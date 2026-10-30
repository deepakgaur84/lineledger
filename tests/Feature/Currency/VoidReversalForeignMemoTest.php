<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\ReverseJournalEntry;
use App\Actions\Accounting\RunHomeCurrencyAdjustment;
use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\VendorCredit;
use App\Services\Posting\BillPoster;
use App\Services\Posting\BillReconciler;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\CurrencyRevaluationService;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\InvoiceReconciler;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\VendorCreditPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * A void (and an accrual reversal) must undo a line's foreign-currency memo as
 * well as its home cents. The reversal used to swap debit and credit but leave
 * currency_code, fx_rate and the foreign amounts blank, so a voided foreign
 * document kept its foreign units on its accounts: the period-end Home Currency
 * Adjustment revalued a balance that no longer existed, and the reconcilers,
 * which compare a foreign customer or vendor in foreign units, never re-opened
 * a document whose closing credit was voided.
 */

beforeEach(function () {
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $this->company);
    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
    $this->company->refresh();

    $this->usd = $this->company->currencies()->where('currency_code', 'USD')->first();
    $this->customer = Contact::create(['display_name' => 'US Co', 'is_customer' => true, 'currency_code' => 'USD']);
    $this->vendor = Contact::create(['display_name' => 'US Supplier', 'is_vendor' => true, 'currency_code' => 'USD']);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/** A posted USD invoice for the US customer, booked at 1.35. */
function fxVoidInvoice(string $no, int $cents, string $date = '2026-03-01'): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => test()->customer->id, 'invoice_no' => $no,
        'invoice_date' => $date, 'due_date' => '2026-04-30',
        'currency_code' => 'USD', 'fx_rate' => '1.35',
    ]);
    $invoice->lines()->create([
        'account_id' => test()->income->id, 'description' => 'Export', 'quantity' => '1',
        'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

/** A posted USD credit memo for the US customer, booked at 1.35. */
function fxVoidCreditMemo(string $no, int $cents): CreditMemo
{
    $memo = CreditMemo::create([
        'contact_id' => test()->customer->id, 'credit_memo_no' => $no,
        'credit_memo_date' => '2026-03-05', 'currency_code' => 'USD', 'fx_rate' => '1.35',
    ]);
    $memo->lines()->create([
        'account_id' => test()->income->id, 'description' => 'Credit', 'quantity' => '1',
        'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0,
    ]);
    app(CreditMemoPoster::class)->post($memo);

    return $memo->fresh();
}

/** A posted USD bill from the US supplier, booked at 1.30. */
function fxVoidBill(string $no, int $cents): Bill
{
    $bill = Bill::create([
        'contact_id' => test()->vendor->id, 'bill_type' => BillType::Vendor, 'bill_no' => $no,
        'bill_date' => '2026-03-01', 'due_date' => '2026-03-31',
        'currency_code' => 'USD', 'fx_rate' => '1.30',
    ]);
    $bill->lines()->create([
        'account_id' => test()->expense->id, 'description' => 'Imported service', 'quantity' => '1',
        'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

/** A posted USD vendor credit from the US supplier, booked at 1.30. */
function fxVoidVendorCredit(string $no, int $cents): VendorCredit
{
    $credit = VendorCredit::create([
        'contact_id' => test()->vendor->id, 'vendor_credit_no' => $no,
        'vendor_credit_date' => '2026-03-05', 'currency_code' => 'USD', 'fx_rate' => '1.30',
    ]);
    $credit->lines()->create([
        'account_id' => test()->expense->id, 'description' => 'Return', 'quantity' => '1',
        'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0,
    ]);
    app(VendorCreditPoster::class)->post($credit);

    return $credit->fresh();
}

/** Posted SUM(debit - credit) on an account, in home cents or in foreign units. */
function fxVoidNet(int $accountId, bool $foreign = false, ?int $contactId = null): int
{
    return (int) DB::table('journal_lines')
        ->where('account_id', $accountId)
        ->where('is_posted', true)
        ->when($contactId !== null, fn ($q) => $q->where('contact_id', $contactId))
        ->sum(DB::raw($foreign ? 'foreign_debit_cents - foreign_credit_cents' : 'debit_cents - credit_cents'));
}

it('reverses the foreign units as well as the home cents when a foreign invoice is voided', function () {
    $invoice = fxVoidInvoice('INV-FX-1', 100_000);
    app(InvoicePoster::class)->void($invoice, CarbonImmutable::parse('2026-03-10'));

    $ar = Account::withoutGlobalScopes()->findOrFail($this->usd->ar_account_id);

    expect(fxVoidNet($ar->id))->toBe(0)
        ->and(fxVoidNet($ar->id, foreign: true))->toBe(0, 'USD AR still carries the voided invoice\'s 1,000 USD')
        ->and($ar->recomputeBalance())->toBe(0)
        ->and($ar->foreignBalanceCents())->toBe(0)
        ->and(fxVoidNet($this->income->id, foreign: true))->toBe(0, 'the income leg kept its foreign units too');
});

it('mirrors every line\'s foreign memo onto the reversal, foreign debit and credit swapped', function () {
    $invoice = fxVoidInvoice('INV-FX-1', 100_000);
    app(InvoicePoster::class)->void($invoice, CarbonImmutable::parse('2026-03-10'));

    $original = $invoice->fresh()->journalEntry;
    $reversal = JournalEntry::query()->findOrFail($original->reversed_by_entry_id);

    expect($reversal->reverses_entry_id)->toBe($original->id)
        ->and($reversal->lines)->toHaveCount($original->lines->count());

    foreach ($original->lines->values() as $i => $line) {
        $mirror = $reversal->lines[$i];

        expect($line->currency_code)->toBe('USD')
            ->and($mirror->account_id)->toBe($line->account_id)
            ->and($mirror->debit_cents)->toBe($line->credit_cents)
            ->and($mirror->credit_cents)->toBe($line->debit_cents)
            ->and($mirror->currency_code)->toBe('USD')
            ->and((float) $mirror->fx_rate)->toBe(1.35)
            ->and($mirror->foreign_debit_cents)->toBe($line->foreign_credit_cents)
            ->and($mirror->foreign_credit_cents)->toBe($line->foreign_debit_cents);
    }

    $arMirror = $reversal->lines->firstWhere('account_id', $this->usd->ar_account_id);
    expect($arMirror->foreign_credit_cents)->toBe(100_000)
        ->and($arMirror->foreign_debit_cents)->toBe(0);
});

it('does not revalue a voided foreign invoice at period end', function () {
    $invoice = fxVoidInvoice('INV-FX-1', 100_000);
    app(InvoicePoster::class)->void($invoice, CarbonImmutable::parse('2026-03-10'));
    $entries = JournalEntry::query()->count();

    // Nothing is open in USD, so there is nothing to revalue at any rate.
    $revaluation = app(RunHomeCurrencyAdjustment::class)->handle($this->company, CarbonImmutable::parse('2026-03-31'), ['USD' => '1.42']);

    expect($revaluation)->toBeNull('a Home Currency Adjustment was posted for a voided invoice')
        ->and(JournalEntry::query()->count())->toBe($entries)
        ->and(fxVoidNet((int) $this->company->unrealized_gain_loss_account_id))->toBe(0);

    expect(app(CurrencyRevaluationService::class)->revalue($this->company->fresh(), CarbonImmutable::parse('2026-04-30'), ['USD' => '1.40']))
        ->toBeNull();
});

it('still revalues the foreign invoices that remain open after another is voided', function () {
    fxVoidInvoice('INV-FX-OPEN', 20_000);
    $voided = fxVoidInvoice('INV-FX-VOID', 100_000);
    app(InvoicePoster::class)->void($voided, CarbonImmutable::parse('2026-03-10'));

    $revaluation = app(RunHomeCurrencyAdjustment::class)->handle($this->company, CarbonImmutable::parse('2026-03-31'), ['USD' => '1.42']);

    // Only the open USD 200 is revalued: 200 x (1.42 - 1.35) = 14.00 CAD of gain.
    $unrealized = (int) DB::table('journal_lines')
        ->where('journal_entry_id', $revaluation->journal_entry_id)
        ->where('account_id', $this->company->unrealized_gain_loss_account_id)
        ->sum(DB::raw('credit_cents - debit_cents'));

    expect($unrealized)->toBe(1_400);
});

it('keeps a foreign customer\'s GL balance in step with their open documents after a void', function () {
    $voided = fxVoidInvoice('INV-FX-VOID', 100_000);
    app(InvoicePoster::class)->void($voided, CarbonImmutable::parse('2026-03-05'));
    fxVoidInvoice('INV-FX-2', 20_000, '2026-03-10');

    // GL and documents agree that USD 200 is owed, so nothing is closable.
    expect(fxVoidNet((int) $this->usd->ar_account_id, foreign: true, contactId: $this->customer->id))->toBe(20_000)
        ->and(app(InvoiceReconciler::class)->availableToReconcile($this->company, $this->customer->id, $this->company->currentDateTime()))->toBe(0);
});

it('re-opens a foreign invoice when the credit memo that closed it is voided', function () {
    $invoice = fxVoidInvoice('INV-FX-1', 100_000);
    $memo = fxVoidCreditMemo('CM-FX-1', 100_000);

    // "Close settled balance" applies the credit to the invoice...
    app(InvoiceReconciler::class)->reconcileInvoice($invoice->fresh());
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    // ...then the credit turns out to be a mistake. The customer owes USD 1,000 again.
    app(CreditMemoPoster::class)->void($memo->fresh());

    $invoice->refresh();

    expect(fxVoidNet((int) $this->usd->ar_account_id))->toBe(135_000)
        ->and(fxVoidNet((int) $this->usd->ar_account_id, foreign: true))->toBe(100_000)
        ->and($invoice->reconciled_cents)->toBe(0)
        ->and($invoice->balanceCents())->toBe(100_000)
        ->and($invoice->status)->toBe(InvoiceStatus::Posted);
});

it('reverses the foreign units when a foreign bill is voided', function () {
    $bill = fxVoidBill('BILL-FX-1', 50_000);
    app(BillPoster::class)->void($bill, CarbonImmutable::parse('2026-03-10'));

    $ap = Account::withoutGlobalScopes()->findOrFail($this->usd->ap_account_id);

    expect(fxVoidNet($ap->id))->toBe(0)
        ->and($ap->foreignBalanceCents())->toBe(0, 'USD AP still carries the voided bill\'s 500 USD')
        ->and(fxVoidNet($this->expense->id, foreign: true))->toBe(0);
});

it('re-opens a foreign bill when the vendor credit that closed it is voided', function () {
    $bill = fxVoidBill('BILL-FX-1', 50_000);
    $credit = fxVoidVendorCredit('VC-FX-1', 50_000);

    app(BillReconciler::class)->reconcileBill($bill->fresh());
    expect($bill->fresh()->status)->toBe(BillStatus::Paid);

    app(VendorCreditPoster::class)->void($credit->fresh());

    $bill->refresh();

    expect($bill->reconciled_cents)->toBe(0)
        ->and($bill->balanceCents())->toBe(50_000)
        ->and($bill->status)->toBe(BillStatus::Posted);
});

it('mirrors the foreign memo onto an accrual reversal, which then cancels it out', function () {
    // A manual entry carrying a USD memo on its foreign-AR leg.
    $entry = JournalEntry::create(['entry_no' => 'JE-FX-ACC', 'entry_date' => '2026-03-31', 'memo' => 'Accrued export sale']);
    $entry->lines()->create([
        'account_id' => $this->usd->ar_account_id, 'contact_id' => $this->customer->id,
        'debit_cents' => 27_000, 'credit_cents' => 0, 'line_order' => 0,
        'currency_code' => 'USD', 'fx_rate' => '1.35', 'foreign_debit_cents' => 20_000, 'foreign_credit_cents' => 0,
    ]);
    $entry->lines()->create(['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 27_000, 'line_order' => 1]);
    $original = app(JournalPoster::class)->post($entry);

    $draft = app(ReverseJournalEntry::class)->handle($original, CarbonImmutable::parse('2026-04-01'));

    $arMirror = $draft->lines->firstWhere('account_id', $this->usd->ar_account_id);
    $incomeMirror = $draft->lines->firstWhere('account_id', $this->income->id);

    expect($arMirror->currency_code)->toBe('USD')
        ->and((float) $arMirror->fx_rate)->toBe(1.35)
        ->and($arMirror->credit_cents)->toBe(27_000)
        ->and($arMirror->foreign_credit_cents)->toBe(20_000)
        ->and($arMirror->foreign_debit_cents)->toBe(0)
        ->and($incomeMirror->currency_code)->toBeNull()
        ->and($incomeMirror->foreign_debit_cents + $incomeMirror->foreign_credit_cents)->toBe(0);

    app(JournalPoster::class)->post($draft->fresh());

    expect(fxVoidNet((int) $this->usd->ar_account_id))->toBe(0)
        ->and(fxVoidNet((int) $this->usd->ar_account_id, foreign: true))->toBe(0);
});

it('gives the reversal of a home-currency entry no foreign memo', function () {
    $customer = Contact::create(['display_name' => 'Home Co', 'is_customer' => true]);
    $invoice = Invoice::create([
        'contact_id' => $customer->id, 'invoice_no' => 'INV-CAD-1',
        'invoice_date' => '2026-03-01', 'due_date' => '2026-03-31',
    ]);
    $invoice->lines()->create([
        'account_id' => $this->income->id, 'description' => 'Local sale', 'quantity' => '1',
        'unit_price_cents' => 10_000, 'line_subtotal_cents' => 10_000,
        'line_tax_cents' => 0, 'line_total_cents' => 10_000, 'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);
    app(InvoicePoster::class)->void($invoice->fresh(), CarbonImmutable::parse('2026-03-10'));

    $reversalLines = JournalLine::query()
        ->where('journal_entry_id', $invoice->fresh()->journalEntry->reversed_by_entry_id)
        ->get();

    expect($reversalLines)->not->toBeEmpty();

    foreach ($reversalLines as $line) {
        expect($line->currency_code)->toBeNull()
            ->and($line->fx_rate)->toBeNull()
            ->and($line->foreign_debit_cents)->toBe(0)
            ->and($line->foreign_credit_cents)->toBe(0);
    }
});
