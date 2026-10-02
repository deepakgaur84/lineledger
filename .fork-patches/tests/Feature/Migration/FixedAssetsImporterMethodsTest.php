<?php

use App\Enums\DataMigrationStatus;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Models\DataMigrationRun;
use App\Services\Migration\ImportContext;
use App\Services\Migration\Importers\FixedAssetsImporter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->run = DataMigrationRun::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'status' => DataMigrationStatus::InProgress,
        'conversion_date' => CarbonImmutable::create(2026, 7, 31),
        'current_step' => 1,
        'step_results' => [],
        'open_invoices_use_original_date' => true,
        'open_bills_use_original_date' => true,
        'started_at' => now(),
    ]);

    $this->ctx = new ImportContext(
        company: $this->company,
        run: $this->run,
        conversionDate: CarbonImmutable::create(2026, 7, 31),
        useOriginalDates: true,
    );
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Write one asset row to a temp CSV under the given headers and return its path.
 *
 * @param  list<string>  $headers
 * @param  array<string, string>  $values  keyed by header
 */
function openingAssetCsv(array $headers, array $values): string
{
    $path = tempnam(sys_get_temp_dir(), 'openingassets').'.csv';
    $file = fopen($path, 'w');
    fputcsv($file, $headers);
    fputcsv($file, array_map(fn (string $header): string => $values[$header] ?? '', $headers));
    fclose($file);

    return $path;
}

function openingAssetHeaders(): array
{
    return ['asset_no', 'name', 'asset_account_code', 'acquired_date', 'cost', 'useful_life_months', 'category_name', 'depreciation_method', 'depreciation_rate'];
}

function openingAssetValues(array $overrides = []): array
{
    return array_merge([
        'asset_no' => 'FA-1',
        'name' => 'Truck',
        'asset_account_code' => '1500',
        'acquired_date' => '2024-01-15',
        'cost' => '45000.00',
    ], $overrides);
}

