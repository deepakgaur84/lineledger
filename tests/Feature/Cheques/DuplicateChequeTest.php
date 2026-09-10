<?php

use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Posting\ChequePoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function chequeToCopy(array $overrides = []): Cheque
{
    $payee = Contact::create(['display_name' => 'Acme Services', 'is_vendor' => true]);

    $cheque = Cheque::create(array_merge([
        'bank_account_id' => test()->bank->id,
        'cheque_no' => '1001',
        'cheque_date' => '2026-01-15',
        'payee_contact_id' => $payee->id,
        'payee_name' => 'Acme Services',
        'memo' => 'Monthly service',
    ], $overrides));

    $cheque->lines()->create([
        'account_id' => test()->expense->id,
        'description' => 'Service call',
        'amount_cents' => 12500,
        'line_order' => 0,
    ]);

    return $cheque->fresh('lines');
}

it('copies a cheque’s bank, payee, memo and lines onto a fresh draft', function () {
    $source = chequeToCopy();

    $component = Livewire::withQueryParams(['from' => $source->id])
        ->test('pages::cheques.form', ['company' => $this->company]);

    $component
        ->assertSet('bank_account_id', $this->bank->id)
        ->assertSet('payee_contact_id', $source->payee_contact_id)
        ->assertSet('payee_name', 'Acme Services')
        ->assertSet('memo', 'Monthly service');

    $lines = $component->get('lines');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['account_id'])->toBe($this->expense->id)
        ->and($lines[0]['description'])->toBe('Service call')
        ->and($lines[0]['amount'])->toBe('125.00');
});

it('gives the copy its own number and today’s date, leaving the source alone', function () {
    $source = chequeToCopy();

    $component = Livewire::withQueryParams(['from' => $source->id])
        ->test('pages::cheques.form', ['company' => $this->company]);

    expect($component->get('cheque_no'))->not->toBe('1001')
        ->and($component->get('cheque_date'))->toBe($this->company->currentDateTime()->toDateString())
        ->and($component->get('cheque'))->toBeNull();

    // Saving the copy leaves the original untouched.
    $component->call('saveDraft');

    expect(Cheque::query()->count())->toBe(2)
        ->and($source->fresh()->cheque_no)->toBe('1001');
});

it('lets a voided cheque be re-issued by copying it', function () {
    $source = chequeToCopy();
    app(ChequePoster::class)->post($source);
    app(ChequePoster::class)->void($source->fresh('lines'));

    expect($source->fresh()->status)->toBe(ChequeStatus::Void);

    Livewire::withQueryParams(['from' => $source->id])
        ->test('pages::cheques.form', ['company' => $this->company])
        ->assertSet('payee_name', 'Acme Services');
});

it('ignores a source cheque belonging to another company', function () {
    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    $foreign = chequeToCopy();
    app()->instance('current_company', $this->company);

    Livewire::withQueryParams(['from' => $foreign->id])
        ->test('pages::cheques.form', ['company' => $this->company])
        ->assertSet('payee_name', '')
        ->assertSet('memo', '');
});

it('offers a duplicate link on the cheque list and the cheque page', function () {
    $source = chequeToCopy();

    $expected = route('cheques.create', ['company' => $this->company->slug, 'from' => $source->id]);

    Livewire::test('pages::cheques.index', ['company' => $this->company])
        ->assertSeeHtml($expected);

    Livewire::test('pages::cheques.show', ['company' => $this->company, 'cheque' => $source])
        ->assertSeeHtml($expected);
});
