<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\TaxCalculator;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Cross-tenant READS through ids a client can set on a Livewire component.
 *
 * A public Livewire property is client-writable unless it is #[Locked], and
 * journal_lines (and the other document line tables) carry no company_id, so
 * CompanyScope cannot protect a query keyed by a client-held id there. Each
 * case below is a member of organization A pointing a component at an
 * organization B id; nothing of B's may render or be returned.
 */
beforeEach(function () {
    $this->victim = Company::factory()->create();
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * A posted invoice for $company (its posting writes audit rows), built with
 * $company bound and left bound.
 */
function crossTenantReadPostedInvoice(Company $company, string $invoiceNo): Invoice
{
    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Customer '.$invoiceNo, 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => $invoiceNo,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $totals = app(TaxCalculator::class)->line('1', 123456);

    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 123456,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    return $invoice->refresh();
}

/**
 * Posts a bank deposit of $cents into $company's first bank account, against
 * equity, and returns that bank account. Leaves $company bound.
 */
function crossTenantReadFundedBank(Company $company, int $cents): Account
{
    app()->instance('current_company', $company);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $equity = Account::query()->where('type', AccountType::Equity->value)->orderBy('code')->firstOrFail();

    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-02-01',
        'memo' => 'Seed capital',
        'lines' => [
            ['account_id' => $bank->id, 'debit_cents' => $cents, 'credit_cents' => 0],
            ['account_id' => $equity->id, 'debit_cents' => 0, 'credit_cents' => $cents],
        ],
    ]);
    app(JournalPoster::class)->post($entry);

    return $bank;
}

// ───────────────────────── Audit trail (journal entry page) ─────────────────────────

