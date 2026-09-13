<?php

use App\Actions\Banking\SaveBankRule;
use App\Actions\MasterData\SaveClassification;
use App\Actions\MasterData\SaveItem;
use App\Actions\MasterData\SaveItemCategory;
use App\Actions\MasterData\SavePaymentMethod;
use App\Actions\MasterData\SavePaymentTerm;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BankRuleMatchType;
use App\Enums\CompanyRole;
use App\Enums\ItemType;
use App\Models\Account;
use App\Models\BankRule;
use App\Models\Company;
use App\Services\EditLocks\EditLockManager;
use Livewire\Livewire;

/*
 * Edit dialogs on the bank-rules page and the settings lists (items, item
 * categories, payment methods, payment terms, classes): one member edits a row
 * at a time.
 */

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    $this->owner = editLockMember($this->company, CompanyRole::Owner);
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/** Each dialog page, with a closure that builds one of its rows. */
$dialogs = [
    'bank rules' => ['pages::banking.rules', fn () => app(SaveBankRule::class)->handle([
        'name' => 'Coffee shop',
        'match_type' => BankRuleMatchType::Contains->value,
        'match_pattern' => 'COFFEE',
        'action_account_id' => Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail()->id,
    ])],
    'items' => ['pages::settings.lists.items', fn () => app(SaveItem::class)->handle([
        'name' => 'Consulting hour',
        'type' => ItemType::Service->value,
        'income_account_id' => Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail()->id,
        'default_price_cents' => 12500,
    ])],
    'item categories' => ['pages::settings.lists.item-categories', fn () => app(SaveItemCategory::class)->handle(['name' => 'Hardware'])],
    'payment methods' => ['pages::settings.lists.payment-methods', fn () => app(SavePaymentMethod::class)->handle(['name' => 'PayPal'])],
    'payment terms' => ['pages::settings.lists.payment-terms', fn () => app(SavePaymentTerm::class)->handle(['name' => 'Net 45', 'days' => 45])],
    'classes' => ['pages::settings.lists.classifications', fn () => app(SaveClassification::class)->handle(['name' => 'East Region'])],
];

it('lets one member edit a row at a time and releases it on save', function (string $component, Closure $makeRecord) {
    $record = $makeRecord();
    $locks = app(EditLockManager::class);

    $this->actingAs($this->jane);
    $janes = Livewire::test($component, ['company' => $this->company])
        ->call('openEdit', $record->id)
        ->assertSet('editingId', $record->id)
        ->assertSeeHtml('data-test="edit-lock-keeper"');

    expect($janes->get('editLockToken'))->toHaveLength(40)
        ->and($locks->holderOtherThan($record, $this->bob)?->user->is($this->jane))->toBeTrue();

    // Bob is told who is editing; the dialog stays shut.
    $this->actingAs($this->bob);
    Livewire::test($component, ['company' => $this->company])
        ->call('openEdit', $record->id)
        ->assertSet('editingId', null)
        ->assertSet('editLockToken', null)
        ->assertSet('editLockPendingTakeover', null)
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'] ?? '', $this->jane->name));

    $this->actingAs($this->jane);
    $janes->set('f_name', 'Renamed by Jane')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editLockToken', null);

    expect($record->fresh()->name)->toBe('Renamed by Jane')
        ->and($locks->holderOtherThan($record, $this->bob))->toBeNull();

    // Once Jane is done, Bob can open it.
    $this->actingAs($this->bob);
    Livewire::test($component, ['company' => $this->company])
        ->call('openEdit', $record->id)
        ->assertSet('editingId', $record->id);
})->with($dialogs);

it('lets go of the open row when a new record is started', function (string $component, Closure $makeRecord) {
    $record = $makeRecord();

    $this->actingAs($this->jane);
    Livewire::test($component, ['company' => $this->company])
        ->call('openEdit', $record->id)
        ->call('openCreate')
        ->assertSet('editingId', null)
        ->assertSet('editLockToken', null);

    expect(app(EditLockManager::class)->holderOtherThan($record, $this->bob))->toBeNull();
})->with($dialogs);

it('offers an owner the take-over of a bank rule and stops the previous editor saving', function () {
    $expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $rule = app(SaveBankRule::class)->handle([
        'name' => 'Fuel',
        'match_type' => BankRuleMatchType::Contains->value,
        'match_pattern' => 'PETRO',
        'action_account_id' => $expense->id,
    ]);

    $this->actingAs($this->jane);
    $janes = Livewire::test('pages::banking.rules', ['company' => $this->company])
        ->call('openEdit', $rule->id)
        ->set('f_name', 'Jane typing');

    $this->actingAs($this->owner);
    $owners = Livewire::test('pages::banking.rules', ['company' => $this->company])
        ->call('openEdit', $rule->id)
        ->assertSet('editingId', null);

    expect($owners->get('editLockPendingTakeover')['name'])->toBe($this->jane->name);

    $owners->call('takeOverPendingEditLock')->assertSet('editingId', $rule->id);

    $this->actingAs($this->jane);
    $janes->call('save')->assertDispatched('toast-show');

    expect(BankRule::query()->findOrFail($rule->id)->name)->toBe('Fuel');
});
