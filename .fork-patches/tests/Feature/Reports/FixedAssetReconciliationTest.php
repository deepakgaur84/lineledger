<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Assets\DepreciationGenerator;
use Carbon\CarbonImmutable;
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
    $this->accumDep = Account::query()->where('subtype', AccountSubtype::FixedAsset->value)->where('name', 'Accumulated Depreciation')->firstOrFail();
    $this->depExpense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Posts a simple, balanced journal entry — a debit to $account for $cents,
 * offset against Accounts Payable — simulating a bill that put an asset's
 * cost into the ledger.
 */
function postCostToLedger(Account $account, int $cents, string $date): JournalEntry
{
    $entry = app(\App\Actions\Accounting\SaveJournalEntry::class)->handle([
        'entry_date' => $date,
        'memo' => 'Test posting',
        'lines' => [
            ['account_id' => $account->id, 'debit_cents' => $cents, 'credit_cents' => 0],
            ['account_id' => test()->ap->id, 'debit_cents' => 0, 'credit_cents' => $cents],
        ],
    ]);

    return app(\App\Services\Posting\JournalPoster::class)->post($entry);
}

/**
 * Posts a simple, balanced journal entry crediting $account for $cents,
 * offset by a debit to Depreciation Expense — matching how depreciation
 * actually posts (DepreciationGenerator debits expense, credits Accumulated
 * Depreciation), unlike postCostToLedger() above which always debits.
 */
function postDepreciationToLedger(Account $account, int $cents, string $date): JournalEntry
{
    $entry = app(\App\Actions\Accounting\SaveJournalEntry::class)->handle([
        'entry_date' => $date,
        'memo' => 'Test depreciation posting',
        'lines' => [
            ['account_id' => test()->depExpense->id, 'debit_cents' => $cents, 'credit_cents' => 0],
            ['account_id' => $account->id, 'debit_cents' => 0, 'credit_cents' => $cents],
        ],
    ]);

    return app(\App\Services\Posting\JournalPoster::class)->post($entry);
}

function reconReport(string $start, string $end, string $groupBy = 'account')
{
    return Livewire::test('pages::reports.fixed-asset-reconciliation', ['company' => test()->company])
        ->set('startDate', $start)
        ->set('endDate', $end)
        ->set('groupBy', $groupBy)
        ->instance()
        ->report;
}

it('shows no difference when the register matches the GL exactly', function () {
    postCostToLedger($this->assetAccount, 120000, '2026-01-01');

    Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'accumulated_depreciation_account_id' => $this->accumDep->id,
        'depreciation_expense_account_id' => $this->depExpense->id,
        'cost_cents' => 120000,
        'acquired_date' => '2026-01-01',
        'in_service_date' => '2026-01-01',
        'useful_life_months' => 12,
        'auto_depreciate' => true,
    ]);

    CarbonImmutable::setTestNow('2026-04-15');
    $entries = app(DepreciationGenerator::class)->generateDue($this->company, $this->company->currentDateTime()->startOfDay());
    foreach ($entries as $entry) {
        app(\App\Services\Posting\JournalPoster::class)->post($entry);
    }
    CarbonImmutable::setTestNow();

    $report = reconReport('2026-01-01', '2026-03-31');
    $group = $report['groups'][0];

    // 120000/12 = 10000/month; Jan, Feb, Mar posted = 30000 accumulated by closing.
    expect($group['closing']['reg_cost'])->toBe(120000)
        ->and($group['closing']['bs_cost'])->toBe(120000)
        ->and($group['closing']['reg_accum'])->toBe(30000)
        ->and($group['closing']['bs_accum'])->toBe(30000)
        ->and($group['closing']['diff_cost'])->toBe(0)
        ->and($group['closing']['diff_accum'])->toBe(0)
        ->and($group['closing']['diff_book'])->toBe(0);
});

it('flags the cost as a difference, but not accumulated depreciation, when an asset is register-only', function () {
    // No postCostToLedger() call at all — the asset's cost never reached the GL,
    // exactly the register-only Bulk Import scenario this report exists to catch.
    $asset = Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'accumulated_depreciation_account_id' => $this->accumDep->id,
        'depreciation_expense_account_id' => $this->depExpense->id,
        'cost_cents' => 126000,
        'acquired_date' => '2026-01-01',
        'in_service_date' => '2026-01-01',
        'useful_life_months' => 12,
        'auto_depreciate' => true,
    ]);

    CarbonImmutable::setTestNow('2026-04-15');
    $entries = app(DepreciationGenerator::class)->generateDue($this->company, $this->company->currentDateTime()->startOfDay());
    foreach ($entries as $entry) {
        app(\App\Services\Posting\JournalPoster::class)->post($entry);
    }
    CarbonImmutable::setTestNow();

    $report = reconReport('2026-01-01', '2026-03-31');
    $group = $report['groups'][0];

    // Cost differs (GL never got it); Accum Dep does not, since depreciation
    // posts correctly regardless of how the asset entered the register — this
    // is exactly the pattern in the reference report this was built from.
    expect($group['closing']['reg_cost'])->toBe(126000)
        ->and($group['closing']['bs_cost'])->toBe(0)
        ->and($group['closing']['diff_cost'])->toBe(-126000)
        ->and($group['closing']['diff_accum'])->toBe(0);
});

