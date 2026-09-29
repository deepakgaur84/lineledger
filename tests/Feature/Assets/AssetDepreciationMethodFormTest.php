<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-05-24 12:00:00');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->fixedAssetAccount = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Office Equipment')
        ->firstOrFail();
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

/**
 * A new-asset form filled in as far as the depreciation-independent fields go.
 */
function methodFormBase()
{
    return Livewire::test('pages::assets.form', ['company' => test()->company])
        ->set('name', 'Delivery van')
        ->set('asset_account_id', test()->fixedAssetAccount->id)
        ->set('acquired_date', '2026-01-15')
        ->set('in_service_date', '2026-01-15')
        ->set('cost', '10000.00');
}

it('asks for a depreciation method, defaulting to straight-line', function () {
    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->assertSet('depreciation_method', 'straight_line')
        ->assertSee('Depreciation method')
        ->assertSee('Written-down value (declining balance)')
        ->assertSee('100% on purchase (write off in full)');
});

it('shows only the fields each method needs', function () {
    $form = Livewire::test('pages::assets.form', ['company' => $this->company]);

    // Straight-line: a useful life, no rate, no materiality limit.
    $form->assertSeeHtml('data-test="asset-useful-life-input"')
        ->assertDontSeeHtml('data-test="asset-rate-input"')
        ->assertDontSeeHtml('data-test="asset-materiality-input"');

    // Declining balance with no life: rate, optional life and the materiality limit.
    $form->set('depreciation_method', 'declining_balance')
        ->assertSeeHtml('data-test="asset-rate-input"')
        ->assertSeeHtml('data-test="asset-useful-life-input"')
        ->assertSeeHtml('data-test="asset-materiality-input"');

    // A useful life takes over from the materiality limit.
    $form->set('useful_life_months', 60)
        ->assertSeeHtml('data-test="asset-rate-input"')
        ->assertDontSeeHtml('data-test="asset-materiality-input"');

    // Immediate write-off: nothing to ask beyond salvage.
    $form->set('depreciation_method', 'immediate')
        ->assertDontSeeHtml('data-test="asset-rate-input"')
        ->assertDontSeeHtml('data-test="asset-useful-life-input"')
        ->assertDontSeeHtml('data-test="asset-materiality-input"')
        ->assertSeeHtml('data-test="asset-salvage-input"');
});

it('requires an annual rate once declining balance is chosen', function () {
    methodFormBase()
        ->set('depreciation_method', 'declining_balance')
        ->call('save')
        ->assertHasErrors(['depreciation_rate']);

    expect(Asset::query()->count())->toBe(0);
});

it('refuses any rate below 1% or above 100%', function () {
    foreach (['0', '0.5', '0.99', '100.5', '250'] as $rate) {
        methodFormBase()
            ->set('depreciation_method', 'declining_balance')
            ->set('depreciation_rate', $rate)
            ->call('save')
            ->assertHasErrors(['depreciation_rate']);
    }

    expect(Asset::query()->count())->toBe(0);

    foreach (['1', '100'] as $rate) {
        methodFormBase()
            ->set('depreciation_method', 'declining_balance')
            ->set('depreciation_rate', $rate)
            ->call('save')
            ->assertHasNoErrors();
    }

    expect(Asset::query()->count())->toBe(2);
});

it('saves a declining-balance asset with a rate and no useful life', function () {
    methodFormBase()
        ->set('depreciation_method', 'declining_balance')
        ->set('depreciation_rate', '20')
        ->call('save')
        ->assertHasNoErrors();

    $asset = Asset::query()->where('name', 'Delivery van')->firstOrFail();

    expect($asset->depreciation_method->value)->toBe('declining_balance')
        ->and((float) $asset->depreciation_rate)->toBe(20.0)
        ->and($asset->useful_life_months)->toBeNull()
        ->and($asset->materiality_limit_cents)->toBeNull();
});

it('pre-fills the materiality limit with 5% of cost and follows the cost until it is edited', function () {
    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->assertSet('materiality_limit', '')
        ->set('depreciation_method', 'declining_balance')
        ->set('cost', '10000.00')
        ->assertSet('materiality_limit', '500.00')
        ->assertSet('materiality_touched', false)
        ->set('cost', '20000.00')
        ->assertSet('materiality_limit', '1000.00')
        // Choosing a limit takes it out of the default's hands...
        ->set('materiality_limit', '300.00')
        ->assertSet('materiality_touched', true)
        ->set('cost', '40000.00')
        ->assertSet('materiality_limit', '300.00')
        // ...and clearing the field hands it back.
        ->set('materiality_limit', '')
        ->assertSet('materiality_touched', false)
        ->assertSet('materiality_limit', '2000.00');
});

