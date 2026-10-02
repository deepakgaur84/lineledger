<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetDepreciationEntry;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Reporting\RenderableReports;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->assetAccount = Account::query()->where('subtype', AccountSubtype::FixedAsset->value)->where('name', 'Office Equipment')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Creates one depreciation entry directly against $asset for $period, without
 * running the real generator+poster — this report only ever reads `period`
 * and `amount_cents` off AssetDepreciationEntry, never the journal entry it
 * points to, so a minimal, unposted one is sufficient and far faster across
 * many months of test fixtures than generating and posting each one for real.
 */
function postDepreciation(Asset $asset, string $period, int $cents): AssetDepreciationEntry
{
    $entry = JournalEntry::create(['entry_no' => 'JE-TEST-'.uniqid(), 'entry_date' => $period, 'memo' => 'Test depreciation']);

    return AssetDepreciationEntry::create([
        'company_id' => test()->company->id,
        'asset_id' => $asset->id,
        'journal_entry_id' => $entry->id,
        'period' => $period,
        'amount_cents' => $cents,
    ]);
}

function scheduleReport(string $start, string $end, array $set = [])
{
    $test = Livewire::test('pages::reports.depreciation-schedule', ['company' => test()->company])
        ->set('startDate', $start)
        ->set('endDate', $end);

    foreach ($set as $property => $value) {
        $test->set($property, $value);
    }

    return $test->instance()->report;
}

it('computes the roll-forward for an asset acquired within the period', function () {
    $asset = Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'name' => 'Laptop',
        'cost_cents' => 120000,
        'acquired_date' => '2026-01-01',
        'depreciation_method' => 'straight_line',
        'useful_life_months' => 12,
    ]);

    foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $period) {
        postDepreciation($asset, $period, 10000);
    }

    $report = scheduleReport('2026-01-01', '2026-03-31');
    $row = $report['groups'][0]['rows'][0];

    // Not on the register yet as of the opening date (acquired exactly on the
    // period's first day, so the DAY BEFORE it has nothing).
    expect($row['opening_value_cents'])->toBe(0)
        ->and($row['opening_accum_cents'])->toBe(0)
        ->and($row['purchases_cents'])->toBe(120000)
        ->and($row['purchased_date'])->toBe('2026-01-01')
        ->and($row['depreciation_cents'])->toBe(30000)
        ->and($row['disposals_cents'])->toBe(0)
        ->and($row['closing_accum_cents'])->toBe(30000)
        ->and($row['closing_value_cents'])->toBe(90000);

    // The roll-forward identity the reference report itself is built on.
    expect($row['opening_value_cents'] + $row['purchases_cents'] - $row['disposals_cents'] - $row['depreciation_cents'])
        ->toBe($row['closing_value_cents']);
});

it('carries an opening balance for an asset acquired before the period', function () {
    $asset = Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'cost_cents' => 240000,
        'acquired_date' => '2025-01-01',
        'depreciation_method' => 'straight_line',
        'useful_life_months' => 24,
    ]);

    // 12 months already posted before the report period starts.
    foreach (range(1, 12) as $m) {
        postDepreciation($asset, sprintf('2025-%02d-01', $m), 10000);
    }
    foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $period) {
        postDepreciation($asset, $period, 10000);
    }

    $row = scheduleReport('2026-01-01', '2026-03-31')['groups'][0]['rows'][0];

    expect($row['opening_accum_cents'])->toBe(120000)
        ->and($row['opening_value_cents'])->toBe(120000)
        ->and($row['purchases_cents'])->toBe(0)
        ->and($row['depreciation_cents'])->toBe(30000)
        ->and($row['closing_accum_cents'])->toBe(150000)
        ->and($row['closing_value_cents'])->toBe(90000);
});

it('shows the straight-line rate when the asset has one stored — the bug fix this depends on', function () {
    $asset = Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'cost_cents' => 120000,
        'acquired_date' => '2026-01-01',
        'depreciation_method' => 'straight_line',
        'useful_life_months' => 60,
        'depreciation_rate' => 20,
    ]);

    $row = scheduleReport('2026-01-01', '2026-01-31')['groups'][0]['rows'][0];

    expect($row['rate'])->toBe(20.0)
        ->and($row['useful_life'])->toBe(60);
});

