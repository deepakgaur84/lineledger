<?php

use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceState;
use App\Models\User;
use App\Support\Navigation\SidebarNavCatalog;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    app()->forgetInstance('current_company');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->companyAs = function (CompanyRole $role): Company {
        $company = Company::factory()->create();
        $company->members()->attach($this->user, ['role' => $role->value]);
        app()->instance('current_company', $company);

        return $company;
    };
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

$routes = [
    'opening-balances.index',
    'opening-balances.trial-balance',
    'opening-balances.receivables',
    'opening-balances.payables',
    'opening-balances.cheques',
    'opening-balances.deposits',
];

it('lets the owner reach every workspace page', function () use ($routes) {
    $company = ($this->companyAs)(CompanyRole::Owner);

    foreach ($routes as $route) {
        $this->get(route($route, ['company' => $company->slug]))->assertOk();
    }
});

it('refuses every non-owner role on every workspace page', function () use ($routes) {
    foreach ([CompanyRole::Admin, CompanyRole::Accountant, CompanyRole::Custom] as $role) {
        $company = ($this->companyAs)($role);

        foreach ($routes as $route) {
            $this->get(route($route, ['company' => $company->slug]))->assertForbidden();
        }

        $this->get(route('opening-balances.template', ['company' => $company->slug, 'step' => 'trial_balance']))
            ->assertForbidden();
    }
});

it('refuses a non-member mounting the component directly', function () {
    $victim = Company::factory()->create();

    Livewire::test('pages::opening-balances.index', ['company' => $victim])
        ->assertForbidden();
});

it('creates the workspace state with a default as-of date on first visit', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);

    expect(OpeningBalanceState::for($company))->toBeNull();

    Livewire::test('pages::opening-balances.index', ['company' => $company])->assertOk();

    $state = OpeningBalanceState::for($company);
    expect($state)->not->toBeNull();
    expect($state->as_of_date->toDateString())
        ->toBe(OpeningBalanceState::defaultAsOfDate($company)->toDateString());
});

it('saves a trial balance cell and applies it to the books immediately', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);

    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);
    $bank = Account::query()->where('code', '1000')->firstOrFail();

    $page = Livewire::test('pages::opening-balances.trial-balance', ['company' => $company])
        ->set('newAccountId', $bank->id)
        ->set('newDebit', '1,000.00')
        ->call('addRow')
        ->assertHasNoErrors();

    $state = OpeningBalanceState::for($company)->refresh();
    expect($state->rows()->count())->toBe(1);
    expect($state->journal_entry_id)->not->toBeNull();

    $entry = JournalEntry::withoutGlobalScopes()->find($state->journal_entry_id);
    expect($entry->lines->firstWhere('account_id', $bank->id)->debit_cents)->toBe(100000);

    // Edit the cell — the same entry reposts to the new figure.
    $page->set('d.'.$bank->id, '750.00');

    expect($entry->refresh()->lines()->where('account_id', $bank->id)->first()->debit_cents)->toBe(75000);
});

it('saves a customer balance typed on the receivables grid', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);
    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);
    $customer = Contact::factory()->customer()->create(['company_id' => $company->id]);

    $page = Livewire::test('pages::opening-balances.receivables', ['company' => $company])
        ->set('bal.'.$customer->id, '1,000.00');

    $invoice = Invoice::query()
        ->where('contact_id', $customer->id)
        ->where('is_opening_balance', true)
        ->first();

    expect($invoice)->not->toBeNull();
    expect((int) $invoice->total_cents)->toBe(100000);

    // Plain integers and negatives (credits) work too.
    $page->set('bal.'.$customer->id, '750');
    expect((int) $invoice->fresh()->total_cents)->toBe(75000);

    $page->set('bal.'.$customer->id, '-25.50');
    expect($invoice->fresh()->voided_at)->not->toBeNull();
    expect((int) CreditMemo::query()->where('contact_id', $customer->id)->whereNull('voided_at')->value('total_cents'))->toBe(2550);
});

