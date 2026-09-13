<?php

namespace App\Services\BulkImport\Importers;

class CustomerImporter extends AbstractContactImporter
{
    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Customers';
    }

    protected function role(): string
    {
        return 'is_customer';
    }
}