it('stores nothing for an untouched materiality limit, so the default keeps following cost', function () {
    methodFormBase()
        ->set('depreciation_method', 'declining_balance')
        ->set('depreciation_rate', '20')
        ->assertSet('materiality_limit', '500.00')
        ->call('save')
        ->assertHasNoErrors();

    expect(Asset::query()->where('name', 'Delivery van')->firstOrFail()->materiality_limit_cents)->toBeNull();
});

it('stores a materiality limit the user chose', function () {
    methodFormBase()
        ->set('depreciation_method', 'declining_balance')
        ->set('depreciation_rate', '20')
        ->set('materiality_limit', '300.00')
        ->call('save')
        ->assertHasNoErrors();

    expect(Asset::query()->where('name', 'Delivery van')->firstOrFail()->materiality_limit_cents)->toBe(30000);
});

it('rejects a negative materiality limit', function () {
    methodFormBase()
        ->set('depreciation_method', 'declining_balance')
        ->set('depreciation_rate', '20')
        ->set('materiality_limit', '-5.00')
        ->call('save')
        ->assertHasErrors(['materiality_limit']);

    expect(Asset::query()->count())->toBe(0);
});

it('saves an immediate write-off with no useful life or rate, and can auto-depreciate it', function () {
    methodFormBase()
        ->set('depreciation_method', 'immediate')
        ->set('accumulated_depreciation_account_id', $this->accumDep->id)
        ->set('depreciation_expense_account_id', $this->depExpense->id)
        ->set('auto_depreciate', true)
        ->call('save')
        ->assertHasNoErrors();

    $asset = Asset::query()->where('name', 'Delivery van')->firstOrFail();

    expect($asset->depreciation_method->value)->toBe('immediate')
        ->and($asset->useful_life_months)->toBeNull()
        ->and($asset->depreciation_rate)->toBeNull()
        ->and($asset->auto_depreciate)->toBeTrue();
});

it('lets declining balance auto-depreciate without a useful life', function () {
    methodFormBase()
        ->set('depreciation_method', 'declining_balance')
        ->set('depreciation_rate', '20')
        ->set('accumulated_depreciation_account_id', $this->accumDep->id)
        ->set('depreciation_expense_account_id', $this->depExpense->id)
        ->set('auto_depreciate', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Asset::query()->where('name', 'Delivery van')->firstOrFail()->auto_depreciate)->toBeTrue();
});

it('still needs a useful life to auto-depreciate on straight-line', function () {
    methodFormBase()
        ->set('accumulated_depreciation_account_id', $this->accumDep->id)
        ->set('depreciation_expense_account_id', $this->depExpense->id)
        ->set('auto_depreciate', true)
        ->call('save')
        ->assertHasErrors(['useful_life_months']);
});

it('clears the parameters a method does not use when it is saved', function () {
    methodFormBase()
        ->set('useful_life_months', 60)
        ->set('depreciation_method', 'immediate')
        ->call('save')
        ->assertHasNoErrors();

    expect(Asset::query()->where('name', 'Delivery van')->firstOrFail()->useful_life_months)->toBeNull();
});

it('loads the method, rate, useful life and materiality limit when editing', function () {
    $asset = Asset::factory()->create([
        'asset_account_id' => $this->fixedAssetAccount->id,
        'cost_cents' => 1000000,
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
        'useful_life_months' => 60,
        'materiality_limit_cents' => 75000,
    ]);

    Livewire::test('pages::assets.form', ['company' => $this->company, 'asset' => $asset])
        ->assertSet('depreciation_method', 'declining_balance')
        ->assertSet('depreciation_rate', '20')
        ->assertSet('useful_life_months', 60)
        ->assertSet('materiality_limit', '750.00')
        ->assertSet('materiality_touched', true);
});

it('shows the default materiality limit when editing an asset that never set one', function () {
    $asset = Asset::factory()->create([
        'asset_account_id' => $this->fixedAssetAccount->id,
        'cost_cents' => 1000000,
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
    ]);

    Livewire::test('pages::assets.form', ['company' => $this->company, 'asset' => $asset])
        ->assertSet('materiality_limit', '500.00')
        ->assertSet('materiality_touched', false);
});