it('shows a null rate for a straight-line asset that only ever had a useful life typed', function () {
    Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'depreciation_method' => 'straight_line',
        'useful_life_months' => 36,
        'depreciation_rate' => null,
    ]);

    $row = scheduleReport('2026-01-01', '2026-01-31')['groups'][0]['rows'][0];

    expect($row['rate'])->toBeNull();
});

it('computes disposals as the net book value at the disposal date, removed from closing value', function () {
    $asset = Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'cost_cents' => 120000,
        'acquired_date' => '2025-01-01',
        'depreciation_method' => 'straight_line',
        'useful_life_months' => 12,
        'status' => 'disposed',
        'disposed_at' => '2026-02-15',
    ]);

    foreach (range(1, 12) as $m) {
        postDepreciation($asset, sprintf('2025-%02d-01', $m), 10000);
    }
    // Nothing further depreciates once fully written down, matching the
    // reference report's own disposed-asset rows (opening/depreciation blank).

    $row = scheduleReport('2026-01-01', '2026-03-31')['groups'][0]['rows'][0];

    expect($row['opening_value_cents'])->toBe(0)
        ->and($row['disposed_date'])->toBe('2026-02-15')
        ->and($row['disposals_cents'])->toBe(0)
        ->and($row['closing_value_cents'])->toBe(0);
});

it('writes off a genuine remaining balance at disposal, not just a fully depreciated one', function () {
    $asset = Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'cost_cents' => 120000,
        'acquired_date' => '2026-01-01',
        'depreciation_method' => 'straight_line',
        'useful_life_months' => 12,
        'status' => 'disposed',
        'disposed_at' => '2026-02-15',
    ]);

    // One month posted (January) before the mid-February disposal.
    postDepreciation($asset, '2026-01-01', 10000);

    $row = scheduleReport('2026-01-01', '2026-03-31')['groups'][0]['rows'][0];

    expect($row['disposals_cents'])->toBe(110000)
        ->and($row['closing_value_cents'])->toBe(0);
});

it('excludes disposed assets entirely when includeDisposed is off', function () {
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'status' => 'in-service']);
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'status' => 'disposed', 'disposed_at' => '2026-01-15']);

    $withDisposed = scheduleReport('2026-01-01', '2026-01-31', ['includeDisposed' => true]);
    $withoutDisposed = scheduleReport('2026-01-01', '2026-01-31', ['includeDisposed' => false]);

    expect(array_sum(array_map(fn (array $g): int => count($g['rows']), $withDisposed['groups'])))->toBe(2)
        ->and(array_sum(array_map(fn (array $g): int => count($g['rows']), $withoutDisposed['groups'])))->toBe(1);
});

it('groups by category, alphabetically, with Uncategorized for assets that have none', function () {
    $vehicles = AssetCategory::create(['name' => 'Vehicles', 'is_active' => true]);
    $furniture = AssetCategory::create(['name' => 'Furniture', 'is_active' => true]);

    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'asset_category_id' => $vehicles->id]);
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'asset_category_id' => $furniture->id]);
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'asset_category_id' => null]);

    $groups = scheduleReport('2026-01-01', '2026-01-31')['groups'];

    expect(array_column($groups, 'label'))->toBe(['Furniture', 'Uncategorized', 'Vehicles']);
});

it('sorts assets within a group by name', function () {
    AssetCategory::create(['name' => 'Equipment', 'is_active' => true]);
    $category = AssetCategory::first();

    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'asset_category_id' => $category->id, 'name' => 'Zebra printer']);
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'asset_category_id' => $category->id, 'name' => 'Anvil']);

    $names = array_column(scheduleReport('2026-01-01', '2026-01-31')['groups'][0]['rows'], 'name');

    expect($names)->toBe(['Anvil', 'Zebra printer']);
});

it('defaults to exactly the eleven columns asked for, in the order given', function () {
    $component = Livewire::test('pages::reports.depreciation-schedule', ['company' => $this->company]);

    expect($component->instance()->visibleColumns)->toBe([
        'asset_no', 'name', 'method', 'rate', 'opening_value',
        'purchased', 'purchases', 'disposed', 'disposals',
        'depreciation', 'closing_value',
    ]);
});

