<?php

namespace App\Services\Backup;

use App\Enums\EncryptedValueState;
use App\Support\Encryption\EncryptedColumns;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Streams every row of a single Eloquent model to a JSON-Lines file
 * inside the backup working directory.
 *
 * One JSON object per line, cast-aware (uses `$model->toArray()`), with
 * incremental sha256 + byte accounting so the orchestrator can publish
 * per-table integrity in the manifest.
 *
 * Columns with an `encrypted` cast are written as their plaintext
 * ({@see self::ENCRYPTED_COLUMN_ENCODING}), and the restore encrypts them again
 * with the target instance's key. A bundle therefore never needs the APP_KEY of
 * the instance that made it, and the restored column holds ciphertext the
 * target's cast can read.
 *
 * The writer is **stateless** — every call accepts the company id
 * explicitly and applies an explicit `where('company_id', $companyId)`
 * filter (or `where('id', $companyId)` for the `companies` table) under
 * `withoutGlobalScopes()`. That sidesteps the `BelongsToCompany` global
 * scope so the orchestrator does not need to bind `current_company`,
 * keeping the writer safe to call from queues and unit tests.
 *
 * Child tables that have no direct `company_id` column (e.g.
 * `journal_lines`, `receipt_applications`, `bill_payment_applications`)
 * must be filtered via the caller-supplied `$scope` closure, which is
 * applied to the query builder in place of the default company filter.
 */
final class JsonlTableWriter
{
    /**
     * How a bundle carries a column with an `encrypted` cast, recorded per column
     * in the manifest (`tables.{table}.encrypted_columns.{column}.encoding`).
     *
     * `plaintext` is what bundles have always held, since `toArray()` decrypts
     * through the cast; the manifest now says so. The restore refuses any other
     * encoding, so a later format (a bundle sealed with its own key) is never
     * restored by this version as if its ciphertext were the value.
     */
    public const ENCRYPTED_COLUMN_ENCODING = 'plaintext';

    /**
     * Stream every row of `$modelClass` for `$companyId` to
     * `{workDir}/data/{tableName}.jsonl`.
     *
     * For a model with encrypted columns the result also has `encrypted_columns`:
     * per column, its encoding and how many values could not be decrypted (and
     * were written as null). The orchestrator puts the result in the manifest.
     *
     * @param  class-string<Model>  $modelClass
     * @param  int  $companyId  Tenant id used for the explicit company filter.
     * @param  string  $workDir  Absolute path to the backup working dir.
     * @param  string  $tableName  Physical table name (used for filename + schema introspection).
     * @param  Closure|null  $rowTransform  Optional `fn(array $row, Model $model): array` — applied
     *                                      after `$model->toArray()`; the returned array is written.
     *                                      Used by the orchestrator to strip Stripe columns from
     *                                      `companies` and rewrite `attachments.path`.
     * @param  Closure|null  $scope  Optional `fn(Builder $q): void` — applied to the query in place
     *                               of the default company filter. Required when `$tableName` has
     *                               no `company_id` column.
     * @return array{rows: int, sha256: string, bytes: int, encrypted_columns?: array<string, array{encoding: string, unreadable: int}>}
     */
    public function write(
        string $modelClass,
        int $companyId,
        string $workDir,
        string $tableName,
        ?Closure $rowTransform = null,
        ?Closure $scope = null,
    ): array {
        $dataDir = $workDir.'/data';

        if (! is_dir($dataDir) && ! @mkdir($dataDir, 0755, true) && ! is_dir($dataDir)) {
            throw new RuntimeException("Unable to create backup data directory: {$dataDir}");
        }

        $filePath = $dataDir.'/'.$tableName.'.jsonl';
        $handle = fopen($filePath, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open JSONL file for write: {$filePath}");
        }

        $hashCtx = hash_init('sha256');
        $rowCount = 0;
        $byteCount = 0;

        $encryptedColumns = $this->exportedEncryptedColumns($modelClass);
        $unreadable = array_fill_keys($encryptedColumns, 0);

        try {
            /** @var Builder $query */
            $query = $modelClass::query()->withoutGlobalScopes();

            if ($scope !== null) {
                $scope($query);
            } elseif ($tableName === 'companies') {
                $query->where('id', $companyId);
            } elseif (Schema::hasColumn($tableName, 'company_id')) {
                $query->where('company_id', $companyId);
            } else {
                throw new RuntimeException(
                    "Table [{$tableName}] has no company_id column and no \$scope closure was provided."
                );
            }

            $query->chunkById(5000, function ($chunk) use (
                $handle,
                &$hashCtx,
                &$rowCount,
                &$byteCount,
                &$unreadable,
                $rowTransform,
                $encryptedColumns,
            ): void {
                foreach ($chunk as $model) {
                    /** @var Model $model */
                    $row = $this->rowFor($model, $encryptedColumns, $unreadable);

                    if ($rowTransform !== null) {
                        $row = $rowTransform($row, $model);
                    }

                    $line = json_encode(
                        $row,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );

                    if ($line === false) {
                        throw new RuntimeException(
                            'json_encode failed for row in '.$model::class.': '.json_last_error_msg()
                        );
                    }

                    $line .= "\n";

                    hash_update($hashCtx, $line);
                    fwrite($handle, $line);

                    $rowCount++;
                    $byteCount += strlen($line);
                }
            });
        } finally {
            fclose($handle);
        }

        $result = [
            'rows' => $rowCount,
            'sha256' => hash_final($hashCtx),
            'bytes' => $byteCount,
        ];

        if ($encryptedColumns !== []) {
            $result['encrypted_columns'] = array_map(
                static fn (int $count): array => ['encoding' => self::ENCRYPTED_COLUMN_ENCODING, 'unreadable' => $count],
                $unreadable,
            );
        }

        return $result;
    }

    /**
     * The row as `toArray()` serialises it, except for the encrypted columns.
     * `toArray()` would decrypt those through the cast too, but the cast throws on
     * a value that no longer decrypts, and one such SIN would fail the whole
     * backup. So they are hidden from `toArray()` and read from the raw attribute
     * instead: ciphertext is decrypted, a value that does not decrypt is written
     * as null and counted, and a value an older restore stored unencrypted is
     * written as it is (it is already the plaintext).
     *
     * @param  list<string>  $encryptedColumns
     * @param  array<string, int>  $unreadable  Per-column count, incremented in place.
     * @return array<string, mixed>
     */
    private function rowFor(Model $model, array $encryptedColumns, array &$unreadable): array
    {
        if ($encryptedColumns === []) {
            return $model->toArray();
        }

        $raw = $model->getAttributes();
        $row = $model->makeHidden($encryptedColumns)->toArray();

        foreach ($encryptedColumns as $column) {
            if (! array_key_exists($column, $raw)) {
                continue;
            }

            $value = EncryptedColumns::read($model::class, $raw[$column]);

            if ($value['state'] === EncryptedValueState::Unreadable) {
                $unreadable[$column]++;
            }

            $row[$column] = $value['plaintext'];
        }

        return $row;
    }

    /**
     * The model's encrypted columns that `toArray()` would include. One the model
     * hides from serialisation stays out of the bundle, as it always has.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<string>
     */
    private function exportedEncryptedColumns(string $modelClass): array
    {
        $model = new $modelClass;
        $hidden = $model->getHidden();
        $visible = $model->getVisible();

        return array_values(array_filter(
            EncryptedColumns::of($modelClass),
            static fn (string $column): bool => ! in_array($column, $hidden, true)
                && ($visible === [] || in_array($column, $visible, true)),
        ));
    }
}
