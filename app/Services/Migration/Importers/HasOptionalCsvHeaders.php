<?php

namespace App\Services\Migration\Importers;

/**
 * A {@see CompanyCsvImporter} that understands columns beyond the required
 * ones. They are absent from `templateHeaders()` — adding them there would make
 * an older CSV that omits them fail validation — but the downloaded template
 * still lists them so the operator can discover them.
 */
interface HasOptionalCsvHeaders
{
    /**
     * @return list<string>
     */
    public function optionalHeaders(): array;
}
