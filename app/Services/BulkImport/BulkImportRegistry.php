<?php

namespace App\Services\BulkImport;

use App\Services\BulkImport\Importers\CustomerImporter;
use App\Services\BulkImport\Importers\ItemCategoryImporter;
use App\Services\BulkImport\Importers\ItemImporter;
use App\Services\BulkImport\Importers\VendorImporter;

/**
 * The list of entity types available in the bulk-import tool. Add a new
 * ImporterDefinition implementation here to add a new import type — the
 * page itself needs no changes.
 */
class BulkImportRegistry
{
    /** @return array<int, ImporterDefinition> */
    public static function all(): array
    {
        return [
            app(VendorImporter::class),
            app(CustomerImporter::class),
            app(ItemCategoryImporter::class),
            app(ItemImporter::class),
        ];
    }

    public static function find(string $key): ?ImporterDefinition
    {
        foreach (self::all() as $importer) {
            if ($importer->key() === $key) {
                return $importer;
            }
        }

        return null;
    }
}