it('names the opening document before the balance is typed', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);
    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);
    $customer = Contact::factory()->customer()->create(['company_id' => $company->id]);

    Livewire::test('pages::opening-balances.receivables', ['company' => $company])
        // Document number first — nothing exists to rename yet, so it is held.
        ->set('doc.'.$customer->id, 'INV 27/10020')
        ->assertSet('doc.'.$customer->id, 'INV 27/10020')
        // …then the balance, which creates the document under that number.
        ->set('bal.'.$customer->id, '1,000.00');

    $invoice = Invoice::query()->where('contact_id', $customer->id)->where('is_opening_balance', true)->firstOrFail();

    expect($invoice->invoice_no)->toBe('INV 27/10020');
    expect((int) $invoice->total_cents)->toBe(100000);
});

it('renumbers an opening document already on the receivables grid', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);
    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);
    $customer = Contact::factory()->customer()->create(['company_id' => $company->id]);

    $page = Livewire::test('pages::opening-balances.receivables', ['company' => $company])
        ->set('bal.'.$customer->id, '500.00');

    $invoice = Invoice::query()->where('contact_id', $customer->id)->firstOrFail();
    $entryId = $invoice->journal_entry_id;

    $page->set('doc.'.$customer->id, 'INV 27/10020');

    expect($invoice->fresh()->invoice_no)->toBe('INV 27/10020');
    expect($invoice->fresh()->journal_entry_id)->toBe($entryId);
});

it('does not auto-generate a colliding number for a company with imported invoices', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);
    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);

    // The back catalogue, imported so the newest row by id is not the highest.
    foreach (['INV 27/10052', 'INV 27/10020', 'INV 27/10019'] as $number) {
        Invoice::create([
            'contact_id' => Contact::factory()->customer()->create(['company_id' => $company->id])->id,
            'invoice_no' => $number,
            'invoice_date' => '2026-06-30',
            'due_date' => '2026-06-30',
            'status' => InvoiceStatus::Draft,
        ]);
    }

    $customer = Contact::factory()->customer()->create(['company_id' => $company->id]);

    Livewire::test('pages::opening-balances.receivables', ['company' => $company])
        ->set('bal.'.$customer->id, '100.00');

    $invoice = Invoice::query()
        ->where('contact_id', $customer->id)
        ->where('is_opening_balance', true)
        ->firstOrFail();

    expect($invoice->invoice_no)->toBe('INV 27/10053');
});

it('refuses a document number another document already owns', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);
    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);
    $first = Contact::factory()->customer()->create(['company_id' => $company->id]);
    $second = Contact::factory()->customer()->create(['company_id' => $company->id]);

    $page = Livewire::test('pages::opening-balances.receivables', ['company' => $company])
        ->set('doc.'.$first->id, 'INV 27/10020')
        ->set('bal.'.$first->id, '100.00')
        ->set('bal.'.$second->id, '200.00');

    $secondInvoice = Invoice::query()->where('contact_id', $second->id)->firstOrFail();
    $before = $secondInvoice->invoice_no;

    $page->set('doc.'.$second->id, 'INV 27/10020');

    expect($secondInvoice->fresh()->invoice_no)->toBe($before);
});

it('names the opening bill before the balance is typed', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);
    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);
    $vendor = Contact::factory()->vendor()->create(['company_id' => $company->id]);

    Livewire::test('pages::opening-balances.payables', ['company' => $company])
        ->set('doc.'.$vendor->id, 'BILL-88213')
        ->set('bal.'.$vendor->id, '425.00');

    expect(Bill::query()->where('contact_id', $vendor->id)->value('bill_no'))->toBe('BILL-88213');
});

it('imports opening customer balances with their document numbers', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);
    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);
    Contact::factory()->customer()->create(['company_id' => $company->id, 'display_name' => 'Acme Landscaping']);

    $csv = "customer_display_name,document_no,balance\nAcme Landscaping,INV 27/10020,1250.00\n";

    Livewire::test('pages::opening-balances.receivables', ['company' => $company])
        ->set('importFile', UploadedFile::fake()->createWithContent('ar.csv', $csv))
        ->call('previewImport')
        ->assertHasNoErrors()
        ->call('runImport');

    expect(Invoice::query()->where('is_opening_balance', true)->value('invoice_no'))->toBe('INV 27/10020');
});

