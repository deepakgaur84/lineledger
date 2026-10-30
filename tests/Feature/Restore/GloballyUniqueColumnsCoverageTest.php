<?php

use App\Services\Backup\BackupTableRegistry;
use App\Services\Restore\IdMapper;
use App\Services\Restore\RowTransformer;
use Illuminate\Support\Facades\Schema;

/**
 * Arch test: every column UNIQUE across the whole instance on a table
 * {@see BackupTableRegistry::tables()} exports must be listed in
 * {@see RowTransformer::GLOBALLY_UNIQUE_COLUMNS}, so the copy gets a fresh
 * value, null, or a value nobody holds. Copied verbatim instead, the value
 * fails a restore next to the organization it came from on the constraint:
 * company_api_keys.token_hash in #158, then invitation codes, the
 * inbound-email token, Stripe PaymentIntent ids and MCP confirm tokens.
 *
 * A unique index is already per copy when it includes `company_id` (swapped to
 * the new company) or a parent FK the transformer remaps onto the copy's own
 * rows. User columns don't count: a restore on the same instance matches the
 * same users, so a (user_id, …) index would still collide.
 *
 * RestoreUniqueColumnsTest restores one bundle twice next to its source with
 * every listed column filled in, and checks how each comes back.
 */
beforeEach(function () {
    $this->registryTables = array_column(BackupTableRegistry::tables(), 'table');

    // Every unique index on an exported table that is not already per copy.
    $this->globalUniqueIndexes = function (): array {
        $found = [];

        foreach ($this->registryTables as $table) {
            $perCopy = ['company_id'];

            foreach ([RowTransformer::PARENT_FK_MAP[$table] ?? [], RowTransformer::DEFERRED_FK_COLUMNS[$table] ?? []] as $map) {
                foreach ($map as $column => $parentTable) {
                    if (in_array($parentTable, $this->registryTables, true)) {
                        $perCopy[] = $column;
                    }
                }
            }

            foreach (Schema::getIndexes($table) as $index) {
                if ($index['primary'] || ! $index['unique'] || array_intersect($index['columns'], $perCopy) !== []) {
                    continue;
                }

                $found[] = ['table' => $table, 'name' => $index['name'], 'columns' => $index['columns']];
            }
        }

        return $found;
    };
});

it('lists every globally unique column on every exported table', function () {
    $indexes = ($this->globalUniqueIndexes)();
    $violations = [];

    foreach ($indexes as $index) {
        $listed = array_keys(RowTransformer::GLOBALLY_UNIQUE_COLUMNS[$index['table']] ?? []);

        if (array_intersect($index['columns'], $listed) === []) {
            $violations[] = sprintf(
                '%s.%s (index %s): a restore next to the source would copy it verbatim and fail on the constraint.',
                $index['table'],
                implode('+', $index['columns']),
                $index['name'],
            );
        }
    }

    expect($violations)->toBe([], "Globally unique columns a restore would copy verbatim:\n  - ".implode("\n  - ", $violations)
        ."\nList each in RowTransformer::GLOBALLY_UNIQUE_COLUMNS, handle it in applyTableQuirks(), and give it a value in RestoreUniqueColumnsTest's fixture.");

    // The scan must see the constraint #158 was about, or it is not reading indexes.
    expect(collect($indexes)->contains(fn (array $index) => $index['table'] === 'company_api_keys' && $index['columns'] === ['token_hash']))
        ->toBeTrue('Schema::getIndexes() did not report company_api_keys.token_hash; the scan is not running.');
});

it('keeps the list of globally unique columns honest', function () {
    $indexed = collect(($this->globalUniqueIndexes)())
        ->flatMap(fn (array $index) => array_map(fn (string $column) => "{$index['table']}.{$column}", $index['columns']))
        ->all();

    foreach (RowTransformer::GLOBALLY_UNIQUE_COLUMNS as $table => $columns) {
        foreach ($columns as $column => $handling) {
            expect($this->registryTables)->toContain($table)
                ->and($handling)->toBeIn(['fresh', 'drop', 'deactivate'])
                ->and($indexed)->toContain("{$table}.{$column}");
        }
    }
});

it('never passes a listed value from the bundle on to the copy', function () {
    $transformer = new RowTransformer(new IdMapper, [], importingUserId: 1, newCompanyId: 2);

    foreach (RowTransformer::GLOBALLY_UNIQUE_COLUMNS as $table => $columns) {
        foreach ($columns as $column => $handling) {
            $ref = "{$table}.{$column}";
            $row = ['id' => 10, 'company_id' => 3, $column => "bundle-value-of-{$column}"];

            $first = $transformer->transform($table, $row)['row'][$column] ?? null;
            $second = $transformer->transform($table, $row)['row'][$column] ?? null;

            if ($handling === 'drop') {
                expect($first)->toBeNull("{$ref} is `drop`, but the copy keeps a value.");

                continue;
            }

            // `fresh` may be left for CompanyImporter to mint (companies.slug);
            // a value the transformer does set must be new on every restore.
            if ($handling === 'deactivate' || $first !== null) {
                expect($first)->not->toBeNull("{$ref} is `{$handling}`, but the copy gets no value.")
                    ->and($first)->not->toBe($row[$column], "{$ref} is copied verbatim.")
                    ->and($first)->not->toBe($second, "{$ref} gets the same value on every restore, so the second copy collides with the first.");
            }
        }
    }
});
