<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Posting\ChequePoster;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A cheque line coded to Accounts Receivable / Payable carries its own customer
 * or vendor: the payee is who the cheque is made out to, which is often not
 * whose balance it settles. `journal_lines.contact_id` is the AR/AP sub-ledger,
 * so this is what the aging reports, statements and cached contact balances read.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->orderBy('code')->firstOrFail();
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function chequeLineContactForm(): Testable
{
    return Livewire::test('pages::cheques.form', ['company' => test()->company]);
}

/**
 * The posted GL legs of the only cheque, as [account_id => contact_id].
 *
 * @return array<int, ?int>
 */
function chequeLegContacts(): array
{
    return JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_type', Cheque::class))
        ->get()
        ->mapWithKeys(fn (JournalLine $l) => [(int) $l->account_id => $l->contact_id === null ? null : (int) $l->contact_id])
        ->all();
}

it('scopes line contact options to the role the account requires and the search query', function () {
    $acme = Contact::factory()->customer()->create(['company_id' => $this->company->id, 'display_name' => 'Acme Co']);
    $globex = Contact::factory()->customer()->create(['company_id' => $this->company->id, 'display_name' => 'Globex']);
    $vendor = Contact::factory()->vendor()->create(['company_id' => $this->company->id, 'display_name' => 'Acme Supplies']);

    $component = chequeLineContactForm()
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.contact_query', 'Acme');

    $ids = $component->instance()->lineContactOptions(0)->pluck('id')->all();

    expect($ids)->toContain($acme->id)
        ->not->toContain($globex->id)
        ->not->toContain($vendor->id);
});

it('offers no line contact options on a non-AR/AP account', function () {
    $component = chequeLineContactForm()->set('lines.0.account_id', $this->expense->id);

    expect($component->instance()->lineContactOptions(0))->toBeEmpty();
});

it('blocks posting an Accounts Receivable line without a customer', function () {
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.amount', '100.00')
        ->call('postCheque')
        ->assertHasErrors('lines.0.contact_id');

    expect(Cheque::query()->count())->toBe(0);
});

it('blocks posting an Accounts Payable line without a vendor', function () {
    $payee = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->ap->id)
        ->set('lines.0.amount', '100.00')
        ->call('postCheque')
        ->assertHasErrors('lines.0.contact_id');
});

it('rejects a vendor on an Accounts Receivable line', function () {
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);
    $vendor = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.contact_id', $vendor->id)
        ->set('lines.0.amount', '100.00')
        ->call('postCheque')
        ->assertHasErrors('lines.0.contact_id');
});

it('saves a draft without demanding the Accounts Receivable customer', function () {
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.amount', '100.00')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Cheque::query()->count())->toBe(1);
});

it('stamps the line customer on the AR leg and the payee on the bank leg', function () {
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id, 'display_name' => 'Gina Pichaloff']);
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id, 'display_name' => 'Lionel Thibault']);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.contact_id', $customer->id)
        ->set('lines.0.amount', '1550.06')
        ->call('postCheque')
        ->assertHasNoErrors();

    $legs = chequeLegContacts();

    expect($legs[$this->ar->id])->toBe($customer->id)
        ->and($legs[$this->bank->id])->toBe($payee->id)
        ->and($customer->fresh()->ar_balance_cents)->toBe(155006);
});

it('keeps two Accounts Receivable customers on separate legs', function () {
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);
    $first = Contact::factory()->customer()->create(['company_id' => $this->company->id]);
    $second = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.contact_id', $first->id)
        ->set('lines.0.amount', '60.00')
        ->call('addLine')
        ->set('lines.1.account_id', $this->ar->id)
        ->set('lines.1.contact_id', $second->id)
        ->set('lines.1.amount', '40.00')
        ->call('postCheque')
        ->assertHasNoErrors();

    $arLegs = JournalLine::query()->where('account_id', $this->ar->id)->get();

    expect($arLegs)->toHaveCount(2)
        ->and($arLegs->pluck('contact_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        ->and($first->fresh()->ar_balance_cents)->toBe(6000)
        ->and($second->fresh()->ar_balance_cents)->toBe(4000);
});

it('collapses an explicit payee line and a fallback line onto one leg', function () {
    // Coding one line to the payee by hand and leaving the other to fall back must
    // not split the entry into two rows identical in every posted column.
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.amount', '60.00')
        ->call('addLine')
        ->set('lines.1.account_id', $this->expense->id)
        ->set('lines.1.amount', '40.00')
        ->call('postCheque')
        ->assertHasNoErrors();

    $legs = JournalLine::query()->where('account_id', $this->expense->id)->get();

    expect($legs)->toHaveCount(1)
        ->and((int) $legs->first()->debit_cents)->toBe(10000)
        ->and((int) $legs->first()->contact_id)->toBe($payee->id);
});

it('moves the vendor balance on an Accounts Payable line', function () {
    $payee = Contact::factory()->customer()->create(['company_id' => $this->company->id]);
    $vendor = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->ap->id)
        ->set('lines.0.contact_id', $vendor->id)
        ->set('lines.0.amount', '250.00')
        ->call('postCheque')
        ->assertHasNoErrors();

    // AP is credit-normal; a cheque DEBITS it, so paying one down goes negative.
    expect($vendor->fresh()->ap_balance_cents)->toBe(-25000);
});