it('stores the method and rate, and hands them to a category the import creates', function () {
    $path = openingAssetCsv(openingAssetHeaders(), openingAssetValues([
        'category_name' => 'Vehicles',
        'depreciation_method' => 'WDV',
        'depreciation_rate' => '25',
    ]));

    $result = app(FixedAssetsImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();

    $asset = Asset::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
    $category = AssetCategory::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Vehicles')->firstOrFail();

    expect($asset->depreciationMethod()->value)->toBe('declining_balance')
        ->and((float) $asset->depreciation_rate)->toBe(25.0)
        ->and($category->defaultDepreciationMethod()->value)->toBe('declining_balance')
        ->and((float) $category->default_depreciation_rate)->toBe(25.0);
});

it('still imports a file that has no method or rate columns, as straight-line', function () {
    $headers = ['asset_no', 'name', 'asset_account_code', 'acquired_date', 'cost', 'useful_life_months'];
    $path = openingAssetCsv($headers, openingAssetValues(['useful_life_months' => '60']));

    expect(app(FixedAssetsImporter::class)->commit($path, $this->ctx)->isOk())->toBeTrue();

    $asset = Asset::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();

    expect($asset->depreciationMethod()->value)->toBe('straight_line')
        ->and($asset->useful_life_months)->toBe(60)
        ->and($asset->depreciation_rate)->toBeNull();
});

it('gives declining balance with no rate 20%', function () {
    $path = openingAssetCsv(openingAssetHeaders(), openingAssetValues(['depreciation_method' => 'declining_balance']));

    expect(app(FixedAssetsImporter::class)->commit($path, $this->ctx)->isOk())->toBeTrue();

    expect((float) Asset::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail()->depreciation_rate)->toBe(20.0);
});

it('takes a rate written with a percent sign', function () {
    $path = openingAssetCsv(openingAssetHeaders(), openingAssetValues(['depreciation_method' => 'WDV', 'depreciation_rate' => '12.5%']));

    expect(app(FixedAssetsImporter::class)->commit($path, $this->ctx)->isOk())->toBeTrue();

    expect((float) Asset::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail()->depreciation_rate)->toBe(12.5);
});

it('refuses an unknown method, a rate outside 1 to 100, and a rate on the one method that has none at all, creating nothing', function () {
    $bad = [
        ['depreciation_method' => 'sum of years'],
        ['depreciation_method' => 'WDV', 'depreciation_rate' => '0.5'],
        ['depreciation_method' => 'WDV', 'depreciation_rate' => '0.99'],
        ['depreciation_method' => 'WDV', 'depreciation_rate' => '101'],
        ['depreciation_method' => 'WDV', 'depreciation_rate' => 'abc'],
        // Immediate is the one method a rate never applies to at all — straight_line's
        // own rate is covered separately below, as an ACCEPTED case, not a bad one: it
        // used to be rejected outright before the straight-line rate bug was fixed.
        ['depreciation_method' => '100%', 'depreciation_rate' => '20'],
    ];

    foreach ($bad as $overrides) {
        $result = app(FixedAssetsImporter::class)->commit(openingAssetCsv(openingAssetHeaders(), openingAssetValues($overrides)), $this->ctx);

        expect($result->isOk())->toBeFalse('this row must be refused: '.json_encode($overrides))
            ->and($result->errors[0]['row'])->toBe(2);
    }

    expect(Asset::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(0);
});

it('accepts a rate on a straight-line row — explicitly, or via the method\'s own default', function () {
    // Both of these were wrongly rejected before the straight-line rate bug
    // was fixed: an explicit straight_line method with a rate, and a row
    // with no depreciation_method at all, which defaults to straight_line.
    $explicit = openingAssetCsv(openingAssetHeaders(), openingAssetValues(['depreciation_method' => 'straight_line', 'depreciation_rate' => '20']));
    $defaulted = openingAssetCsv(openingAssetHeaders(), openingAssetValues(['depreciation_rate' => '20']));

    expect(app(FixedAssetsImporter::class)->commit($explicit, $this->ctx)->isOk())->toBeTrue();

    $asset = Asset::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
    expect($asset->depreciationMethod()->value)->toBe('straight_line')
        ->and((float) $asset->depreciation_rate)->toBe(20.0)
        ->and($asset->useful_life_months)->toBe(60);

    Asset::withoutGlobalScopes()->where('company_id', $this->company->id)->delete();

    expect(app(FixedAssetsImporter::class)->commit($defaulted, $this->ctx)->isOk())->toBeTrue();
});

it('drops the useful life of a 100% write-off', function () {
    $path = openingAssetCsv(openingAssetHeaders(), openingAssetValues(['depreciation_method' => '100%', 'useful_life_months' => '60']));

    expect(app(FixedAssetsImporter::class)->commit($path, $this->ctx)->isOk())->toBeTrue();

    $asset = Asset::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();

    expect($asset->depreciationMethod()->value)->toBe('immediate')
        ->and($asset->useful_life_months)->toBeNull()
        ->and($asset->depreciation_rate)->toBeNull();
});

it('leaves an existing category\'s defaults alone', function () {
    AssetCategory::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'name' => 'Vehicles',
        'is_active' => true,
    ]);

    $path = openingAssetCsv(openingAssetHeaders(), openingAssetValues([
        'category_name' => 'Vehicles',
        'depreciation_method' => 'WDV',
        'depreciation_rate' => '25',
    ]));

    expect(app(FixedAssetsImporter::class)->commit($path, $this->ctx)->isOk())->toBeTrue();

    $category = AssetCategory::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Vehicles')->firstOrFail();

    expect($category->defaultDepreciationMethod()->value)->toBe('straight_line')
        ->and($category->default_depreciation_rate)->toBeNull();
});

it('offers the method and rate columns in its template', function () {
    $importer = app(FixedAssetsImporter::class);

    expect($importer->templateHeaders())->toContain('depreciation_method', 'depreciation_rate')
        ->and($importer->templateExampleRows()[0])->toHaveKeys(['depreciation_method', 'depreciation_rate']);
});
