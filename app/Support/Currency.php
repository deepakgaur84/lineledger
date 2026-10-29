<?php

namespace App\Support;

use App\Enums\Country;

/**
 * ISO 4217 currency metadata and exact integer conversion helpers.
 *
 * Mirrors the role {@see Country} plays for jurisdictions: a small,
 * curated table the app reads for symbols, names, and decimal places. Kept as a
 * support map (not an enum) because the set of currencies a company can transact
 * in is open-ended and the per-company enablement lives in `company_currencies`.
 *
 * The app is gated to two-decimal currencies because {@see Money} assumes two
 * decimals; zero-/three-decimal currencies (JPY, BHD) are listed for display but
 * excluded from {@see selectable} until Money grows variable precision. The gate
 * covers the home currency as well as foreign ones: a JPY home currency would
 * book fractional yen (a ¥1 invoice at 10% tax posts ¥0.10 tax).
 */
final class Currency
{
    /**
     * code => [name, symbol, decimals]. The common set; extend as needed.
     *
     * @var array<string, array{name: string, symbol: string, decimals: int}>
     */
    private const CURRENCIES = [
        'CAD' => ['name' => 'Canadian Dollar', 'symbol' => '$', 'decimals' => 2],
        'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2],
        'EUR' => ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2],
        'GBP' => ['name' => 'British Pound', 'symbol' => '£', 'decimals' => 2],
        'AUD' => ['name' => 'Australian Dollar', 'symbol' => '$', 'decimals' => 2],
        'NZD' => ['name' => 'New Zealand Dollar', 'symbol' => '$', 'decimals' => 2],
        'CHF' => ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2],
        'JPY' => ['name' => 'Japanese Yen', 'symbol' => '¥', 'decimals' => 0],
        'MXN' => ['name' => 'Mexican Peso', 'symbol' => '$', 'decimals' => 2],
        'INR' => ['name' => 'Indian Rupee', 'symbol' => '₹', 'decimals' => 2],
        'SGD' => ['name' => 'Singapore Dollar', 'symbol' => '$', 'decimals' => 2],
        'HKD' => ['name' => 'Hong Kong Dollar', 'symbol' => '$', 'decimals' => 2],
        'CNY' => ['name' => 'Chinese Yuan', 'symbol' => '¥', 'decimals' => 2],
        'ZAR' => ['name' => 'South African Rand', 'symbol' => 'R', 'decimals' => 2],
        'BRL' => ['name' => 'Brazilian Real', 'symbol' => 'R$', 'decimals' => 2],
        'SEK' => ['name' => 'Swedish Krona', 'symbol' => 'kr', 'decimals' => 2],
        'NOK' => ['name' => 'Norwegian Krone', 'symbol' => 'kr', 'decimals' => 2],
        'DKK' => ['name' => 'Danish Krone', 'symbol' => 'kr', 'decimals' => 2],
    ];

    public static function isKnown(string $code): bool
    {
        return isset(self::CURRENCIES[mb_strtoupper($code)]);
    }

    public static function name(string $code): string
    {
        return self::CURRENCIES[mb_strtoupper($code)]['name'] ?? mb_strtoupper($code);
    }

    public static function symbol(string $code): string
    {
        return self::CURRENCIES[mb_strtoupper($code)]['symbol'] ?? mb_strtoupper($code);
    }

    /**
     * Every distinct symbol in the common set, longest first (so "R$" is tried
     * before "R" and "$").
     *
     * @return list<string>
     */
    public static function symbols(): array
    {
        $symbols = array_values(array_unique(array_column(self::CURRENCIES, 'symbol')));
        usort($symbols, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $symbols;
    }

    public static function decimals(string $code): int
    {
        return self::CURRENCIES[mb_strtoupper($code)]['decimals'] ?? 2;
    }

    /**
     * Whether a company may use this currency, as its home currency or a foreign
     * one: known and two-decimal (the precision {@see Money} supports). Unknown
     * codes are refused because their decimal places aren't known.
     */
    public static function isSelectable(string $code): bool
    {
        return (self::CURRENCIES[mb_strtoupper($code)]['decimals'] ?? null) === 2;
    }

    /**
     * Currencies a company may use today ({@see isSelectable}). Returns
     * code => "USD — US Dollar" for pickers.
     *
     * @return array<string, string>
     */
    public static function selectable(): array
    {
        $options = [];

        foreach (self::CURRENCIES as $code => $meta) {
            if (self::isSelectable($code)) {
                $options[$code] = $code.' — '.$meta['name'];
            }
        }

        return $options;
    }

    /**
     * Convert an integer foreign amount (in foreign minor units) to home minor
     * units at the given rate (home units per 1 foreign unit), rounded half-up.
     *
     * Uses bcmath so there is no float drift: the foreign and home cents are both
     * exact integers and the only inexact step (the rate multiply) is rounded
     * deterministically. Assumes both currencies share the same minor-unit scale
     * (two decimals), consistent with {@see selectable}.
     */
    public static function toHomeCents(int $foreignCents, string $rate): int
    {
        return self::roundHalfUp(bcmul((string) $foreignCents, $rate, 8));
    }

    /**
     * The inverse of {@see toHomeCents()}: an integer home amount (in home minor
     * units) expressed in foreign minor units at the given rate (home units per
     * 1 foreign unit), rounded half-up. Used where only the home side of a
     * foreign-account line is known and its foreign amount has to be worked out.
     */
    public static function toForeignCents(int $homeCents, string $rate): int
    {
        return self::roundHalfUp(bcdiv((string) $homeCents, $rate, 8));
    }

    /**
     * The rate (home units per 1 foreign unit) that a home amount and a foreign
     * amount imply, to eight decimal places — the precision rates are stored
     * at. Null when the foreign amount is zero or the two have opposite signs.
     */
    public static function impliedRate(int $homeCents, int $foreignCents): ?string
    {
        if ($foreignCents === 0 || ($homeCents < 0) !== ($foreignCents < 0)) {
            return null;
        }

        $quotient = bcdiv((string) abs($homeCents), (string) abs($foreignCents), 9);

        return self::normalizeRate(bcadd($quotient, '0.000000005', 8));
    }

    /**
     * A rate written the way the app stores and shows it: a plain positive
     * decimal with at most eight decimal places and no trailing zeros ("1.35",
     * not "1.35000000"). Null for anything else — empty, zero, negative, an
     * exponent, more than eight decimals or more than ten whole digits (the
     * `decimal(18, 8)` columns rates live in).
     */
    public static function normalizeRate(mixed $rate): ?string
    {
        if (! is_string($rate) && ! is_int($rate) && ! is_float($rate)) {
            return null;
        }

        $rate = ltrim(trim((string) $rate), '+');

        if (preg_match('/^(\d{0,10})(?:\.(\d{0,8}))?$/', $rate, $parts) !== 1) {
            return null;
        }

        $whole = ltrim($parts[1], '0');
        $fraction = rtrim($parts[2] ?? '', '0');
        $normalized = ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);

        return bccomp($normalized, '0', 8) === 1 ? $normalized : null;
    }

    /**
     * Round a bcmath decimal string to the nearest integer, halves away from zero.
     */
    private static function roundHalfUp(string $value): int
    {
        return (int) bcadd($value, str_starts_with($value, '-') ? '-0.5' : '0.5', 0);
    }

    /**
     * The four foreign memo columns for a journal line, or an empty array for a
     * home-currency line (so callers can spread it unconditionally into a line's
     * attributes). $currency null means the line is in the home currency.
     *
     * @return array{currency_code?: string, fx_rate?: string, foreign_debit_cents?: int, foreign_credit_cents?: int}
     */
    public static function lineMemo(?string $currency, ?string $rate, int $foreignDebitCents, int $foreignCreditCents): array
    {
        if ($currency === null) {
            return [];
        }

        return [
            'currency_code' => mb_strtoupper($currency),
            'fx_rate' => $rate,
            'foreign_debit_cents' => $foreignDebitCents,
            'foreign_credit_cents' => $foreignCreditCents,
        ];
    }
}
