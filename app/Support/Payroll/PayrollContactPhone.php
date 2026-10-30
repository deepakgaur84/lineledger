<?php

namespace App\Support\Payroll;

/**
 * The payroll contact's phone number split the way the CRA's XML carries it
 * (the CNTC block of the T619 transmittal and of the T4/T4A summaries): a
 * 3-digit area code, a "###-####" number and an optional extension of up to
 * 5 digits, the summaries' limit.
 */
final readonly class PayrollContactPhone
{
    public function __construct(
        public string $areaCode,
        public string $number,
        public ?string $extension = null,
    ) {}

    /**
     * Reads "613-555-0101", "(613) 555-0101 ext. 22" or "+1 613 555 0101";
     * null for anything that is not a 10-digit North American number.
     */
    public static function parse(?string $value): ?self
    {
        $phone = trim((string) $value);
        $extension = null;

        if (preg_match('/^(.*?)\s*(?:ext\.?|extension|poste|x|#)\s*(\d{1,5})$/i', $phone, $match) === 1) {
            [, $phone, $extension] = $match;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10 || preg_match('/[^\d\s().+-]/', $phone) === 1) {
            return null;
        }

        return new self(substr($digits, 0, 3), substr($digits, 3, 3).'-'.substr($digits, 6), $extension);
    }
}
