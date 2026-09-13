<?php

use App\Actions\Assets\SaveAsset;
use App\Actions\Budgeting\SaveBudget;
use App\Actions\Sales\SaveInvoiceTemplate;
use App\Actions\Tax\SaveTaxReturn;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\TaxReturnStatus;
use App\Livewire\Attributes\GuardsEditLock;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Budget;
use App\Models\Company;
use App\Models\EditLock;
use App\Models\InvoiceTemplate;
use App\Models\TaxCode;
use App\Services\EditLocks\EditLockManager;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    app()->instance('current_company', $this->company);
    $this->locks = app(EditLockManager::class);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $fixedAsset = Account::query()->where('subtype', AccountSubtype::FixedAsset->value)->where('name', 'Office Equipment')->firstOrFail();
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();

    // Each record is built through the same Action its form saves with.
    $this->makers = [
        'budget' => fn (): Budget => app(SaveBudget::class)->handle([
            'name' => 'FY2026 plan',
            'fiscal_year' => 2026,
            'lines' => [['account_id' => $income->id, 'month_1_cents' => 50000]],
        ]),
        'taxReturn' => fn () => app(SaveTaxReturn::class)->handle([
            'tax_agency_id' => $gst->agency_id,
            'tax_return_no' => 'TR-LOCK-1',
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'notes' => 'Original notes',
        ]),
        'asset' => fn (): Asset => app(SaveAsset::class)->handle([
            'name' => 'Laptop',
            'asset_account_id' => $fixedAsset->id,
            'acquired_date' => '2026-01-15',
            'cost_cents' => 150000,
        ]),
        'invoiceTemplate' => fn (): InvoiceTemplate => app(SaveInvoiceTemplate::class)->handle([
            'name' => 'Standard package',
            'is_active' => true,
            'lines' => [[
                'account_id' => $income->id,
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price_cents' => 10000,
            ]],
        ]),
    ];
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

dataset('misc edit forms', [
    // [component, mount parameter, record maker, save method, field, attribute]
    'budget' => ['pages::budgets.form', 'budget', 'budget', 'save', 'name', 'name'],
    'tax return' => ['pages::tax-returns.form', 'tax_return', 'taxReturn', 'saveDraft', 'notes', 'notes'],
    'asset' => ['pages::assets.form', 'asset', 'asset', 'save', 'name', 'name'],
    'invoice template' => ['pages::invoice-templates.form', 'invoiceTemplate', 'invoiceTemplate', 'save', 'name', 'name'],
]);

it('takes the lock when the record is opened for editing', function (string $component, string $param, string $maker, string $saveMethod, string $field, string $attribute) {
    $record = ($this->makers[$maker])();

    $this->actingAs($this->jane);
    $form = Livewire::test($component, ['company' => $this->company, $param => $record])
        ->assertSet('editLockBlocked', false);

    expect($form->get('editLockToken'))->toHaveLength(40)
        ->and(EditLock::query()->sole()->user_id)->toBe($this->jane->id);
})->with('misc edit forms');

it('shows a second member who is editing instead of the form, and refuses a crafted save', function (string $component, string $param, string $maker, string $saveMethod, string $field, string $attribute) {
    $record = ($this->makers[$maker])();
    $before = $record->fresh()->{$attribute};

    $this->actingAs($this->jane);
    Livewire::test($component, ['company' => $this->company, $param => $record]);

    $this->actingAs($this->bob);
    Livewire::test($component, ['company' => $this->company, $param => $record->fresh()])
        ->assertSet('editLockBlocked', true)
        ->assertSet('editLockToken', null)
        ->assertSee($this->jane->name)
        ->set($field, 'Bob was here')
        ->call($saveMethod)
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect($record->fresh()->{$attribute})->toBe($before);
})->with('misc edit forms');

it('lets the member holding the lock save', function (string $component, string $param, string $maker, string $saveMethod, string $field, string $attribute) {
    $record = ($this->makers[$maker])();

    $this->actingAs($this->jane);
    Livewire::test($component, ['company' => $this->company, $param => $record])
        ->set($field, 'Updated by Jane')
        ->call($saveMethod)
        ->assertHasNoErrors();

    expect($record->fresh()->{$attribute})->toBe('Updated by Jane');
})->with('misc edit forms');

it('refuses archiving or deleting an asset from its show page while someone is editing it', function () {
    $asset = ($this->makers['asset'])();
    $this->locks->acquire($asset, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::assets.show', ['company' => $this->company, 'asset' => $asset])
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => 'asset']))
        ->call('archive')
        ->assertDispatched('toast-show');

    Livewire::test('pages::assets.show', ['company' => $this->company, 'asset' => $asset->fresh()])
        ->call('delete')
        ->assertNoRedirect();

    expect(Asset::query()->whereKey($asset->id)->exists())->toBeTrue()
        ->and($asset->fresh()->is_active)->toBeTrue();
});

it('archives an asset nobody is editing, replacing the lock version', function () {
    $asset = ($this->makers['asset'])();
    $mine = $this->locks->acquire($asset, $this->jane);
    $this->locks->release($mine->token, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::assets.show', ['company' => $this->company, 'asset' => $asset])
        ->call('archive');

    expect($asset->fresh()->is_active)->toBeFalse()
        ->and($this->locks->currentVersion($asset))->not->toBe($mine->version);
});

it('refuses filing a tax return from its show page while someone is editing it', function () {
    $taxReturn = ($this->makers['taxReturn'])();
    $this->locks->acquire($taxReturn, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::tax-returns.show', ['company' => $this->company, 'tax_return' => $taxReturn])
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => 'tax return']))
        ->call('file')
        ->assertDispatched('toast-show');

    expect($taxReturn->fresh()->status)->toBe(TaxReturnStatus::Draft);
});

it('guards exactly the show page methods that change the record', function (string $component, array $methods) {
    $class = app('livewire.factory')->resolveComponentClass($component);

    $guarded = collect((new ReflectionClass($class))->getMethods())
        ->filter(fn (ReflectionMethod $m) => $m->getAttributes(GuardsEditLock::class) !== [])
        ->map->getName()
        ->sort()->values()->all();

    expect($guarded)->toBe($methods);
})->with([
    'tax return' => ['pages::tax-returns.show', ['file', 'void']],
    'asset' => ['pages::assets.show', ['archive', 'delete', 'restore']],
]);

it('refuses deleting a budget someone is editing, and deletes one nobody is', function () {
    $budget = ($this->makers['budget'])();
    $held = $this->locks->acquire($budget, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::budgets.index', ['company' => $this->company])
        ->call('delete', $budget->id)
        ->assertDispatched('toast-show');

    expect(Budget::query()->whereKey($budget->id)->exists())->toBeTrue();

    $this->locks->release($held->token, $this->jane);

    Livewire::test('pages::budgets.index', ['company' => $this->company])
        ->call('delete', $budget->id);

    expect(Budget::query()->whereKey($budget->id)->exists())->toBeFalse();
});

it('refuses deleting an invoice template someone is editing', function () {
    $template = ($this->makers['invoiceTemplate'])();
    $this->locks->acquire($template, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::invoice-templates.index', ['company' => $this->company])
        ->call('delete', $template->id)
        ->assertDispatched('toast-show');

    expect(InvoiceTemplate::query()->whereKey($template->id)->exists())->toBeTrue();
});