it('offers every other column as hideable/showable, but never Sale Price or Dep Recovered', function () {
    $options = Livewire::test('pages::reports.depreciation-schedule', ['company' => $this->company])
        ->instance()->columnOptions();

    expect($options)->toHaveKeys(['category', 'useful_life', 'cost', 'opening_accum_dep', 'closing_accum_dep', 'status'])
        ->and($options)->not->toHaveKey('sale_price')
        ->and($options)->not->toHaveKey('dep_recovered');

    foreach (array_keys($options) as $key) {
        expect($key)->not->toContain('sale')->not->toContain('recovered');
    }
});

it('toggles a hidden column on and a visible column off', function () {
    $component = Livewire::test('pages::reports.depreciation-schedule', ['company' => $this->company]);

    expect($component->instance()->visibleColumns)->not->toContain('cost');

    $component->call('toggleColumn', 'cost');
    expect($component->instance()->visibleColumns)->toContain('cost');

    $component->call('toggleColumn', 'name');
    expect($component->instance()->visibleColumns)->not->toContain('name');
});

it('ignores an attempt to toggle a column that does not exist', function () {
    $component = Livewire::test('pages::reports.depreciation-schedule', ['company' => $this->company]);
    $before = $component->instance()->visibleColumns;

    $component->call('toggleColumn', 'not_a_real_column');

    expect($component->instance()->visibleColumns)->toBe($before);
});

it('sums group and grand totals correctly across more than one group', function () {
    $vehicles = AssetCategory::create(['name' => 'Vehicles', 'is_active' => true]);

    $a = Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'asset_category_id' => null, 'cost_cents' => 100000, 'acquired_date' => '2025-06-01']);
    $b = Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'asset_category_id' => $vehicles->id, 'cost_cents' => 50000, 'acquired_date' => '2025-06-01']);

    $report = scheduleReport('2026-01-01', '2026-01-31');

    $uncategorized = collect($report['groups'])->firstWhere('label', 'Uncategorized');
    $vehiclesGroup = collect($report['groups'])->firstWhere('label', 'Vehicles');

    expect($uncategorized['totals']['cost_cents'])->toBe(100000)
        ->and($vehiclesGroup['totals']['cost_cents'])->toBe(50000)
        ->and($report['grand_totals']['cost_cents'])->toBe(150000);
});

it('never crashes and shows an empty-state message when there are no assets at all', function () {
    Livewire::test('pages::reports.depreciation-schedule', ['company' => $this->company])
        ->assertOk()
        ->assertSee('No fixed assets recorded yet.');

    // The specific regression this guards: grand-totals used to be computed
    // via array_merge(...[]) when there were no groups, which throws in
    // modern PHP (array_merge requires at least one argument) rather than
    // simply producing zero totals.
    $report = scheduleReport('2026-01-01', '2026-01-31');
    expect($report['groups'])->toBe([])
        ->and($report['grand_totals']['cost_cents'])->toBe(0);
});

it('exports to CSV and PDF without error', function () {
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'name' => 'Laptop', 'cost_cents' => 50000, 'acquired_date' => '2026-01-01']);

    $component = Livewire::test('pages::reports.depreciation-schedule', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-01-31')
        ->assertOk()
        ->assertSee('Laptop')
        ->assertSeeHtml('data-test="schedule-table"');

    $csvResponse = $component->instance()->exportCsv();
    expect($csvResponse)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $csvResponse->sendContent();
    $csv = ob_get_clean();
    expect($csv)->toContain('Laptop');

    expect($component->instance()->exportPdf())->toBeInstanceOf(BinaryFileResponse::class);
});

it('does not let a user-controlled asset name execute as a spreadsheet formula in CSV export', function () {
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'name' => '=cmd|/c calc', 'cost_cents' => 10000, 'acquired_date' => '2026-01-01']);

    $component = Livewire::test('pages::reports.depreciation-schedule', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-01-31');

    $response = $component->instance()->exportCsv();
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    // CsvExporter::neutralize() prefixes a formula-like cell with a single
    // quote so it is never auto-evaluated as a live formula when opened.
    expect($csv)->toContain("'=cmd");
});

it('is reachable from the report catalog and renderable outside a Livewire request', function () {
    expect(RenderableReports::supports('reports.depreciation-schedule', 'pdf'))->toBeTrue();

    $this->get(route('reports.depreciation-schedule', ['company' => $this->company->slug]))
        ->assertOk();
});
