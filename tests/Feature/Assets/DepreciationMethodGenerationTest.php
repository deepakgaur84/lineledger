<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetDepreciationEntry;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Assets\DepreciationGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-05-24 12:00:00');

    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $this->company);

    $this->accumDep = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Accumulated Depreciation')
        ->firstOrFail();
    $this->depExpense = Account::query()
        ->where('subtype', AccountSubtype::Expense->value)
        ->orderBy('code')
        ->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

function methodAsset(array $overrides = []): Asset
{
    return Asset::factory()->create(array_merge([
        'in_service_date' => '2026-04-10',
        'cost_cents' => 360000,
        'salvage_value_cents' => 0,
        'auto_depreciate' => true,
        'accumulated_depreciation_account_id' => test()->accumDep->id,
        'depreciation_expense_account_id' => test()->depExpense->id,
    ], $overrides));
}

/**
 * @return Collection<int, JournalEntry>
 */
function runMethodGeneration(): Collection
{
    return app(DepreciationGenerator::class)
        ->generateDue(test()->company, test()->company->currentDateTime()->startOfDay());
}

it('drafts an immediate asset\'s whole depreciable base in its in-service month', function () {
    $asset = methodAsset([
        'depreciation_method' => 'immediate',
        'salvage_value_cents' => 60000,
    ]);

    $created = runMethodGeneration();

    expect($created)->toHaveCount(1);

    $entry = $created->first()->load('lines');

    expect($entry->is_posted)->toBeFalse()
        ->and($entry->entry_date->toDateString())->toBe('2026-04-30')
        ->and($entry->isBalanced())->toBeTrue()
        ->and((int) $entry->lines->sum('debit_cents'))->toBe(300000);

    $pivots = AssetDepreciationEntry::query()->where('asset_id', $asset->id)->get();

    expect($pivots)->toHaveCount(1)
        ->and($pivots->first()->period->toDateString())->toBe('2026-04-01')
        ->and($pivots->first()->amount_cents)->toBe(300000);
});

it('never drafts an immediate asset a second time', function () {
    methodAsset(['depreciation_method' => 'immediate', 'in_service_date' => '2026-01-15']);

    expect(runMethodGeneration())->toHaveCount(1);
    expect(runMethodGeneration())->toHaveCount(0);

    expect(AssetDepreciationEntry::query()->count())->toBe(1);
});

it('drafts a declining-balance asset at the annual-step monthly charge', function () {
    $asset = methodAsset([
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
        'cost_cents' => 1000000,
    ]);

    $created = runMethodGeneration();

    // 20% of 10,000.00 = 2,000.00 a year → 16,666 for the first eleven months.
    expect($created)->toHaveCount(1)
        ->and(AssetDepreciationEntry::query()->where('asset_id', $asset->id)->firstOrFail()->amount_cents)->toBe(16666);
});

it('drafts a declining-balance asset with a useful life at the life-tail charge when its life is a single year', function () {
    $asset = methodAsset([
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
        'useful_life_months' => 12,
        'cost_cents' => 1000000,
    ]);

    runMethodGeneration();

    // A 12-month life is entirely the tail year: the whole base over 12 months,
    // not the 16,666 a month that 20% a year would give without a life.
    expect(AssetDepreciationEntry::query()->where('asset_id', $asset->id)->firstOrFail()->amount_cents)->toBe(83333);
});

it('catches up several months of a declining-balance asset at the same monthly charge within a year', function () {
    methodAsset([
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
        'cost_cents' => 1000000,
        'in_service_date' => '2026-02-01',
    ]);

    $created = runMethodGeneration();

    expect($created)->toHaveCount(3)
        ->and(AssetDepreciationEntry::query()->orderBy('period')->pluck('amount_cents')->all())
        ->toBe([16666, 16666, 16666]);
});

it('does not need a useful life for declining-balance or immediate assets to auto-depreciate', function () {
    $immediate = methodAsset(['depreciation_method' => 'immediate']);
    $declining = methodAsset(['depreciation_method' => 'declining_balance', 'depreciation_rate' => 20]);

    expect($immediate->useful_life_months)->toBeNull()
        ->and($immediate->isAutoDepreciable())->toBeTrue()
        ->and($declining->useful_life_months)->toBeNull()
        ->and($declining->isAutoDepreciable())->toBeTrue();
});

it('still needs a useful life for straight-line, and a rate for declining balance', function () {
    $straightLine = methodAsset();
    $noRate = methodAsset(['depreciation_method' => 'declining_balance']);

    expect($straightLine->isAutoDepreciable())->toBeFalse()
        ->and($noRate->isAutoDepreciable())->toBeFalse();

    $straightLine->update(['useful_life_months' => 36]);
    $noRate->update(['depreciation_rate' => 20]);

    expect($straightLine->fresh()->isAutoDepreciable())->toBeTrue()
        ->and($noRate->fresh()->isAutoDepreciable())->toBeTrue();
});
