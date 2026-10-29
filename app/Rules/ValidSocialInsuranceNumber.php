<?php

namespace App\Rules;

use App\Support\Payroll\SocialInsuranceNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An employee SIN as entered: nine digits (spaces and hyphens allowed) that
 * pass the SIN check digit, or 000 000 000 — the CRA's value for "the employee
 * has not given a SIN", which saves as no SIN. An empty string passes (pair
 * with `nullable`/`required`).
 */
class ValidSocialInsuranceNumber implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (SocialInsuranceNumber::isAcceptableEntry(is_scalar($value) ? (string) $value : null)) {
            return;
        }

        $fail(__('Enter a valid 9-digit Social Insurance Number, or 000 000 000 if the employee hasn’t given you one yet.'));
    }
}
