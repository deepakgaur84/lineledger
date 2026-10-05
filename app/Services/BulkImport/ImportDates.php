<?php

namespace App\Services\BulkImport;

/**
 * The one place that says how a date is written in a bulk-import CSV, so the
 * help on every importer's date column and the notes on the import screen can
 * never drift apart.
 *
 * The example is deliberately a day, three-letter month and two-digit year
 * (09-Apr-26): it cannot be read two ways, which is exactly what a slash date
 * cannot say for itself. PHP reads 09/04/2026 as month/day/year — 4 September —
 * and every importer parses dates with PHP, so a day/month/year habit (or a
 * spreadsheet that re-saves dates in a d/m/Y locale) silently shifts dates or
 * rejects them outright. A test pins that the example really does parse, to
 * the day it claims to be, through the same rule the importers use.
 */
final class ImportDates
{
    /** 9 April 2026 — unambiguous to a person and to PHP's parser. */
    public const EXAMPLE = '09-Apr-26';

    /** The same day in ISO form, which is also always safe. */
    public const EXAMPLE_ISO = '2026-04-09';

    /** The help every required date column starts with. */
    public static function required(): string
    {
        return 'Required. Write it like '.self::EXAMPLE.' (or '.self::EXAMPLE_ISO.') — not with slashes, which are read as month/day/year.';
    }

    /**
     * The date columns among an importer's columns. They are recognised by name,
     * which every importer already follows (bill_date, acquired_date, …), so a
     * new importer's date columns are covered without anyone registering them.
     *
     * @param  array<int, string>  $columns
     * @return list<string>
     */
    public static function columnsIn(array $columns): array
    {
        return array_values(array_filter($columns, static fn (string $column): bool => str_contains($column, 'date')));
    }
}
