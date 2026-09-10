<?php

use App\Actions\Sales\PostCustomerOpeningBalance;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\OpeningBalanceState;
use App\Models\VendorCredit;
use App\Services\OpeningBalances\CustomerOpeningBalanceSync;
use App\Services\OpeningBalances\VendorOpeningBalanceSync;
use App\Services\Reporting\OpenDocumentAgingBuilder;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->state = OpeningBalanceState::create([
        'company_id' => $this->company->id,
        'as_of_date' => '2026-06-30',
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function arSync(): CustomerOpeningBalanceSync
{
    return app(CustomerOpeningBalanceSync::class);
}

function apSync(): VendorOpeningBalanceSync
{
    return app(VendorOpeningBalanceSync::class);
}

it('posts, edits in place, flips sign and zeroes a customer opening balance', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    // Post
    arSync()->set($this->state, $customer, 125000);

    $invoice = Invoice::query()->where('contact_id', $customer->id)->where('is_opening_balance', true)->firstOrFail();
    expect($invoice->total_cents)->toBe(125000);
    expect($invoice->invoice_date->toDateString())->toBe('2026-06-30');
    expect($invoice->journal_entry_id)->not->toBeNull();
    expect($customer->fresh()->ar_balance_cents)->toBe(125000);

    // Edit in place — the same invoice and journal entry, a new amount.
    arSync()->set($this->state, $customer, 110000);

    $edited = $invoice->fresh();
    expect($edited->total_cents)->toBe(110000);
    expect($edited->journal_entry_id)->toBe($invoice->journal_entry_id);
    expect($customer->fresh()->ar_balance_cents)->toBe(110000);

    // Sign flip — invoice voided, credit memo created.
    arSync()->set($this->state, $customer, -20000);

    expect($invoice->fresh()->voided_at)->not->toBeNull();
    $memo = CreditMemo::query()->where('contact_id', $customer->id)->where('is_opening_balance', true)->whereNull('voided_at')->firstOrFail();
    expect($memo->total_cents)->toBe(20000);
    expect($customer->fresh()->ar_balance_cents)->toBe(-20000);

    // Zero — everything voided.
    arSync()->set($this->state, $customer, 0);

    expect($memo->fresh()->voided_at)->not->toBeNull();
    expect($customer->fresh()->ar_balance_cents)->toBe(0);
    expect(arSync()->currentFor($customer)['net'])->toBe(0);
});

it('ties the AR aging grand total to the control account with no unattributed row', function () {
    $alice = Contact::factory()->customer()->create(['company_id' => $this->company->id, 'display_name' => 'Alice Ltd']);
    $bob = Contact::factory()->customer()->create(['company_id' => $this->company->id, 'display_name' => 'Bob Inc']);

    arSync()->set($this->state, $alice, 100000);
    arSync()->set($this->state, $bob, -25000);

    $asOf = CarbonImmutable::parse('2026-06-30');
    $summary = app(OpenDocumentAgingBuilder::class)->summary($this->company, 'ar', $asOf, false);

    expect($summary['totals']['total'])->toBe(75000);

    $arAccount = Account::query()->where('code', '1100')->firstOrFail();
    expect(app(ReportCalculator::class)->balanceAsOf($arAccount, $asOf))->toBe(75000);

    $names = array_column($summary['rows'], 'name');
    expect($names)->toContain('Alice Ltd');
    expect(implode('|', $names))->not->toContain('no customer');
});

it('posts, edits in place, flips sign and zeroes a vendor opening balance', function () {
    $vendor = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    apSync()->set($this->state, $vendor, 80000);

    $bill = Bill::query()->where('contact_id', $vendor->id)->where('is_opening_balance', true)->firstOrFail();
    expect($bill->total_cents)->toBe(80000);
    expect(str_starts_with($bill->bill_no, 'OB-'))->toBeTrue();
    expect($vendor->fresh()->ap_balance_cents)->toBe(80000);

    apSync()->set($this->state, $vendor, 60000);

    expect($bill->fresh()->total_cents)->toBe(60000);
    expect($bill->fresh()->journal_entry_id)->toBe($bill->journal_entry_id);

    apSync()->set($this->state, $vendor, -10000);

    expect($bill->fresh()->voided_at)->not->toBeNull();
    $credit = VendorCredit::query()->where('contact_id', $vendor->id)->where('is_opening_balance', true)->whereNull('voided_at')->firstOrFail();
    expect($credit->total_cents)->toBe(10000);
    expect($vendor->fresh()->ap_balance_cents)->toBe(-10000);

    apSync()->set($this->state, $vendor, 0);
    expect($credit->fresh()->voided_at)->not->toBeNull();
    expect($vendor->fresh()->ap_balance_cents)->toBe(0);
});

it('ties the AP aging grand total to the control account', function () {
    $vendor = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    apSync()->set($this->state, $vendor, 43210);

    $asOf = CarbonImmutable::parse('2026-06-30');
    $summary = app(OpenDocumentAgingBuilder::class)->summary($this->company, 'ap', $asOf, false);

    expect($summary['totals']['total'])->toBe(43210);

    $apAccount = Account::query()->where('code', '2000')->firstOrFail();
    expect(app(ReportCalculator::class)->balanceAsOf($apAccount, $asOf))->toBe(43210);
});

it('consolidates multiple opening documents into one', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    // Two docs exist (e.g. one from the customers page, one from a CSV).
    arSync()->set($this->state, $customer, 40000);
    $first = Invoice::query()->where('contact_id', $customer->id)->firstOrFail();

    Invoice::query()->whereKey($first->id)->update(['total_cents' => 40000]); // untouched
    app(PostCustomerOpeningBalance::class)->handle($customer, 10000, CarbonImmutable::parse('2026-06-30'));

    expect(arSync()->currentFor($customer)['net'])->toBe(50000);

    // Setting a new figure voids both and posts one clean document.
    arSync()->set($this->state, $customer, 45000);

    $live = Invoice::query()->where('contact_id', $customer->id)->where('is_opening_balance', true)->whereNull('voided_at')->get();
    expect($live)->toHaveCount(1);
    expect((int) $live->first()->total_cents)->toBe(45000);
    expect($customer->fresh()->ar_balance_cents)->toBe(45000);
});

