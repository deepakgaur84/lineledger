<?php

namespace App\Exceptions\Payroll;

use RuntimeException;

/**
 * Thrown instead of writing an ROE Web payroll extract that ROE Web would refuse:
 * the CRA payroll account (Block 5) or the payroll contact (Block 16) in
 * Settings → Payroll is missing or unusable. The message says what to fix; the
 * ROE page shows it rather than downloading the file.
 */
class RoeExportBlockedException extends RuntimeException
{
    /**
     * @param  array<int, string>  $problems  One translated sentence per problem.
     */
    public static function because(array $problems): self
    {
        return new self(implode(' ', $problems));
    }
}
