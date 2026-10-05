<?php

use App\Services\BulkImport\ImportDates;
use App\Services\BulkImport\SlashDateWarnings;

// Pure logic, no application or database: PHP's own date parser is the thing under test.

it('warns when a slash date is valid both ways but means a different day', function (string $written, string $readAs, string $meant, string $suggest) {
    expect(SlashDateWarnings::inspect($written))->toBe([
        'reads_as' => $readAs,
        'meant' => $meant,
        'suggest' => $suggest,
    ]);
})->with([
    'day 5, month 9' => ['05/09/2026', '9 May 2026', '5 September 2026', '05-Sep-26'],
    'the documented example day' => ['09/04/2026', '4 September 2026', '9 April 2026', '09-Apr-26'],
    'no leading zeros' => ['5/9/2026', '9 May 2026', '5 September 2026', '05-Sep-26'],
    'two-digit year' => ['05/09/26', '9 May 2026', '5 September 2026', '05-Sep-26'],
]);

it('explains a slash date PHP refuses, because there is no month 27', function () {
    expect(SlashDateWarnings::inspect('27/09/2026'))->toBe([
        'reads_as' => null,
        'meant' => '27 September 2026',
        'suggest' => '27-Sep-26',
    ]);
});

it('keeps four digits in the suggestion when a two-digit year would be a different year', function () {
    // A two-digit year pivots at 70: 69 means 2069 and 70 means 1970.
    expect(SlashDateWarnings::inspect('15/06/1969')['suggest'])->toBe('15-Jun-1969')
        ->and(SlashDateWarnings::inspect('15/06/2070')['suggest'])->toBe('15-Jun-2070')
        ->and(SlashDateWarnings::inspect('30/09/1999')['suggest'])->toBe('30-Sep-99');
});

it('says nothing when there is nothing to warn about', function (string $written) {
    expect(SlashDateWarnings::inspect($written))->toBeNull();
})->with([
    'same day either way' => ['05/05/2026'],
    'same day either way again' => ['12/12/2026'],
    'month-first only, which PHP reads as written' => ['09/27/2026'],
    'not a date either way' => ['31/31/2026'],
    'an overflowing date' => ['30/02/2026'],
    'ISO' => ['2026-04-09'],
    'the documented example' => ['09-Apr-26'],
    'month as a word' => ['9 Apr 2026'],
    'numeric with dashes, which PHP reads day-first' => ['09-04-2026'],
    'numeric with dots, which PHP reads day-first' => ['09.04.2026'],
    'year-first with slashes' => ['2026/04/09'],
    'blank' => [''],
    'text' => ['abc'],
    'two parts' => ['1/2'],
]);

it('finds warnings only in the date columns, with the spreadsheet row number', function () {
    $rows = [
        ['name' => 'A', 'acquired_date' => '05/09/2026', 'description' => '05/09/2026'],
        ['name' => 'B', 'acquired_date' => ' 27/09/2026 ', 'description' => ''],
        ['name' => 'C', 'acquired_date' => '09-Apr-26', 'description' => ''],
    ];

    $found = SlashDateWarnings::find($rows, ['acquired_date', 'in_service_date']);

    // The clean row and the slash-looking text in a non-date column are both skipped.
    expect($found)->toHaveCount(2)
        // Row 1 of the sheet is the header, so the first data row is row 2.
        ->and([$found[0]['row'], $found[0]['column'], $found[0]['value']])->toBe([2, 'acquired_date', '05/09/2026'])
        ->and([$found[1]['row'], $found[1]['value'], $found[1]['reads_as']])->toBe([3, '27/09/2026', null])
        ->and(SlashDateWarnings::find([], ['acquired_date']))->toBe([]);
});

it('only ever suggests a rewrite that parses back to the very day that was meant', function () {
    // The suggestion is what people are told to type, so check all of it: every
    // day-first date over 150 years, on both sides of the two-digit-year pivot.
    // Problems are collected and asserted once — a failure then names the dates.
    $warned = 0;
    $problems = [];

    foreach (range(1950, 2100) as $year) {
        foreach (range(1, 12) as $month) {
            foreach (range(1, 31) as $day) {
                if (! checkdate($month, $day, $year)) {
                    continue;
                }

                $written = sprintf('%02d/%02d/%04d', $day, $month, $year);
                $result = SlashDateWarnings::inspect($written);

                if ($result === null) {
                    continue;
                }

                $warned++;
                $meant = sprintf('%04d-%02d-%02d', $year, $month, $day);

                if ((new DateTimeImmutable($result['suggest']))->format('Y-m-d') !== $meant) {
                    $problems[] = "{$written}: suggestion {$result['suggest']} is a different day";
                }

                if ((new DateTimeImmutable($result['meant']))->format('Y-m-d') !== $meant) {
                    $problems[] = "{$written}: 'meant' is a different day";
                }

                // Never warn when PHP's own reading is the day that was meant.
                if ($result['reads_as'] !== null && (new DateTimeImmutable($result['reads_as']))->format('Y-m-d') === $meant) {
                    $problems[] = "{$written}: warned although PHP reads it correctly";
                }
            }
        }
    }

    // The count is only here so the loop cannot pass by checking nothing.
    expect($problems)->toBe([])
        ->and($warned)->toBeGreaterThan(50000);
});

it('recognises date columns by name', function () {
    expect(ImportDates::columnsIn(['name', 'bill_date', 'due_date', 'description', 'acquired_date', 'in_service_date', 'cost']))
        ->toBe(['bill_date', 'due_date', 'acquired_date', 'in_service_date'])
        ->and(ImportDates::columnsIn(['name', 'email']))->toBe([]);
});
