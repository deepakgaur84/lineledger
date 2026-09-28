<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\BulkImport\BulkImportRegistry;
use App\Services\BulkImport\Importers\FixedAssetImporter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-05-24 12:00:00');

    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $this->company);
    $this->importer = app(FixedAssetImporter::class);

    $this->assetAccount = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Office Equipment')
        ->firstOrFail();
    $this->accumDep = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Accumulated Depreciation')
        ->firstOrFail();
    $this->expense = Account::query()
        ->where('subtype', AccountSubtype::Expense->value)
        ->orderBy('code')
        ->firstOrFail();
    $this->bank = Account::query()
        ->where('subtype', AccountSubtype::Bank->value)
        ->orderBy('code')
        ->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

/**
 * A CSV row as the bulk-import page hands it over: every column present, blank
 * unless set.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function fixedAssetRow(array $overrides = []): array
{
    $blank = array_fill_keys(array_keys(app(FixedAssetImporter::class)->csvColumns()), '');

    return array_merge($blank, [
        'name' => 'Delivery van',
        'asset_account_code' => test()->assetAccount->code,
        'acquired_date' => '2026-01-15',
        'cost' => '10000.00',
    ], $overrides);
}

function fixedAssetAccountsForAuto(): array
{
    return [
        'accum_depreciation_account_code' => test()->accumDep->code,
        'depreciation_expense_account_code' => test()->expense->code,
    ];
}

it('creates a register-only asset from a minimal row without touching the ledger', function () {
    $entriesBefore = JournalEntry::query()->count();
    $row = fixedAssetRow();

    expect($this->importer->validate($row, $this->company))->toBe([]);

    $this->importer->commit($row, $this->company);

    $asset = Asset::query()->where('name', 'Delivery van')->firstOrFail();

    expect($asset->asset_no)->toStartWith('AST-')
        ->and($asset->cost_cents)->toBe(1000000)
        ->and($asset->depreciationMethod()->value)->toBe('straight_line')
        ->and($asset->useful_life_months)->toBeNull()
        ->and($asset->auto_depreciate)->toBeFalse()
        ->and(JournalEntry::query()->count())->toBe($entriesBefore);
});

it('requires a name, an asset account, an acquired date and a cost above zero', function () {
    foreach (['name', 'asset_account_code', 'acquired_date', 'cost'] as $column) {
        expect($this->importer->validate(fixedAssetRow([$column => '']), $this->company))
            ->not->toBeEmpty("a blank {$column} must be refused");
    }

    expect($this->importer->validate(fixedAssetRow(['cost' => '0']), $this->company))->not->toBeEmpty();
});

it('refuses an asset account that is not a fixed-asset account, or does not exist', function () {
    $notFixed = $this->importer->validate(fixedAssetRow(['asset_account_code' => $this->bank->code]), $this->company);
    $unknown = $this->importer->validate(fixedAssetRow(['asset_account_code' => '99999']), $this->company);

    expect(implode(' ', $notFixed))->toContain('fixed-asset')
        ->and($unknown)->not->toBeEmpty();
});

it('accepts the usual spellings of each depreciation method and stores the right one', function () {
    $spellings = [
        'WDV' => 'declining_balance',
        'Reducing balance' => 'declining_balance',
        'Straight line' => 'straight_line',
        '100%' => 'immediate',
        'immediate' => 'immediate',
    ];

    foreach ($spellings as $text => $expected) {
        $row = fixedAssetRow(['name' => "Asset {$text}", 'depreciation_method' => $text]);

        expect($this->importer->validate($row, $this->company))->toBe([]);

        $this->importer->commit($row, $this->company);

        expect(Asset::query()->where('name', "Asset {$text}")->firstOrFail()->depreciationMethod()->value)->toBe($expected);
    }
});

it('rejects a depreciation method it does not recognise', function () {
    $errors = $this->importer->validate(fixedAssetRow(['depreciation_method' => 'sum of years']), $this->company);

    expect(implode(' ', $errors))->toContain('not recognised');
});

it('gives a declining-balance row with no rate 20%, and says so in the preview', function () {
    $row = fixedAssetRow(['depreciation_method' => 'WDV']);

    expect($this->importer->validate($row, $this->company))->toBe([]);

    $summary = $this->importer->summarize($row, $this->company);

    expect($summary['Name'])->toBe('Delivery van')
        ->and($summary['Depreciation'])->toContain('Written-down value')
        ->and($summary['Depreciation'])->toContain('20% a year')
        ->and($summary['Depreciation'])->toContain('(rate defaulted)');

    $this->importer->commit($row, $this->company);

    expect((float) Asset::query()->where('name', 'Delivery van')->firstOrFail()->depreciation_rate)->toBe(20.0);
});

it('takes a given rate, with or without a percent sign, and refuses any outside 1 to 100', function () {
    foreach (['1', '20', '12.5', '100', '20%'] as $rate) {
        expect($this->importer->validate(fixedAssetRow(['depreciation_method' => 'WDV', 'depreciation_rate' => $rate]), $this->company))
            ->toBe([], "a rate of {$rate} must be accepted");
    }

    foreach (['0', '0.5', '0.99', '100.5', 'abc', '1.2345'] as $rate) {
        expect($this->importer->validate(fixedAssetRow(['depreciation_method' => 'WDV', 'depreciation_rate' => $rate]), $this->company))
            ->not->toBeEmpty("a rate of {$rate} must be refused");
    }

    $this->importer->commit(fixedAssetRow(['depreciation_method' => 'WDV', 'depreciation_rate' => '20%']), $this->company);

    expect((float) Asset::query()->where('name', 'Delivery van')->firstOrFail()->depreciation_rate)->toBe(20.0);
});

it('refuses a rate on a method that does not use one', function () {
    $straightLine = $this->importer->validate(fixedAssetRow(['depreciation_rate' => '20']), $this->company);
    $immediate = $this->importer->validate(fixedAssetRow(['depreciation_method' => '100%', 'depreciation_rate' => '20']), $this->company);

    expect(implode(' ', $straightLine))->toContain('declining_balance')
        ->and(implode(' ', $immediate))->toContain('declining_balance');
});

it('needs no useful life or rate for a 100% write-off, and drops a life it is given', function () {
    $row = fixedAssetRow(['depreciation_method' => '100%', 'useful_life_months' => '60']);

    expect($this->importer->validate($row, $this->company))->toBe([]);

    $this->importer->commit($row, $this->company);

    $asset = Asset::query()->where('name', 'Delivery van')->firstOrFail();

    expect($asset->depreciationMethod()->value)->toBe('immediate')
        ->and($asset->useful_life_months)->toBeNull()
        ->and($asset->depreciation_rate)->toBeNull();
});

it('needs both depreciation accounts and, for straight-line, a useful life to auto-depreciate', function () {
    $noAccounts = fixedAssetRow(['auto_depreciate' => 'yes', 'useful_life_months' => '36']);
    $noLife = fixedAssetRow(['auto_depreciate' => 'yes'] + fixedAssetAccountsForAuto());
    $complete = fixedAssetRow(['auto_depreciate' => 'yes', 'useful_life_months' => '36'] + fixedAssetAccountsForAuto());

    expect($this->importer->validate($noAccounts, $this->company))->not->toBeEmpty()
        ->and($this->importer->validate($noLife, $this->company))->not->toBeEmpty()
        ->and($this->importer->validate($complete, $this->company))->toBe([]);

    $this->importer->commit($complete, $this->company);

    $asset = Asset::query()->where('name', 'Delivery van')->firstOrFail();

    // A blank in-service date defaults to the acquired date once auto-depreciation is on.
    expect($asset->auto_depreciate)->toBeTrue()
        ->and($asset->in_service_date->toDateString())->toBe('2026-01-15');
});

it('lets declining balance auto-depreciate with no useful life', function () {
    $row = fixedAssetRow(['depreciation_method' => 'WDV', 'auto_depreciate' => 'yes'] + fixedAssetAccountsForAuto());

    expect($this->importer->validate($row, $this->company))->toBe([]);

    $this->importer->commit($row, $this->company);

    $asset = Asset::query()->where('name', 'Delivery van')->firstOrFail();

    expect($asset->auto_depreciate)->toBeTrue()
        ->and($asset->useful_life_months)->toBeNull();
});

it('insists auto_depreciate is yes or no', function () {
    expect($this->importer->validate(fixedAssetRow(['auto_depreciate' => 'maybe']), $this->company))->not->toBeEmpty();
});

it('fills blank fields from the category the way the asset form does', function () {
    AssetCategory::create([
        'name' => 'Vehicles',
        'default_asset_account_id' => $this->assetAccount->id,
        'default_accumulated_depreciation_account_id' => $this->accumDep->id,
        'default_depreciation_expense_account_id' => $this->expense->id,
        'default_useful_life_months' => 60,
        'default_depreciation_method' => 'declining_balance',
        'default_depreciation_rate' => 25,
        'is_active' => true,
    ]);

    $row = fixedAssetRow(['category_name' => 'vehicles', 'asset_account_code' => '']);

    expect($this->importer->validate($row, $this->company))->toBe([]);

    $this->importer->commit($row, $this->company);

    $asset = Asset::query()->where('name', 'Delivery van')->firstOrFail();

    expect($asset->asset_category_id)->not->toBeNull()
        ->and($asset->asset_account_id)->toBe($this->assetAccount->id)
        ->and($asset->accumulated_depreciation_account_id)->toBe($this->accumDep->id)
        ->and($asset->depreciation_expense_account_id)->toBe($this->expense->id)
        ->and($asset->depreciationMethod()->value)->toBe('declining_balance')
        ->and((float) $asset->depreciation_rate)->toBe(25.0)
        ->and($asset->useful_life_months)->toBe(60);
});

it('does not call a rate the category supplies "defaulted"', function () {
    AssetCategory::create([
        'name' => 'Vehicles',
        'default_depreciation_method' => 'declining_balance',
        'default_depreciation_rate' => 25,
        'is_active' => true,
    ]);

    $summary = $this->importer->summarize(fixedAssetRow(['category_name' => 'Vehicles']), $this->company);

    expect($summary['Depreciation'])->toContain('25% a year')
        ->and($summary['Depreciation'])->not->toContain('(rate defaulted)')
        ->and($summary['Category'])->toBe('Vehicles');
});

it('lets a row override the category\'s method', function () {
    AssetCategory::create([
        'name' => 'Vehicles',
        'default_useful_life_months' => 60,
        'default_depreciation_method' => 'declining_balance',
        'default_depreciation_rate' => 25,
        'is_active' => true,
    ]);

    $row = fixedAssetRow(['category_name' => 'Vehicles', 'depreciation_method' => 'straight line']);

    expect($this->importer->validate($row, $this->company))->toBe([]);

    $this->importer->commit($row, $this->company);

    $asset = Asset::query()->where('name', 'Delivery van')->firstOrFail();

    expect($asset->depreciationMethod()->value)->toBe('straight_line')
        ->and($asset->useful_life_months)->toBe(60)
        ->and($asset->depreciation_rate)->toBeNull();
});

it('uses 20% when a row asks for declining balance but its category has no rate to lend', function () {
    AssetCategory::create(['name' => 'Furniture', 'is_active' => true]);

    $row = fixedAssetRow(['category_name' => 'Furniture', 'depreciation_method' => 'WDV']);

    $this->importer->commit($row, $this->company);

    expect((float) Asset::query()->where('name', 'Delivery van')->firstOrFail()->depreciation_rate)->toBe(20.0);
});

it('refuses a category that does not exist or is archived', function () {
    AssetCategory::create(['name' => 'Old stock', 'is_active' => false]);

    expect(implode(' ', $this->importer->validate(fixedAssetRow(['category_name' => 'Nope']), $this->company)))->toContain('not found')
        ->and(implode(' ', $this->importer->validate(fixedAssetRow(['category_name' => 'Old stock']), $this->company)))->toContain('not found');
});

it('keeps the salvage value within the cost and leaves something to depreciate', function () {
    $tooMuch = fixedAssetRow(['salvage_value' => '20000.00']);
    $nothingLeft = fixedAssetRow(['salvage_value' => '10000.00', 'auto_depreciate' => 'yes', 'useful_life_months' => '36'] + fixedAssetAccountsForAuto());

    expect($this->importer->validate($tooMuch, $this->company))->not->toBeEmpty()
        ->and($this->importer->validate($nothingLeft, $this->company))->not->toBeEmpty();
});

it('rejects an asset number that is already in use', function () {
    $this->importer->commit(fixedAssetRow(['asset_no' => 'FA-1']), $this->company);

    expect($this->importer->validate(fixedAssetRow(['name' => 'Another', 'asset_no' => 'FA-1']), $this->company))->not->toBeEmpty();
});

it('previews how many draft months auto-depreciation would create, respecting the lock date', function () {
    $row = fixedAssetRow([
        'cost' => '3600.00',
        'useful_life_months' => '36',
        'auto_depreciate' => 'yes',
    ] + fixedAssetAccountsForAuto());

    // In service January 2026; today is 24 May, so January to April have ended.
    $summary = $this->importer->summarize($row, $this->company);

    expect($summary['Auto-depreciation'])->toBe('On')
        ->and($summary['Draft entries to generate'])->toContain('4 months')
        ->and($summary['Draft entries to generate'])->toContain('Jan 2026')
        ->and($summary['Draft entries to generate'])->toContain('Apr 2026')
        ->and($summary)->toHaveKey('⚠ Back-fills drafts');

    $this->company->update(['lock_date' => '2026-02-28']);

    expect($this->importer->summarize($row, $this->company)['Draft entries to generate'])->toContain('2 months');
});

it('previews nothing to generate for a register-only asset', function () {
    $summary = $this->importer->summarize(fixedAssetRow(), $this->company);

    expect($summary['Auto-depreciation'])->toBe('Off (register only)')
        ->and($summary)->not->toHaveKey('Draft entries to generate')
        ->and($summary)->not->toHaveKey('⚠ Back-fills drafts');
});

it('flags an asset that looks like one already in the register', function () {
    $this->importer->commit(fixedAssetRow(), $this->company);

    expect($this->importer->summarize(fixedAssetRow(), $this->company))->toHaveKey('⚠ Possible duplicate');
});

it('is available in the bulk-import tool', function () {
    $importer = BulkImportRegistry::find('fixed-assets');

    expect($importer)->toBeInstanceOf(FixedAssetImporter::class)
        ->and($importer->label())->toBe('Fixed Assets');
});