it('refuses a client-set audit trail target', function () {
    $foreign = crossTenantReadPostedInvoice($this->victim, 'B-SECRET-1');
    $own = crossTenantReadPostedInvoice($this->company, 'A-1');
    $this->actingAs($this->user);

    $trail = Livewire::test('audit-trail', [
        'auditableType' => (new JournalEntry)->getMorphClass(),
        'auditableId' => $own->journal_entry_id,
    ]);

    // Baseline: the organization's own trail shows only its own rows.
    expect($trail->instance()->entries()->pluck('company_id')->unique()->values()->all())
        ->toBe([$this->company->id]);

    expect(fn () => $trail->set('auditableId', $foreign->journal_entry_id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => $trail->set('auditableType', Invoice::class))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('keeps the audit trail to the organization even if its target is swapped server-side', function () {
    $foreign = crossTenantReadPostedInvoice($this->victim, 'B-SECRET-2');
    $own = crossTenantReadPostedInvoice($this->company, 'A-2');
    $this->actingAs($this->user);

    $trail = Livewire::test('audit-trail', [
        'auditableType' => (new JournalEntry)->getMorphClass(),
        'auditableId' => $own->journal_entry_id,
    ])->instance();

    // Even with the lock bypassed and nothing bound — as on a Livewire request
    // that skips the HTTP middleware — the query is constrained to the company.
    $trail->auditableId = $foreign->journal_entry_id;
    app()->forgetInstance('current_company');

    expect($trail->entries())->toBeEmpty();
});

it('will not open the audit trail for another organization\'s record', function () {
    $foreign = crossTenantReadPostedInvoice($this->victim, 'B-SECRET-3');
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    Livewire::test('audit-trail', [
        'auditableType' => (new JournalEntry)->getMorphClass(),
        'auditableId' => $foreign->journal_entry_id,
    ])->assertNotFound();
});

it('re-binds its organization on the audit trail\'s own Livewire requests', function () {
    $own = crossTenantReadPostedInvoice($this->company, 'A-4');
    $this->actingAs($this->user);

    $trail = Livewire::test('audit-trail', [
        'auditableType' => (new JournalEntry)->getMorphClass(),
        'auditableId' => $own->journal_entry_id,
    ]);

    app()->forgetInstance('current_company');
    $trail->call('$refresh');

    expect(app()->bound('current_company'))->toBeTrue()
        ->and(app('current_company')->id)->toBe($this->company->id);
});

// ───────────────────────── Bank register ─────────────────────────

it('does not render another organization\'s bank balances in the register', function (CompanyRole $role, int $page) {
    $foreignBank = crossTenantReadFundedBank($this->victim, 98765432);
    app()->forgetInstance('current_company');

    $member = User::factory()->create();
    $this->company->members()->attach($member, ['role' => $role->value]);

    $response = $this->actingAs($member)->get(route('banking.register', [
        'company' => $this->company->slug,
        'account' => $foreignBank->id,
        'page' => $page,
    ]));

    $response->assertNotFound();
    expect((string) $response->getContent())->not->toContain('987,654.32');
})->with([
    'owner, empty page 2' => [CompanyRole::Owner, 2],
    'accountant, out-of-range page' => [CompanyRole::Accountant, 999],
]);

it('refuses another organization\'s account picked in the register', function () {
    $foreignBank = crossTenantReadFundedBank($this->victim, 7654321);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $register = Livewire::test('pages::banking.register', ['company' => $this->company]);
    $ownAccountId = $register->get('account_id');

    $register->set('account_id', $foreignBank->id)->assertNotFound();

    // The balance queries key on the account resolved through the
    // organization, so even a foreign id forced past the update hook reads nothing.
    $instance = Livewire::test('pages::banking.register', ['company' => $this->company])->instance();
    $instance->account_id = $foreignBank->id;
    unset($instance->account);

    expect($instance->ledgerBalanceCents())->toBe(0)
        ->and($instance->clearedBalanceCents())->toBe(0)
        ->and($instance->lines()->total())->toBe(0)
        ->and($ownAccountId)->not->toBe($foreignBank->id);
});

it('still opens the register on the organization\'s own account', function () {
    $ownBank = crossTenantReadFundedBank($this->company, 1234500);
    app()->forgetInstance('current_company');

    $this->actingAs($this->user)
        ->get(route('banking.register', ['company' => $this->company->slug, 'account' => $ownBank->id]))
        ->assertOk()
        ->assertSee('12,345.00');
});

// ───────────────────────── Merge dialogs ─────────────────────────

it('does not summarize another organization\'s account in the merge dialog', function () {
    app()->instance('current_company', $this->victim);
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $foreignIncome = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->firstOrFail();
    foreach (range(1, 7) as $day) {
        app(SaveJournalEntry::class)->handle([
            'entry_date' => '2026-03-0'.$day,
            'lines' => [
                ['account_id' => $bank->id, 'debit_cents' => 100, 'credit_cents' => 0],
                ['account_id' => $foreignIncome->id, 'debit_cents' => 0, 'credit_cents' => 100],
            ],
        ]);
    }

    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $page = Livewire::test('pages::accounts.index', ['company' => $this->company]);

    expect(fn () => $page->set('mergingId', $foreignIncome->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $instance = $page->instance();
    $instance->mergingId = $foreignIncome->id;

    expect($instance->mergeSummary())->toBe('')
        ->and($instance->mergeTargets())->toBeEmpty();
});

it('does not summarize another organization\'s customer in the merge dialog', function () {
    app()->instance('current_company', $this->victim);
    $customer = Contact::create(['display_name' => 'Victim Customer', 'is_customer' => true]);
    foreach (['B-INV-1', 'B-INV-2', 'B-INV-3'] as $invoiceNo) {
        Invoice::create([
            'contact_id' => $customer->id,
            'invoice_no' => $invoiceNo,
            'invoice_date' => '2026-03-10',
            'due_date' => '2026-04-10',
        ]);
    }

    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $page = Livewire::test('pages::customers.index', ['company' => $this->company]);

    expect(fn () => $page->set('mergingId', $customer->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $instance = $page->instance();
    $instance->mergingId = $customer->id;

    expect($instance->mergeSummary())->toBe('')
        ->and($instance->mergeTargets())->toBeEmpty();
});

it('does not summarize another organization\'s vendor in the merge dialog', function () {
    app()->instance('current_company', $this->victim);
    $vendor = Contact::create(['display_name' => 'Victim Supplier', 'is_vendor' => true]);
    foreach (['B-BILL-1', 'B-BILL-2', 'B-BILL-3'] as $billNo) {
        Bill::create([
            'contact_id' => $vendor->id,
            'bill_type' => BillType::Vendor,
            'bill_no' => $billNo,
            'bill_date' => '2026-03-10',
            'due_date' => '2026-04-10',
        ]);
    }

    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $page = Livewire::test('pages::vendors.index', ['company' => $this->company]);

    expect(fn () => $page->set('mergingId', $vendor->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $instance = $page->instance();
    $instance->mergingId = $vendor->id;

    expect($instance->mergeSummary())->toBe('')
        ->and($instance->mergeTargets())->toBeEmpty();
});

it('still summarizes the organization\'s own account in the merge dialog', function () {
    app()->instance('current_company', $this->company);
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $income = Account::query()->where('type', AccountType::Income->value)->where('is_system', false)->orderBy('code')->firstOrFail();
    foreach (range(1, 3) as $day) {
        app(SaveJournalEntry::class)->handle([
            'entry_date' => '2026-03-0'.$day,
            'lines' => [
                ['account_id' => $bank->id, 'debit_cents' => 100, 'credit_cents' => 0],
                ['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 100],
            ],
        ]);
    }
    $this->actingAs($this->user);

    $summary = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openMerge', $income->id)
        ->instance()
        ->mergeSummary();

    expect($summary)->toContain('3 journal line(s)');
});

// ───────────────────────── Fixed-asset source prefill (sweep) ─────────────────────────

it('does not prefill a new asset from another organization\'s transaction line', function (string $sourceType) {
    app()->instance('current_company', $this->victim);
    $fixedAsset = Account::query()->where('subtype', AccountSubtype::FixedAsset->value)->orderBy('code')->firstOrFail();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();

    $lineId = match ($sourceType) {
        'bill_line' => Bill::create([
            'contact_id' => Contact::create(['display_name' => 'Victim Vendor', 'is_vendor' => true])->id,
            'bill_type' => BillType::Vendor,
            'bill_no' => 'B-AST-1',
            'bill_date' => '2026-03-10',
            'due_date' => '2026-04-10',
        ])->lines()->create([
            'account_id' => $fixedAsset->id,
            'description' => 'Victim secret server',
            'quantity' => '1',
            'unit_price_cents' => 4321000,
            'line_subtotal_cents' => 4321000,
            'line_tax_cents' => 0,
            'line_total_cents' => 4321000,
            'line_order' => 0,
        ])->id,
        'cheque_line' => Cheque::create([
            'bank_account_id' => $bank->id,
            'cheque_no' => 'B-CHQ-1',
            'cheque_date' => '2026-04-15',
            'payee_name' => 'Victim Payee',
        ])->lines()->create([
            'account_id' => $fixedAsset->id,
            'description' => 'Victim secret server',
            'amount_cents' => 4321000,
            'tax_cents' => 0,
            'line_order' => 0,
        ])->id,
        'journal_line' => app(SaveJournalEntry::class)->handle([
            'entry_date' => '2026-03-10',
            'lines' => [
                ['account_id' => $fixedAsset->id, 'debit_cents' => 4321000, 'credit_cents' => 0, 'memo' => 'Victim secret server'],
                ['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 4321000],
            ],
        ])->lines()->where('account_id', $fixedAsset->id)->value('id'),
    };

    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    Livewire::withQueryParams(['source_type' => $sourceType, 'source_id' => $lineId])
        ->test('pages::assets.form', ['company' => $this->company])
        ->assertSet('name', '')
        ->assertSet('description', '')
        ->assertSet('asset_account_id', null)
        ->assertSet('cost', '0.00')
        ->assertSet('source_type', null)
        ->assertSet('source_id', null)
        ->assertDontSee('Victim secret server');
})->with(['bill_line', 'cheque_line', 'journal_line']);