it('still imports opening customer balances without the optional column', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);
    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);
    Contact::factory()->customer()->create(['company_id' => $company->id, 'display_name' => 'Acme Landscaping']);

    $csv = "customer_display_name,balance\nAcme Landscaping,1250.00\n";

    Livewire::test('pages::opening-balances.receivables', ['company' => $company])
        ->set('importFile', UploadedFile::fake()->createWithContent('ar.csv', $csv))
        ->call('previewImport')
        ->assertHasNoErrors()
        ->call('runImport');

    expect((int) Invoice::query()->where('is_opening_balance', true)->value('total_cents'))->toBe(125000);
});

it('saves a vendor balance typed on the payables grid', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);
    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);
    $vendor = Contact::factory()->vendor()->create(['company_id' => $company->id]);

    Livewire::test('pages::opening-balances.payables', ['company' => $company])
        ->set('bal.'.$vendor->id, '425.00');

    expect((int) Bill::query()->where('contact_id', $vendor->id)->where('is_opening_balance', true)->value('total_cents'))->toBe(42500);
});

it('imports a trial balance CSV through the page and applies it', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);
    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);

    $csv = "account_code,debit,credit\n1000,1250.00,\n1300,180.00,\n2700,,1430.00\n";

    Livewire::test('pages::opening-balances.trial-balance', ['company' => $company])
        ->set('importFile', UploadedFile::fake()->createWithContent('tb.csv', $csv))
        ->call('previewImport')
        ->assertHasNoErrors()
        ->call('runImport');

    $state = OpeningBalanceState::for($company)->refresh();
    expect($state->rows()->count())->toBe(3);
    expect($state->journal_entry_id)->not->toBeNull();

    $entry = JournalEntry::withoutGlobalScopes()->find($state->journal_entry_id);
    expect($entry->totalDebitsCents())->toBe(143000);
    expect($entry->isBalanced())->toBeTrue();
});

it('streams a CSV template for every workspace step', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);

    foreach (['trial_balance', 'customer_balances', 'vendor_balances', 'outstanding_cheques', 'deposits_in_transit', 'inventory', 'fixed_assets'] as $step) {
        $this->get(route('opening-balances.template', ['company' => $company->slug, 'step' => $step]))
            ->assertOk();
    }

    $this->get(route('opening-balances.template', ['company' => $company->slug, 'step' => 'nope']))
        ->assertNotFound();
});

it('shows the sidebar item to owners only', function () {
    $owned = ($this->companyAs)(CompanyRole::Owner);
    $keys = array_keys(SidebarNavCatalog::flattenKeys($owned, $this->user));
    expect($keys)->toContain('accounting.opening_balances');

    $accountantCompany = ($this->companyAs)(CompanyRole::Accountant);
    $keys = array_keys(SidebarNavCatalog::flattenKeys($accountantCompany, $this->user));
    expect($keys)->not->toContain('accounting.opening_balances');
});

it('blocks edits once finalized and reopens after un-finalize', function () {
    $company = ($this->companyAs)(CompanyRole::Owner);
    OpeningBalanceState::create(['company_id' => $company->id, 'as_of_date' => '2026-06-30']);
    $bank = Account::query()->where('code', '1000')->firstOrFail();

    Livewire::test('pages::opening-balances.index', ['company' => $company])
        ->call('finalize');

    $state = OpeningBalanceState::for($company)->refresh();
    expect($state->isFinalized())->toBeTrue();
    expect($company->refresh()->lock_date->toDateString())->toBe('2026-06-30');

    // A grid save is refused while finalized (no row lands).
    Livewire::test('pages::opening-balances.trial-balance', ['company' => $company])
        ->set('newAccountId', $bank->id)
        ->set('newDebit', '10.00')
        ->call('addRow');

    expect($state->rows()->count())->toBe(0);

    Livewire::test('pages::opening-balances.index', ['company' => $company])
        ->call('unfinalize');

    expect($state->refresh()->isFinalized())->toBeFalse();
    expect($company->refresh()->lock_date)->toBeNull();
});
