<?php

use App\Actions\Banking\RecordStatementLine;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\User;
use App\Services\Banking\Import\OpenInvoiceMatcher;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
    $this->customer = Contact::factory()->customer()->create();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function depositLine(int $cents, ?int $contactId = null): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => test()->import->id,
        'account_id' => test()->bank->id,
        'txn_date' => now()->toDateString(),
        'amount_cents' => $cents,
        'description' => 'DEPOSIT '.$cents,
        'match_status' => StatementLineMatchStatus::Unmatched->value,
        'suggested_contact_id' => $contactId,
        'created_journal_entry_id' => null,
    ]);
}

it('offers the open invoice whose balance equals the deposit', function () {
    $invoice = overpaymentInvoice($this->customer, 115000);
    overpaymentInvoice($this->customer, 4000);

    $line = depositLine(115000, $this->customer->id);
    $matcher = app(OpenInvoiceMatcher::class);

    expect($matcher->forLine($line, $this->customer->id)?->id)->toBe($invoice->id)
        ->and($matcher->candidates(depositLine(-115000, $this->customer->id), $this->customer->id))->toHaveCount(0);
});

it('offers an exact-sum set when no single invoice matches', function () {
    $a = overpaymentInvoice($this->customer, 3000);
    $b = overpaymentInvoice($this->customer, 7000);
    overpaymentInvoice($this->customer, 99900);

    $set = app(OpenInvoiceMatcher::class)->allocationFor(depositLine(10000, $this->customer->id), $this->customer->id);

    expect(collect($set)->pluck('invoice_id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('records a customer receipt and settles the invoice when a deposit is accepted against it', function () {
    $invoice = overpaymentInvoice($this->customer, 115000);
    $line = depositLine(115000, $this->customer->id);

    $entry = app(RecordStatementLine::class)->handle(
        $line,
        null,
        $this->customer->id,
        invoiceAllocations: [['invoice_id' => $invoice->id, 'amount_cents' => 115000]],
    );

    expect($entry->source_type)->toBe(CustomerReceipt::class)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($line->fresh()->created_journal_entry_id)->toBe($entry->id)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($entry->lines()->where('account_id', $this->bank->id)->sum('debit_cents'))->toEqual(115000);
});

it('splits one deposit across several invoices, one in part', function () {
    $a = overpaymentInvoice($this->customer, 3000);
    $b = overpaymentInvoice($this->customer, 9000);
    $line = depositLine(10000, $this->customer->id);

    app(RecordStatementLine::class)->handle(
        $line,
        null,
        $this->customer->id,
        invoiceAllocations: [
            ['invoice_id' => $a->id, 'amount_cents' => 3000],
            ['invoice_id' => $b->id, 'amount_cents' => 7000],
        ],
    );

    expect($a->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($b->fresh()->status)->toBe(InvoiceStatus::Partial)
        ->and($b->fresh()->balanceCents())->toBe(2000);
});

it('refuses an invoice that was paid elsewhere since it was offered', function () {
    $invoice = overpaymentInvoice($this->customer, 5000);
    overpaymentReceipt($this->customer, 5000, $invoice);
    $line = depositLine(5000, $this->customer->id);

    expect(fn () => app(RecordStatementLine::class)->handle(
        $line,
        null,
        $this->customer->id,
        invoiceAllocations: [['invoice_id' => $invoice->id, 'amount_cents' => 5000]],
    ))->toThrow(PostingValidationException::class);

    expect($line->fresh()->created_journal_entry_id)->toBeNull();
});

it('refuses amounts that do not add up to the deposit, other customers, and money going out', function () {
    $invoice = overpaymentInvoice($this->customer, 5000);
    $other = Contact::factory()->customer()->create();
    $otherInvoice = overpaymentInvoice($other, 5000);
    $action = app(RecordStatementLine::class);

    expect(fn () => $action->handle(depositLine(6000, $this->customer->id), null, $this->customer->id, invoiceAllocations: [['invoice_id' => $invoice->id, 'amount_cents' => 5000]]))
        ->toThrow(PostingValidationException::class)
        ->and(fn () => $action->handle(depositLine(5000, $this->customer->id), null, $this->customer->id, invoiceAllocations: [['invoice_id' => $otherInvoice->id, 'amount_cents' => 5000]]))
        ->toThrow(PostingValidationException::class)
        ->and(fn () => $action->handle(depositLine(-5000, $this->customer->id), null, $this->customer->id, invoiceAllocations: [['invoice_id' => $invoice->id, 'amount_cents' => 5000]]))
        ->toThrow(PostingValidationException::class);
});

it('suggests the matching invoice when a customer is picked on For Review, and accepting pays it', function () {
    $invoice = overpaymentInvoice($this->customer, 115000);
    $line = depositLine(115000);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('selectLineContact', $line->id, $this->customer->id)
        ->assertSet("lineInvoice.{$line->id}", $invoice->id)
        ->assertSee('data-test="review-receive-as"', escape: false)
        ->call('accept', $line->id);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created);
});

it('lets the Receive payment picker allocate across invoices', function () {
    $a = overpaymentInvoice($this->customer, 3000);
    $b = overpaymentInvoice($this->customer, 9000);
    $line = depositLine(10000);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('selectLineContact', $line->id, $this->customer->id)
        ->call('openReceivePayment', $line->id)
        ->set('receiveRows.0.apply', '30.00')
        ->set('receiveRows.1.apply', '70.00')
        ->call('saveReceivePayment')
        ->assertSet("lineInvoice.{$line->id}", 'allocations')
        ->call('accept', $line->id);

    expect($a->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($b->fresh()->status)->toBe(InvoiceStatus::Partial);
});

it('offers Equity accounts as a category so capital and drawings can come straight from the feed', function () {
    $equity = Account::query()->where('type', 'equity')->orderBy('code')->firstOrFail();
    $line = depositLine(500000);

    $page = Livewire::test('pages::banking.review', ['company' => $this->company]);

    expect(collect($page->instance()->categoryOptions)->pluck('value')->all())->toContain($equity->id);

    $entry = app(RecordStatementLine::class)->handle($line, $equity->id);

    expect($entry->lines()->where('account_id', $equity->id)->sum('credit_cents'))->toEqual(500000);
});