it('corrects both contacts when a repost swaps the customer', function () {
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);
    $before = Contact::factory()->customer()->create(['company_id' => $this->company->id]);
    $after = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.contact_id', $before->id)
        ->set('lines.0.amount', '100.00')
        ->call('postCheque')
        ->assertHasNoErrors();

    expect($before->fresh()->ar_balance_cents)->toBe(10000);

    $cheque = Cheque::query()->firstOrFail();

    Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque])
        ->set('lines.0.contact_id', $after->id)
        ->call('postCheque')
        ->assertHasNoErrors();

    expect($before->fresh()->ar_balance_cents)->toBe(0)
        ->and($after->fresh()->ar_balance_cents)->toBe(10000)
        ->and(chequeLegContacts()[$this->ar->id])->toBe($after->id);
});

it('returns the customer balance when the cheque is voided', function () {
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.contact_id', $customer->id)
        ->set('lines.0.amount', '100.00')
        ->call('postCheque')
        ->assertHasNoErrors();

    expect($customer->fresh()->ar_balance_cents)->toBe(10000);

    app(ChequePoster::class)->void(Cheque::query()->firstOrFail());

    expect($customer->fresh()->ar_balance_cents)->toBe(0);
});

it('pre-fills the line customer from a payee who is one, in either order', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    // Payee chosen first, then the account.
    chequeLineContactForm()
        ->set('payee_contact_id', $customer->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->assertSet('lines.0.contact_id', $customer->id);

    // Account coded first, then the payee.
    chequeLineContactForm()
        ->set('lines.0.account_id', $this->ar->id)
        ->assertSet('lines.0.contact_id', null)
        ->set('payee_contact_id', $customer->id)
        ->assertSet('lines.0.contact_id', $customer->id);
});

it('leaves the line customer blank when the payee does not hold the role', function () {
    $other = Contact::factory()->create([
        'company_id' => $this->company->id,
        'is_customer' => false,
        'is_vendor' => false,
        'is_other_name' => true,
    ]);

    chequeLineContactForm()
        ->set('payee_contact_id', $other->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->assertSet('lines.0.contact_id', null);
});

it('drops a stale contact when the line moves off an AR account', function () {
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.contact_id', $customer->id)
        ->set('lines.0.amount', '100.00')
        ->set('lines.0.account_id', $this->expense->id)
        ->assertSet('lines.0.contact_id', null)
        ->call('postCheque')
        ->assertHasNoErrors();

    expect(chequeLegContacts()[$this->expense->id])->toBe($payee->id);
});

it('still stamps the payee on every leg of an ordinary expense cheque', function () {
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.amount', '75.00')
        ->call('postCheque')
        ->assertHasNoErrors();

    expect(array_values(chequeLegContacts()))->each->toBe($payee->id);
});

it('creates a customer inline from the combo and posts with it', function () {
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    chequeLineContactForm()
        ->set('payee_contact_id', $payee->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.amount', '100.00')
        ->set('lines.0.contact_query', 'Brand New Client')
        ->call('startNewLineContact', 0)
        ->call('postCheque')
        ->assertHasNoErrors();

    $created = Contact::query()->where('display_name', 'Brand New Client')->firstOrFail();

    expect($created->is_customer)->toBeTrue()
        ->and(chequeLegContacts()[$this->ar->id])->toBe($created->id);
});
