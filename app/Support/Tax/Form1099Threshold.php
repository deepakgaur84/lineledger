<?php

namespace App\Support\Tax;

/**
 * The IRS minimum reporting threshold for Form 1099-NEC box 1 (nonemployee
 * compensation), by the calendar year the payments were made in.
 *
 * - Payments made before 2026: $600 (IRC §6041(a) and §6041A(a)).
 * - Payments made in 2026: $2,000. The One Big Beautiful Bill Act (§70433) raised
 *   the §6041(a) threshold for payments made after December 31, 2025, and
 *   §6041A(a)(2) now cross-references it.
 * - Payments made after 2026: $2,000 indexed for inflation (IRC §6041(h)). The
 *   IRS publishes each year's figure; until a year is added below it falls back
 *   to the latest published one, which can only be lower than the indexed figure,
 *   and {@see isPublished()} says so.
 *
 * Source: irs.gov, "Am I required to file a Form 1099 or other information
 * return?": "For payments made before 2026, the reporting threshold is $600. For
 * payments made in 2026, the reporting threshold is $2,000. For payments made
 * after 2026, see Pub. 1099 for the inflation-adjusted reporting threshold."
 *
 * Append-only: when the IRS publishes a later year's inflation-adjusted figure
 * (Pub. 1099, or the annual inflation-adjustment revenue procedure), add one
 * entry keyed by that calendar year. Never edit a past year, so a filed year's
 * summary keeps reproducing the figures it was filed with.
 */
final class Form1099Threshold
{
    /** The first calendar year of the raised threshold. */
    private const RAISED_FROM_YEAR = 2026;

    /** The threshold for payments made before {@see RAISED_FROM_YEAR}, in cents. */
    private const BEFORE_RAISE_CENTS = 60000;

    /**
     * The published threshold, in cents, keyed by the calendar year of payment.
     *
     * @var array<int, int>
     */
    private const PUBLISHED = [
        2026 => 200000,
    ];

    /** The reporting threshold for payments made in $year, in cents. */
    public static function centsFor(int $year): int
    {
        if ($year < self::RAISED_FROM_YEAR) {
            return self::BEFORE_RAISE_CENTS;
        }

        return self::PUBLISHED[$year] ?? self::PUBLISHED[self::latestPublishedYear()];
    }

    /**
     * Whether $year's threshold is the IRS figure for that year, rather than the
     * latest published one standing in for an inflation-adjusted figure not yet
     * added to {@see PUBLISHED}.
     */
    public static function isPublished(int $year): bool
    {
        return $year < self::RAISED_FROM_YEAR || array_key_exists($year, self::PUBLISHED);
    }

    public static function latestPublishedYear(): int
    {
        return max(array_keys(self::PUBLISHED));
    }
}
