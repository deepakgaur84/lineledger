<?php

namespace App\Support\Payroll;

/**
 * Social Insurance Number checks shared by entry validation and the slip
 * exports. A SIN is nine digits, the last a check digit: doubling every second
 * digit (summing the digits of each product) and adding the others gives a
 * multiple of 10 — the mod-10 scheme that catches a mistyped digit and most
 * swapped pairs, which the CRA reports as invalid SINs.
 *
 * The CRA's T4 and T4A XML specifications reserve 000000000 for "the employee
 * has failed to provide a SIN": it marks the absence of a SIN and is never a
 * valid one.
 */
final class SocialInsuranceNumber
{
    /** What a T4 or T4A slip carries when the recipient has not given a SIN. */
    public const NOT_PROVIDED = '000000000';

    /** The digits of $value, with spaces, hyphens and other separators dropped. */
    public static function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    /** Whether $value is the CRA's no-SIN value, 000 000 000. */
    public static function isNotProvided(?string $value): bool
    {
        return self::digits($value) === self::NOT_PROVIDED;
    }

    /**
     * Whether $value may be saved as an employee's SIN: blank, a valid SIN, or
     * 000 000 000 for "not given yet" (which saves as no SIN).
     */
    public static function isAcceptableEntry(?string $value): bool
    {
        $entry = trim((string) $value);

        return $entry === '' || self::isValid($entry)
            || (preg_match('/^[\d\s-]+$/', $entry) === 1 && self::isNotProvided($entry));
    }

    /** Whether $value is a SIN: nine digits, optionally spaced or hyphenated, that pass the check digit. */
    public static function isValid(?string $value): bool
    {
        if (preg_match('/^[\d\s-]+$/', trim((string) $value)) !== 1) {
            return false;
        }

        $digits = self::digits($value);

        if (strlen($digits) !== 9 || $digits === self::NOT_PROVIDED) {
            return false;
        }

        $sum = 0;

        foreach (str_split($digits) as $position => $digit) {
            $weighted = (int) $digit;

            if ($position % 2 === 1) {
                $weighted *= 2;
                $weighted = $weighted > 9 ? $weighted - 9 : $weighted;
            }

            $sum += $weighted;
        }

        return $sum % 10 === 0;
    }
}
