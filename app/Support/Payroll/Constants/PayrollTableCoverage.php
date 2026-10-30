<?php

namespace App\Support\Payroll\Constants;

/**
 * Decides which effective-dated payroll table covers a pay date: the one rule
 * {@see FederalConstants} and {@see ProvincialConstants} both select by.
 *
 * A table covers pay dates from its effective date until the next loaded table
 * takes over, and never past December 31 of its own year. CRA (T4127) and
 * Revenu Québec (TP-1015.G) publish new tables every January 1 (new CPP/QPP and
 * EI ceilings, indexed brackets and basic amounts), so a January pay date needs
 * that year's January table. A
 * date no table covers (before the first table, or on or after January 1 of a
 * year whose tables are not loaded) resolves to null, and the engine refuses to
 * calculate instead of reusing a superseded table.
 *
 * A mid-year (July 1) edition has to be appended before its effective date:
 * until then the January table keeps covering the rest of its year.
 */
final class PayrollTableCoverage
{
    /**
     * The table in force on the pay date, or null when no loaded table covers it.
     *
     * @param  array<string, array<string, mixed>>  $tables  keyed by effective date (Y-m-d)
     * @return array<string, mixed>|null
     */
    public static function select(array $tables, string $payDate): ?array
    {
        $effective = null;

        foreach (array_keys($tables) as $date) {
            if ($date <= $payDate && ($effective === null || $date > $effective)) {
                $effective = $date;
            }
        }

        // The latest table on or before the pay date only reaches the end of its year.
        if ($effective === null || self::year($effective) !== self::year($payDate)) {
            return null;
        }

        return $tables[$effective];
    }

    /**
     * The last pay date the tables cover (December 31 of the latest table's
     * year), or null when no table is loaded.
     *
     * @param  array<string, array<string, mixed>>  $tables  keyed by effective date (Y-m-d)
     */
    public static function coveredThrough(array $tables): ?string
    {
        if ($tables === []) {
            return null;
        }

        return self::year(max(array_keys($tables))).'-12-31';
    }

    private static function year(string $date): string
    {
        return substr($date, 0, 4);
    }
}