it('refuses to rewrite an opening invoice that already has payments applied', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    arSync()->set($this->state, $customer, 30000);

    Invoice::query()->where('contact_id', $customer->id)->update(['amount_paid_cents' => 5000]);

    expect(fn () => arSync()->set($this->state, $customer, 20000))
        ->toThrow(RuntimeException::class, 'payments applied');
});

it('names the opening invoice with the number the operator supplies', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    arSync()->set($this->state, $customer, 125000, 'INV 27/10020');

    $invoice = Invoice::query()->where('contact_id', $customer->id)->firstOrFail();
    expect($invoice->invoice_no)->toBe('INV 27/10020');
    expect($invoice->journalEntry->memo)->toContain('INV 27/10020');
});

it('renumbers an existing opening invoice and reposts its journal memo', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    arSync()->set($this->state, $customer, 125000);
    $invoice = Invoice::query()->where('contact_id', $customer->id)->firstOrFail();
    $entryId = $invoice->journal_entry_id;

    expect(arSync()->renameDocument($customer, 'INV 27/10020'))->toBeTrue();

    $renamed = $invoice->fresh();
    expect($renamed->invoice_no)->toBe('INV 27/10020');
    expect($renamed->journal_entry_id)->toBe($entryId);
    expect($renamed->journalEntry->memo)->toContain('INV 27/10020');
    expect(arSync()->documentNumberFor($customer))->toBe('INV 27/10020');
});

it('reports the number back through documentNumberFor for a credit memo', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    arSync()->set($this->state, $customer, -2550, 'CM-2027-004');

    expect(arSync()->documentNumberFor($customer))->toBe('CM-2027-004');
    expect(CreditMemo::query()->where('contact_id', $customer->id)->value('credit_memo_no'))->toBe('CM-2027-004');
});

it('refuses a document number another document already owns', function () {
    $first = Contact::factory()->customer()->create(['company_id' => $this->company->id]);
    $second = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    arSync()->set($this->state, $first, 10000, 'INV 27/10020');

    expect(fn () => arSync()->set($this->state, $second, 20000, 'INV 27/10020'))
        ->toThrow(RuntimeException::class, 'already in use');

    // …and a rename onto a taken number is refused just as loudly.
    arSync()->set($this->state, $second, 20000, 'INV 27/10021');

    expect(fn () => arSync()->renameDocument($second, 'INV 27/10020'))
        ->toThrow(RuntimeException::class, 'already in use');

    expect(Invoice::query()->where('contact_id', $second->id)->value('invoice_no'))->toBe('INV 27/10021');
});

it('keeps the supplied number when the balance is edited afterwards', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    arSync()->set($this->state, $customer, 125000, 'INV 27/10020');
    arSync()->set($this->state, $customer, 110000, 'INV 27/10020');

    $invoice = Invoice::query()->where('contact_id', $customer->id)->firstOrFail();
    expect($invoice->invoice_no)->toBe('INV 27/10020');
    expect((int) $invoice->total_cents)->toBe(110000);
});

it('renames a vendor opening bill and honours a supplied bill number', function () {
    $vendor = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    apSync()->set($this->state, $vendor, 42500, 'BILL-88213');

    $bill = Bill::query()->where('contact_id', $vendor->id)->firstOrFail();
    expect($bill->bill_no)->toBe('BILL-88213');
    expect(apSync()->documentNumberFor($vendor))->toBe('BILL-88213');

    expect(apSync()->renameDocument($vendor, 'BILL-88999'))->toBeTrue();
    expect($bill->fresh()->bill_no)->toBe('BILL-88999');
    expect($bill->fresh()->journalEntry->memo)->toContain('BILL-88999');
});

it('holds off renaming when the customer has no opening document yet', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    expect(arSync()->renameDocument($customer, 'INV 27/10020'))->toBeFalse();
    expect(arSync()->documentNumberFor($customer))->toBeNull();
});

it('rejects a document number longer than the column allows', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    expect(fn () => arSync()->set($this->state, $customer, 10000, str_repeat('X', 41)))
        ->toThrow(RuntimeException::class, 'too long');
});
