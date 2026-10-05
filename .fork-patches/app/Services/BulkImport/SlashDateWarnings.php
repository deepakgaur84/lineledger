<?php

namespace App\Services\BulkImport;

use DateTimeImmutable;
use Throwable;

/**
 * Finds slash dates in an upload's date columns that were probably not meant
 * the way PHP will read them.
 *
 * Every importer hands dates to PHP, which reads 05/09/2026 as month/day/year
 * (9 May) and rejects 27/09/2026 outright (there is no month 27). The rejected
 * ones show up as an invalid row; the dangerous ones are the dates that are
 * *valid* both ways, which pass validation and quietly import as a different
 * day. A day/month/year typist cannot see that from the preview, so this says
 * so, with the date PHP will use, the date they most likely meant, and how to
 * write it so there is no doubt.
 *
 * It never blocks or alters anything — like the bank statement import's date
 * format guesser, it reports the ambiguity for the person to confirm rather
 * than guessing which reading was intended.
 */
final class SlashDateWarnings
{
    /**
     * @param  array<int, array<string, ?string>>  $rows  data rows keyed by column, in file order
     * @param  list<string>  $dateColumns
     * @return list<array{row: int, column: string, value: string, reads_as: ?string, meant: string, suggest: string}>
     */
    public static function find(array $rows, array $dateColumns): array
    {
        $warnings = [];

        foreach ($rows as $index => $row) {
            foreach ($dateColumns as $column) {
                $value = trim((string) ($row[$column] ?? ''));
                $found = self::inspect($value);

                if ($found === null) {
                    continue;
                }

                $warnings[] = [
                    // The spreadsheet's own row number: +1 for the zero-based index, +1 for the header row.
                    'row' => $index + 2,
                    'column' => $column,
                    'value' => $value,
                ] + $found;
            }
        }

        return $warnings;
    }

    /**
     * Null when there is nothing to say: not a day-first-looking slash date, no
     * valid day/month/year reading, or both readings land on the same day.
     *
     * @return array{reads_as: ?string, meant: string, suggest: string}|null
     */
    public static function inspect(string $value): ?array
    {
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})$#', $value, $parts) !== 1) {
            return null;
        }

        $meant = self::dayFirst($value, strlen($parts[3]) === 4);

        // No valid day/month/year reading either (31/31/2026): nothing useful to add.
        if ($meant === null) {
            return null;
        }

        $read = self::asPhpReadsIt($value);

        // The same day either way (05/05/2026): there is no ambiguity to warn about.
        if ($read !== null && $read->format('Y-m-d') === $meant->format('Y-m-d')) {
            return null;
        }

        // A two-digit year pivots at 70 (70–99 mean 19xx, 00–69 mean 20xx), so a
        // suggestion outside 1970–2069 must keep all four digits to stay the same day.
        $year = (int) $meant->format('Y');

        return [
            'reads_as' => $read?->format('j F Y'),
            'meant' => $meant->format('j F Y'),
            'suggest' => $meant->format($year >= 1970 && $year <= 2069 ? 'd-M-y' : 'd-M-Y'),
        ];
    }

    /**
     * What the importers actually do with the text: Laravel's `date` rule
     * (strtotime, then checkdate on date_parse), then Carbon, which is
     * DateTime underneath. Null when they would reject it.
     */
    private static function asPhpReadsIt(string $value): ?DateTimeImmutable
    {
        if (strtotime($value) === false) {
            return null;
        }

        $parsed = date_parse($value);

        if (! checkdate((int) $parsed['month'], (int) $parsed['day'], (int) $parsed['year'])) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** A strict day/month/year reading — an overflowing date such as 30/02 does not count. */
    private static function dayFirst(string $value, bool $fourDigitYear): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat($fourDigitYear ? '!d/m/Y' : '!d/m/y', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
