<?php

namespace App\Services\Restore;

use App\Enums\EncryptedValueState;
use App\Services\Backup\BackupTableRegistry;
use App\Support\Encryption\EncryptedColumns;
use Illuminate\Support\Facades\DB;

/**
 * Repairs the encrypted columns of organizations restored before the restore
 * encrypted them.
 *
 * Until then a restore inserted an encrypted column exactly as the bundle held
 * it, which was the plaintext. In every organization restored that way, each
 * employee SIN sits unencrypted in `employee_payroll_profiles.sin_encrypted`.
 * The cast cannot read it, so the slip exports file the unknown-SIN
 * placeholder, and the value is unencrypted at rest.
 *
 * Safe to run on every deploy, which `app:upgrade` does. It changes only values
 * that are not ciphertext at all. The cast never writes such a value, so a
 * restore wrote it, and it is the plaintext. Each one is encrypted with this
 * instance's key, after which the cast reads back exactly what was stored, so
 * nothing is lost. The update is a compare-and-set on the old value, so a SIN
 * re-entered in the meantime is never overwritten, and a second run finds
 * nothing. Ciphertext is never touched. Values that no key of this instance
 * decrypts (a database restored under another APP_KEY) are counted, but
 * nothing here can recover them: the SIN has to be entered again.
 */
final class EncryptedColumnRepairer
{
    /**
     * Repair one company's encrypted columns, or with `$dryRun` only count what
     * would change. Only columns with something to report are returned.
     *
     * Every registry table with an encrypted column has a `company_id`
     * (EncryptedColumnsTest holds the registry to that).
     *
     * @return array<string, array{encrypted: int, unreadable: int}> Keyed `table.column`.
     */
    public function repair(int $companyId, bool $dryRun): array
    {
        $results = [];

        foreach (BackupTableRegistry::tables() as $entry) {
            $table = $entry['table'];
            $modelClass = $entry['model'];

            foreach (EncryptedColumns::of($modelClass) as $column) {
                $encrypted = 0;
                $unreadable = 0;

                DB::table($table)
                    ->where('company_id', $companyId)
                    ->whereNotNull($column)
                    ->select(['id', $column])
                    ->chunkById(500, function ($rows) use ($table, $modelClass, $column, $dryRun, &$encrypted, &$unreadable): void {
                        foreach ($rows as $row) {
                            $raw = $row->{$column};
                            $value = EncryptedColumns::read($modelClass, $raw);

                            if ($value['state'] === EncryptedValueState::Unreadable) {
                                $unreadable++;
                            }

                            if ($value['state'] !== EncryptedValueState::Unencrypted) {
                                continue;
                            }

                            if ($dryRun) {
                                $encrypted++;

                                continue;
                            }

                            $encrypted += DB::table($table)
                                ->where('id', $row->id)
                                ->where($column, $raw)
                                ->update([$column => EncryptedColumns::encrypt($modelClass, $value['plaintext'])]);
                        }
                    });

                if ($encrypted > 0 || $unreadable > 0) {
                    $results[$table.'.'.$column] = ['encrypted' => $encrypted, 'unreadable' => $unreadable];
                }
            }
        }

        return $results;
    }
}
