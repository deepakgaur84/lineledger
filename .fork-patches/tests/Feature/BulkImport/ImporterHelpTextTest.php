<?php

use App\Services\BulkImport\BulkImportRegistry;
use App\Services\BulkImport\HasImportNotes;
use App\Services\BulkImport\ImportDates;
use App\Services\BulkImport\Importers\FixedAssetImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

it('tells every importer\'s required date column how to write a date, with the example', function () {
    $checked = 0;
    $offenders = [];

    foreach (BulkImportRegistry::all() as $importer) {
        $columns = $importer->csvColumns();

        foreach (ImportDates::columnsIn(array_keys($columns)) as $column) {
            if (! str_starts_with($columns[$column], 'Required')) {
                continue;
            }

            $checked++;

            if (! str_contains($columns[$column], ImportDates::EXAMPLE) || ! str_contains($columns[$column], 'slashes')) {
                $offenders[] = $importer->key().'.'.$column;
            }
        }
    }

    // One required date per importer that has any: bills, invoices, credits, payments,
    // receipts, journal entries, fixed assets. The count stops this passing by checking nothing.
    expect($offenders)->toBe([])
        ->and($checked)->toBeGreaterThanOrEqual(8);
});

it('no longer tells anyone that any unambiguous date works', function () {
    // That sentence is what people trusted when they typed 27/09/2026 — it is only true
    // for dates that cannot be read two ways, and a slash date can.
    $offenders = [];

    foreach (BulkImportRegistry::all() as $importer) {
        foreach ($importer->csvColumns() as $column => $help) {
            if (str_contains(mb_strtolower($help), 'unambiguous')) {
                $offenders[] = $importer->key().'.'.$column;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('uses an example that the importers\' own date rule accepts, and that means the day it claims', function () {
    // The help is only worth anything if the example works. 09-Apr-26 and 2026-04-09
    // must both pass the rule every importer uses, and both be 9 April 2026.
    foreach ([ImportDates::EXAMPLE, ImportDates::EXAMPLE_ISO] as $example) {
        expect(Validator::make(['date' => $example], ['date' => ['required', 'date']])->passes())->toBeTrue()
            ->and(CarbonImmutable::parse($example)->toDateString())->toBe('2026-04-09');
    }
});

it('gives the fixed assets importer notes on the rules that actually get its rows rejected', function () {
    $importer = app(FixedAssetImporter::class);

    expect($importer)->toBeInstanceOf(HasImportNotes::class);

    $notes = implode("\n", $importer->importNotes());

    // Category must exist (and is not an account), codes not names, a rate is refused on 100%,
    // and auto_depreciate drags in the depreciation accounts.
    expect($importer->importNotes())->toHaveCount(4)
        ->and($notes)->toContain('category_name')
        ->and($notes)->toContain('Asset categories')
        ->and($notes)->toContain('account codes')
        ->and($notes)->toContain('100% (immediate)')
        ->and($notes)->toContain('a rate on that row is rejected')
        ->and($notes)->toContain('auto_depreciate');
});

it('gives the in-service date the same format hint as the acquired date', function () {
    $columns = app(FixedAssetImporter::class)->csvColumns();

    expect($columns['in_service_date'])->toContain('Same date format as acquired_date');
});