it('shows the method, rate and materiality limit on the asset page, with a schedule and no useful life', function () {
    $asset = Asset::factory()->create([
        'asset_account_id' => $this->fixedAssetAccount->id,
        'accumulated_depreciation_account_id' => $this->accumDep->id,
        'depreciation_expense_account_id' => $this->depExpense->id,
        'in_service_date' => '2026-01-15',
        'cost_cents' => 1000000,
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
        'auto_depreciate' => true,
    ]);

    Livewire::test('pages::assets.show', ['company' => $this->company, 'asset' => $asset->fresh()])
        ->assertSee('Written-down value (declining balance)')
        ->assertSee('20% a year')
        ->assertSee('Materiality limit')
        ->assertSee('500.00')
        ->assertSee('default, 5% of cost')
        ->assertSeeHtml('data-test="asset-depreciation-schedule"')
        ->assertSeeHtml('data-test="asset-depreciation-note"');
});

it('shows an immediate write-off\'s schedule on the asset page', function () {
    $asset = Asset::factory()->create([
        'asset_account_id' => $this->fixedAssetAccount->id,
        'accumulated_depreciation_account_id' => $this->accumDep->id,
        'depreciation_expense_account_id' => $this->depExpense->id,
        'in_service_date' => '2026-01-15',
        'cost_cents' => 1000000,
        'depreciation_method' => 'immediate',
        'auto_depreciate' => true,
    ]);

    Livewire::test('pages::assets.show', ['company' => $this->company, 'asset' => $asset->fresh()])
        ->assertSee('100% on purchase (write off in full)')
        ->assertSeeHtml('data-test="asset-depreciation-schedule"')
        ->assertSee('Written off in full in the month the asset is placed in service.');
});

it('defaults the useful-life input to months, matching an existing asset\'s stored value', function () {
    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->assertSet('useful_life_input_mode', 'months')
        ->assertSeeHtml('data-test="asset-useful-life-input"')
        ->assertDontSeeHtml('data-test="asset-life-rate-input"');
});

it('computes useful life from a rate, matching Xero\'s own formula (100 ÷ years = rate)', function () {
    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->set('useful_life_input_mode', 'rate')
        ->set('straight_line_rate', '20')
        ->assertSet('useful_life_months', 60)
        ->set('straight_line_rate', '50')
        ->assertSet('useful_life_months', 24)
        ->set('straight_line_rate', '12.5')
        ->assertSet('useful_life_months', 96);
});

it('rounds a rate that does not divide evenly into whole months', function () {
    Livewire::test('pages::assets.form', ['company' => $this->company])
        ->set('useful_life_input_mode', 'rate')
        ->set('straight_line_rate', '15')
        ->assertSet('useful_life_months', 80);
});

it('ignores a blank, zero, or non-numeric rate rather than blanking out the useful life', function () {
    $form = Livewire::test('pages::assets.form', ['company' => $this->company])
        ->set('useful_life_input_mode', 'rate')
        ->set('straight_line_rate', '20')
        ->assertSet('useful_life_months', 60);

    foreach (['', '0', '-5', 'abc'] as $bad) {
        $form->set('straight_line_rate', $bad)->assertSet('useful_life_months', 60);
    }
});

it('saves the computed useful life, not the rate itself, on a straight-line asset', function () {
    methodFormBase()
        ->set('useful_life_input_mode', 'rate')
        ->set('straight_line_rate', '20')
        ->call('save')
        ->assertHasNoErrors();

    $asset = Asset::query()->where('name', 'Delivery van')->firstOrFail();

    expect($asset->useful_life_months)->toBe(60)
        ->and($asset->depreciation_rate)->toBeNull();
});

it('back-fills a starting rate from the stored useful life when switching to rate mode on an existing asset', function () {
    $asset = Asset::factory()->create([
        'asset_account_id' => $this->fixedAssetAccount->id,
        'depreciation_method' => 'straight_line',
        'useful_life_months' => 60,
    ]);

    Livewire::test('pages::assets.form', ['company' => $this->company, 'asset' => $asset])
        ->assertSet('useful_life_input_mode', 'months')
        ->set('useful_life_input_mode', 'rate')
        ->assertSet('straight_line_rate', '20');
});

it('offers no rate/months toggle for declining balance or immediate', function () {
    $form = Livewire::test('pages::assets.form', ['company' => $this->company]);

    $form->set('depreciation_method', 'declining_balance')
        ->assertDontSeeHtml('data-test="asset-life-mode-toggle"')
        ->assertDontSeeHtml('data-test="asset-life-rate-input"');

    $form->set('depreciation_method', 'immediate')
        ->assertDontSeeHtml('data-test="asset-life-mode-toggle"');
});
