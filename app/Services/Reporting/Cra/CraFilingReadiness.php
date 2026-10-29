<?php

namespace App\Services\Reporting\Cra;

/**
 * Whether a CRA information-return file is ready to upload. Errors are what
 * the CRA would reject (or file under the wrong account): the download is
 * refused until there are none. Warnings are what the CRA accepts but flags
 * — a missing SIN, say — and are shown beside the download.
 */
final readonly class CraFilingReadiness
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function isReady(): bool
    {
        return $this->errors === [];
    }
}
