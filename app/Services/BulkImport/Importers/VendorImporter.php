<?php

namespace App\Services\BulkImport\Importers;

class VendorImporter extends AbstractContactImporter
{
    public function key(): string
    {
        return 'vendors';
    }

    public function label(): string
    {
        return 'Vendors';
    }

    protected function role(): string
    {
        return 'is_vendor';
    }
}
