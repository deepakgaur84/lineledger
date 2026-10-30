<?php

namespace App\Support\Payroll;

/**
 * The CRA payroll program account: the 9-digit Business Number, the RP program
 * identifier and a 4-digit reference number, written as one 15-character
 * "BN15" (123456789RP0001). T4 and T4A slips and summaries are filed under it,
 * and the CRA's specifications reject any other program account in its place
 * (an RT GST/HST account included).
 */
final class PayrollAccountNumber
{
    public const PATTERN = '/^\d{9}RP\d{4}$/';

    /** Upper case, with spaces and hyphens removed. */
    public static function normalize(?string $value): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', (string) $value) ?? '');
    }

    /** Whether $value is a 9-digit Business Number. */
    public static function isBusinessNumber(?string $value): bool
    {
        return preg_match('/^\d{9}$/', self::normalize($value)) === 1;
    }

    /** "RP0001" from "RP0001", "rp 0001" or "0001"; null when it is no RP program account. */
    public static function programAccount(?string $value): ?string
    {
        $normalized = self::normalize($value);

        return match (true) {
            preg_match('/^RP\d{4}$/', $normalized) === 1 => $normalized,
            preg_match('/^\d{4}$/', $normalized) === 1 => 'RP'.$normalized,
            default => null,
        };
    }

    /**
     * The BN15 from the Business Number and RP account settings, or null when
     * they do not make one. Either field may hold the whole account; when both
     * do, they must agree.
     */
    public static function compose(?string $businessNumber, ?string $rpAccount): ?string
    {
        $bn = self::normalize($businessNumber);
        $rp = self::normalize($rpAccount);

        if (preg_match(self::PATTERN, $bn) === 1) {
            return $rp === '' || $rp === $bn || self::programAccount($rp) === substr($bn, 9) ? $bn : null;
        }

        if (! self::isBusinessNumber($bn)) {
            return null;
        }

        if (preg_match(self::PATTERN, $rp) === 1) {
            return str_starts_with($rp, $bn) ? $rp : null;
        }

        $program = self::programAccount($rp);

        return $program !== null ? $bn.$program : null;
    }
}