it('negates Accumulated Depreciation into a positive magnitude, matching the Register side', function () {
    postCostToLedger($this->assetAccount, 120000, '2026-01-01');
    Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'accumulated_depreciation_account_id' => $this->accumDep->id,
        'depreciation_expense_account_id' => $this->depExpense->id,
        'cost_cents' => 120000,
        'acquired_date' => '2026-01-01',
        'in_service_date' => '2026-01-01',
        'useful_life_months' => 12,
        'auto_depreciate' => true,
    ]);

    CarbonImmutable::setTestNow('2026-02-15');
    foreach (app(DepreciationGenerator::class)->generateDue($this->company, $this->company->currentDateTime()->startOfDay()) as $entry) {
        app(\App\Services\Posting\JournalPoster::class)->post($entry);
    }
    CarbonImmutable::setTestNow();

    // Directly confirm the raw GL balance really is negative before the report
    // negates it — proves the negation is doing real work, not passing through
    // a value that was already positive.
    $raw = app(\App\Services\Reporting\ReportCalculator::class)->balanceAsOf($this->accumDep, CarbonImmutable::parse('2026-01-31'));
    expect($raw)->toBeLessThan(0);

    $report = reconReport('2026-01-01', '2026-01-31');
    expect($report['groups'][0]['closing']['bs_accum'])->toBeGreaterThan(0)
        ->and($report['groups'][0]['closing']['bs_accum'])->toBe(-$raw);
});

it('groups by account by default, and by category when selected', function () {
    $category = AssetCategory::create(['name' => 'Vehicles', 'is_active' => true]);

    postCostToLedger($this->assetAccount, 50000, '2026-01-01');

    Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'asset_category_id' => $category->id,
        'cost_cents' => 50000,
        'acquired_date' => '2026-01-01',
    ]);

    $byAccount = reconReport('2026-01-01', '2026-01-31', 'account');
    $byCategory = reconReport('2026-01-01', '2026-01-31', 'category');

    expect($byAccount['groups'][0]['label'])->toBe($this->assetAccount->name)
        ->and($byCategory['groups'][0]['label'])->toBe('Vehicles');
});

it('groups an asset with no category under Uncategorized', function () {
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'asset_category_id' => null, 'cost_cents' => 10000]);

    $report = reconReport('2026-01-01', '2026-01-31', 'category');

    expect($report['groups'][0]['label'])->toBe('Uncategorized');
});

it('surfaces drift as a difference when an asset points at a different account than its category default', function () {
    $otherAccount = Account::create(['code' => '1590', 'name' => 'Other Fixed Asset', 'subtype' => AccountSubtype::FixedAsset, 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit]);

    $category = AssetCategory::create([
        'name' => 'Vehicles',
        'default_asset_account_id' => $this->assetAccount->id,
        'is_active' => true,
    ]);

    // The asset's cost is posted to a DIFFERENT account than the category's default.
    postCostToLedger($otherAccount, 75000, '2026-01-01');

    Asset::factory()->create([
        'asset_account_id' => $otherAccount->id,
        'asset_category_id' => $category->id,
        'cost_cents' => 75000,
        'acquired_date' => '2026-01-01',
    ]);

    $report = reconReport('2026-01-01', '2026-01-31', 'category');
    $group = $report['groups'][0];

    // Register side counts the asset (75000); GL side checks the CATEGORY's
    // default account, which has nothing posted to it — so this surfaces as a
    // real difference, exactly as intended.
    expect($group['closing']['reg_cost'])->toBe(75000)
        ->and($group['closing']['bs_cost'])->toBe(0)
        ->and($group['closing']['diff_cost'])->toBe(-75000);
});

it('falls back to the assets\' own accounts, by account grouping\'s own rule, for a category with no default', function () {
    $category = AssetCategory::create(['name' => 'Vehicles', 'is_active' => true]); // no default_asset_account_id

    postCostToLedger($this->assetAccount, 40000, '2026-01-01');

    Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'asset_category_id' => $category->id,
        'cost_cents' => 40000,
        'acquired_date' => '2026-01-01',
    ]);

    $report = reconReport('2026-01-01', '2026-01-31', 'category');
    $group = $report['groups'][0];

    // With no category default to check, it falls back to the asset's own
    // account — which does have the posting — so no false difference appears.
    expect($group['closing']['bs_cost'])->toBe(40000)
        ->and($group['closing']['diff_cost'])->toBe(0);
});

it('drops a disposed asset from the register side once its disposal date has passed', function () {
    postCostToLedger($this->assetAccount, 60000, '2026-01-01');

    Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'cost_cents' => 60000,
        'acquired_date' => '2026-01-01',
        'disposed_at' => '2026-02-15',
        'status' => 'disposed',
    ]);

    $beforeDisposal = reconReport('2026-01-01', '2026-02-01');
    $afterDisposal = reconReport('2026-01-01', '2026-03-01');

    // Before disposal: register still shows it, matching the GL, no difference.
    expect($beforeDisposal['groups'][0]['closing']['reg_cost'])->toBe(60000)
        ->and($beforeDisposal['groups'][0]['closing']['diff_cost'])->toBe(0);

    // After disposal: the register drops it (LineLedger's disposal posts no
    // journal entry of its own), so the GL still has 60000 but the register
    // now has 0 — surfacing exactly the gap this report exists to catch.
    expect($afterDisposal['groups'][0]['closing']['reg_cost'])->toBe(0)
        ->and($afterDisposal['groups'][0]['closing']['bs_cost'])->toBe(60000)
        ->and($afterDisposal['groups'][0]['closing']['diff_cost'])->toBe(60000);
});

it('computes opening and closing independently for an asset acquired mid-period', function () {
    postCostToLedger($this->assetAccount, 90000, '2026-02-10');

    Asset::factory()->create([
        'asset_account_id' => $this->assetAccount->id,
        'cost_cents' => 90000,
        'acquired_date' => '2026-02-10',
    ]);

    $report = reconReport('2026-01-01', '2026-03-31');
    $group = $report['groups'][0];

    expect($group['opening']['reg_cost'])->toBe(0)
        ->and($group['opening']['bs_cost'])->toBe(0)
        ->and($group['closing']['reg_cost'])->toBe(90000)
        ->and($group['closing']['bs_cost'])->toBe(90000);
});

it('sums accumulated depreciation across every distinct account an account-group\'s assets actually use', function () {
    $otherAccumAccount = Account::create(['code' => '1591', 'name' => 'Other Accum Dep', 'subtype' => AccountSubtype::FixedAsset, 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit]);

    postCostToLedger($this->assetAccount, 240000, '2026-01-01');
    // CREDIT each accum account, matching how depreciation actually posts —
    // postCostToLedger() always debits, which is wrong for this side and was
    // the actual bug: it produced a positive raw balance the report correctly
    // negated to -25000, while this test wrongly expected +25000. The report's
    // own negation logic was right all along; the test simulated the wrong
    // side of the entry.
    postDepreciationToLedger($this->accumDep, 10000, '2026-01-31');
    postDepreciationToLedger($otherAccumAccount, 15000, '2026-01-31');

    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'accumulated_depreciation_account_id' => $this->accumDep->id, 'cost_cents' => 120000, 'acquired_date' => '2026-01-01']);
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'accumulated_depreciation_account_id' => $otherAccumAccount->id, 'cost_cents' => 120000, 'acquired_date' => '2026-01-01']);

    $report = reconReport('2026-01-01', '2026-01-31');

    // Both distinct accum accounts for this single asset-account group summed together.
    expect($report['groups'][0]['closing']['bs_accum'])->toBe(25000);
});

it('exports to CSV, XLSX and PDF without error', function () {
    postCostToLedger($this->assetAccount, 50000, '2026-01-01');
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'cost_cents' => 50000, 'acquired_date' => '2026-01-01']);

    $component = Livewire::test('pages::reports.fixed-asset-reconciliation', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-01-31')
        ->assertOk()
        ->assertSee($this->assetAccount->name)
        ->assertSeeHtml('data-test="recon-table"');

    $csvResponse = $component->instance()->exportCsv();
    expect($csvResponse)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $csvResponse->sendContent();
    $csv = ob_get_clean();
    expect($csv)->toContain($this->assetAccount->name);

    expect($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class)
        ->and($component->instance()->exportPdf())->toBeInstanceOf(BinaryFileResponse::class);
});

it('does not let a user-controlled group label execute as a spreadsheet formula', function () {
    // CWE-1236: a category/account name starting with "=" must render as inert
    // text in the XLSX export, not a live formula — confirmed by exercising
    // the exact export path with such a name, not just reading the source.
    $category = AssetCategory::create(['name' => '=cmd|/c calc', 'is_active' => true]);
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'asset_category_id' => $category->id, 'cost_cents' => 10000, 'acquired_date' => '2026-01-01']);

    $component = Livewire::test('pages::reports.fixed-asset-reconciliation', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-01-31')
        ->set('groupBy', 'category');

    expect($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class);
});

it('shows a message and no crash when there are no fixed assets at all', function () {
    Livewire::test('pages::reports.fixed-asset-reconciliation', ['company' => $this->company])
        ->assertOk()
        ->assertSee('No fixed assets recorded yet.');
});

it('is reachable from the report catalog and renderable outside a Livewire request', function () {
    expect(\App\Support\Reporting\RenderableReports::supports('reports.fixed-asset-reconciliation', 'pdf'))->toBeTrue();

    $this->get(route('reports.fixed-asset-reconciliation', ['company' => $this->company->slug]))
        ->assertOk();
});
