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
use App\Models\VendorCredit;
use App\Services\Posting\BillPoster;
use App\Services\Posting\BillReconciler;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\InvoiceReconciler;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\VendorCreditPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * currency:backfill-reversal-memos repairs the reversals voids and accrual
 * reversals wrote before they carried the foreign-currency memo: it copies each
 * original line's memo, foreign debit and credit swapped, onto the reversal line
 * that mirrors it, then re-opens whatever a voided foreign credit had closed.
 * app:upgrade runs it on every deploy, so it must be exact, idempotent and safe
 * to run against data it cannot pair.
 */

beforeEach(function () {
    $this->company = fxBackfillCompany();
    $this->usd = $this->company->currencies()->where('currency_code', 'USD')->first();
    $this->customer = Contact::create(['display_name' => 'US Co', 'is_customer' => true, 'currency_code' => 'USD']);
    $this->vendor = Contact::create(['display_name' => 'US Supplier', 'is_vendor' => true, 'currency_code' => 'USD']);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** A CAD organization with USD enabled, bound as the current company. */
function fxBackfillCompany(): Company
{
    $company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $company);
    app(EnableCompanyCurrency::class)->handle($company, 'USD');

    return $company->refresh();
}

/** A posted USD invoice to the test's US customer, at 1.35. */
function fxBackfillInvoice(string $no, int $cents, ?Contact $customer = null, ?Account $income = null): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => ($customer ?? test()->customer)->id, 'invoice_no' => $no,
        'invoice_date' => '2026-03-01', 'due_date' => '2026-04-30',
        'currency_code' => 'USD', 'fx_rate' => '1.35',
    ]);
    $invoice->lines()->create([
        'account_id' => ($income ?? test()->income)->id, 'description' => 'Export', 'quantity' => '1',
        'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

/** Void an invoice, then rewind its reversal to what the old void wrote. Returns the reversal's id. */
function fxBackfillOldVoid(Invoice $invoice): int
{
    app(InvoicePoster::class)->void($invoice->fresh(), CarbonImmutable::parse('2026-03-10'));
    $reversalId = (int) $invoice->fresh()->journalEntry->reversed_by_entry_id;
    fxBackfillRewind($reversalId);

    return $reversalId;
}

/** Blank the foreign memo on every line of an entry, as the pre-fix reversal writers left it. */
function fxBackfillRewind(int $entryId): void
{
    DB::table('journal_lines')->where('journal_entry_id', $entryId)->update([
        'currency_code' => null, 'fx_rate' => null, 'foreign_debit_cents' => 0, 'foreign_credit_cents' => 0,
    ]);
}

/**
 * Each line's memo columns, in line order.
 *
 * @return list<array{?string, ?string, int, int}>
 */
function fxBackfillMemos(int $entryId): array
{
    return DB::table('journal_lines')->where('journal_entry_id', $entryId)
        ->orderBy('line_order')->orderBy('id')->get()
        ->map(fn ($l): array => [
            $l->currency_code,
            $l->fx_rate === null ? null : number_format((float) $l->fx_rate, 2),
            (int) $l->foreign_debit_cents,
            (int) $l->foreign_credit_cents,
        ])->all();
}

/** Posted foreign units (debit - credit) on an account, optionally for one contact. */
function fxBackfillForeignNet(int $accountId, ?int $contactId = null): int
{
    return (int) DB::table('journal_lines')->where('account_id', $accountId)->where('is_posted', true)
        ->when($contactId !== null, fn ($q) => $q->where('contact_id', $contactId))
        ->sum(DB::raw('foreign_debit_cents - foreign_credit_cents'));
}

/** Leave no company bound, so the command runs as it does from a shell. */
function fxBackfillUnbind(): void
{
    app()->forgetInstance('current_company');
}

it('copies each original line\'s foreign memo onto the reversal an old void wrote without it, and a second run changes nothing', function () {
    $invoice = fxBackfillInvoice('INV-OLD-1', 100_000);
    $reversalId = fxBackfillOldVoid($invoice);
    $arId = (int) $this->usd->ar_account_id;
    $balances = DB::table('accounts')->where('company_id', $this->company->id)->pluck('balance_cents', 'id')->all();

    // The phantom the bug left: the voided invoice's USD 1,000 still on USD AR.
    expect(fxBackfillForeignNet($arId))->toBe(100_000);

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain("Company {$this->company->slug} — copied the foreign-currency memo onto 2 line(s) of 1 reversal(s).")
        ->doesntExpectOutputToContain('Skipped')
        ->assertSuccessful();

    // AR debit USD 1,000 → reversal credit USD 1,000; income credit → reversal debit.
    expect(fxBackfillMemos($reversalId))->toBe([
        ['USD', '1.35', 0, 100_000],
        ['USD', '1.35', 100_000, 0],
    ])->and(fxBackfillForeignNet($arId))->toBe(0)
        ->and(fxBackfillForeignNet($this->income->id))->toBe(0);

    // Memo columns only: every home balance is exactly as it was.
    expect(DB::table('accounts')->where('company_id', $this->company->id)->pluck('balance_cents', 'id')->all())->toBe($balances);

    // Nothing is left for the period-end revaluation to find.
    app()->instance('current_company', $this->company);
    expect(app(RunHomeCurrencyAdjustment::class)->handle($this->company, CarbonImmutable::parse('2026-03-31'), ['USD' => '1.42']))->toBeNull();

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain("Company {$this->company->slug} — copied the foreign-currency memo onto 0 line(s) of 0 reversal(s).")
        ->assertSuccessful();

    expect(fxBackfillMemos($reversalId))->toBe([
        ['USD', '1.35', 0, 100_000],
        ['USD', '1.35', 100_000, 0],
    ]);
});

it('points at the Home Currency Adjustments that revalued the voided units', function () {
    $invoice = fxBackfillInvoice('INV-OLD-1', 100_000);

    // Legitimate: the invoice was open on 5 March.
    expect(app(RunHomeCurrencyAdjustment::class)->handle($this->company, CarbonImmutable::parse('2026-03-05'), ['USD' => '1.40']))->not->toBeNull();

    // Voided on 10 March by the old void; the 31 March run revalued what was left.
    fxBackfillOldVoid($invoice);
    app()->instance('current_company', $this->company);
    expect(app(RunHomeCurrencyAdjustment::class)->handle($this->company, CarbonImmutable::parse('2026-03-31'), ['USD' => '1.42']))->not->toBeNull();

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos', ['--dry-run' => true])
        ->expectsOutputToContain('1 Home Currency Adjustment(s) dated 2026-03-10 or later revalued these units as if they were still open')
        ->assertSuccessful();

    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain('1 Home Currency Adjustment(s) dated 2026-03-10 or later revalued these units as if they were still open')
        ->assertSuccessful();

    // Once filled, nothing more to say.
    $this->artisan('currency:backfill-reversal-memos')
        ->doesntExpectOutputToContain('Home Currency Adjustment')
        ->assertSuccessful();
});

it('counts what it would copy on a dry run and writes nothing', function () {
    $reversalId = fxBackfillOldVoid(fxBackfillInvoice('INV-OLD-1', 100_000));

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos', ['--dry-run' => true])
        ->expectsOutputToContain("Company {$this->company->slug} — would copy the foreign-currency memo onto 2 line(s) of 1 reversal(s).")
        ->assertSuccessful();

    expect(fxBackfillMemos($reversalId))->toBe([[null, null, 0, 0], [null, null, 0, 0]]);
});

it('re-opens a foreign invoice that a voided credit memo had closed', function () {
    $invoice = fxBackfillInvoice('INV-OLD-1', 100_000);

    $memo = CreditMemo::create([
        'contact_id' => $this->customer->id, 'credit_memo_no' => 'CM-OLD-1',
        'credit_memo_date' => '2026-03-05', 'currency_code' => 'USD', 'fx_rate' => '1.35',
    ]);
    $memo->lines()->create([
        'account_id' => $this->income->id, 'description' => 'Credit', 'quantity' => '1',
        'unit_price_cents' => 100_000, 'line_subtotal_cents' => 100_000,
        'line_tax_cents' => 0, 'line_total_cents' => 100_000, 'line_order' => 0,
    ]);
    app(CreditMemoPoster::class)->post($memo);
    app(InvoiceReconciler::class)->reconcileInvoice($invoice->fresh());
    app(CreditMemoPoster::class)->void($memo->fresh());

    // What the old void left: a reversal without the memo, and the invoice it
    // should have re-opened still closed by the voided credit.
    fxBackfillRewind((int) $memo->fresh()->journalEntry->reversed_by_entry_id);
    DB::table('invoices')->where('id', $invoice->id)->update(['reconciled_cents' => 100_000, 'status' => InvoiceStatus::Paid->value]);

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos', ['--dry-run' => true])
        ->expectsOutputToContain('Would then re-check 1 customer or vendor balance(s)')
        ->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain('copied the foreign-currency memo onto 2 line(s) of 1 reversal(s).')
        ->expectsOutputToContain('Re-opened 1 invoice(s) and 0 bill(s) that a voided credit had closed.')
        ->assertSuccessful();

    $invoice->refresh();

    expect(fxBackfillForeignNet((int) $this->usd->ar_account_id, $this->customer->id))->toBe(100_000)
        ->and($invoice->reconciled_cents)->toBe(0)
        ->and($invoice->balanceCents())->toBe(100_000)
        ->and($invoice->status)->toBe(InvoiceStatus::Posted);
});

it('re-opens a foreign bill that a voided vendor credit had closed', function () {
    $bill = Bill::create([
        'contact_id' => $this->vendor->id, 'bill_type' => BillType::Vendor, 'bill_no' => 'BILL-OLD-1',
        'bill_date' => '2026-03-01', 'due_date' => '2026-03-31', 'currency_code' => 'USD', 'fx_rate' => '1.30',
    ]);
    $bill->lines()->create([
        'account_id' => $this->expense->id, 'description' => 'Service', 'quantity' => '1',
        'unit_price_cents' => 50_000, 'line_subtotal_cents' => 50_000,
        'line_tax_cents' => 0, 'line_total_cents' => 50_000, 'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    $credit = VendorCredit::create([
        'contact_id' => $this->vendor->id, 'vendor_credit_no' => 'VC-OLD-1',
        'vendor_credit_date' => '2026-03-05', 'currency_code' => 'USD', 'fx_rate' => '1.30',
    ]);
    $credit->lines()->create([
        'account_id' => $this->expense->id, 'description' => 'Return', 'quantity' => '1',
        'unit_price_cents' => 50_000, 'line_subtotal_cents' => 50_000,
        'line_tax_cents' => 0, 'line_total_cents' => 50_000, 'line_order' => 0,
    ]);
    app(VendorCreditPoster::class)->post($credit);
    app(BillReconciler::class)->reconcileBill($bill->fresh());
    app(VendorCreditPoster::class)->void($credit->fresh());

    fxBackfillRewind((int) $credit->fresh()->journalEntry->reversed_by_entry_id);
    DB::table('bills')->where('id', $bill->id)->update(['reconciled_cents' => 50_000, 'status' => BillStatus::Paid->value]);

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain('Re-opened 0 invoice(s) and 1 bill(s) that a voided credit had closed.')
        ->assertSuccessful();

    $bill->refresh();

    expect($bill->reconciled_cents)->toBe(0)
        ->and($bill->balanceCents())->toBe(50_000)
        ->and($bill->status)->toBe(BillStatus::Posted);
});

it('re-checks balances once every reversal is filled, so a legitimately closed invoice stays closed', function () {
    // INV-2 is closed for real: a write-off journal entry cleared its USD 100 on AR.
    $closed = fxBackfillInvoice('INV-2', 10_000);
    $writeOff = JournalEntry::create(['entry_no' => 'JE-WO', 'entry_date' => '2026-03-02', 'memo' => 'Write-off']);
    $writeOff->lines()->create([
        'account_id' => $this->usd->ar_account_id, 'contact_id' => $this->customer->id,
        'debit_cents' => 0, 'credit_cents' => 13_500, 'line_order' => 0,
        'currency_code' => 'USD', 'fx_rate' => '1.35', 'foreign_debit_cents' => 0, 'foreign_credit_cents' => 10_000,
    ]);
    $writeOff->lines()->create(['account_id' => $this->expense->id, 'debit_cents' => 13_500, 'credit_cents' => 0, 'line_order' => 1]);
    app(JournalPoster::class)->post($writeOff);
    app(InvoiceReconciler::class)->reconcileInvoice($closed->fresh());
    expect($closed->fresh()->status)->toBe(InvoiceStatus::Paid);

    // A USD 50 credit memo voided first, then a USD 100 invoice voided — both by
    // the old void, which left their reversals without the memo.
    $memo = CreditMemo::create([
        'contact_id' => $this->customer->id, 'credit_memo_no' => 'CM-1',
        'credit_memo_date' => '2026-03-05', 'currency_code' => 'USD', 'fx_rate' => '1.35',
    ]);
    $memo->lines()->create([
        'account_id' => $this->income->id, 'description' => 'Credit', 'quantity' => '1',
        'unit_price_cents' => 5_000, 'line_subtotal_cents' => 5_000,
        'line_tax_cents' => 0, 'line_total_cents' => 5_000, 'line_order' => 0,
    ]);
    app(CreditMemoPoster::class)->post($memo);
    app(CreditMemoPoster::class)->void($memo->fresh());
    fxBackfillRewind((int) $memo->fresh()->journalEntry->reversed_by_entry_id);
    fxBackfillOldVoid(fxBackfillInvoice('INV-1', 10_000));

    // Filling the credit memo's reversal alone would read USD 100 owed and
    // re-open INV-2; with both filled the customer owes nothing, as before.
    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain('copied the foreign-currency memo onto 4 line(s) of 2 reversal(s).')
        ->doesntExpectOutputToContain('Re-opened')
        ->assertSuccessful();

    expect(fxBackfillForeignNet((int) $this->usd->ar_account_id, $this->customer->id))->toBe(0)
        ->and($closed->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($closed->fresh()->reconciled_cents)->toBe(10_000);
});

it('pairs lines that share a line order by what they mirror', function () {
    // An original whose lines all sit at line_order 0: the old void could have
    // read them in either order, so its reversal lists them the other way round.
    $original = JournalEntry::create(['entry_no' => 'JE-TIE', 'entry_date' => '2026-03-01']);
    $original->lines()->create([
        'account_id' => $this->usd->ar_account_id, 'contact_id' => $this->customer->id,
        'debit_cents' => 13_500, 'credit_cents' => 0, 'line_order' => 0,
        'currency_code' => 'USD', 'fx_rate' => '1.35', 'foreign_debit_cents' => 10_000, 'foreign_credit_cents' => 0,
    ]);
    $original->lines()->create([
        'account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 13_500, 'line_order' => 0,
        'currency_code' => 'USD', 'fx_rate' => '1.35', 'foreign_debit_cents' => 0, 'foreign_credit_cents' => 10_000,
    ]);
    app(JournalPoster::class)->post($original);

    $reversal = JournalEntry::create(['entry_no' => 'REV-JE-TIE', 'entry_date' => '2026-03-10', 'reverses_entry_id' => $original->id]);
    $reversal->lines()->create(['account_id' => $this->income->id, 'debit_cents' => 13_500, 'credit_cents' => 0, 'line_order' => 0]);
    $reversal->lines()->create(['account_id' => $this->usd->ar_account_id, 'contact_id' => $this->customer->id, 'debit_cents' => 0, 'credit_cents' => 13_500, 'line_order' => 1]);
    app(JournalPoster::class)->post($reversal);
    $original->forceFill(['voided_at' => now()])->save();

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain('copied the foreign-currency memo onto 2 line(s) of 1 reversal(s).')
        ->assertSuccessful();

    expect(fxBackfillMemos($reversal->id))->toBe([
        ['USD', '1.35', 10_000, 0],  // income: original foreign credit → reversal foreign debit
        ['USD', '1.35', 0, 10_000],  // AR: original foreign debit → reversal foreign credit
    ])->and(fxBackfillForeignNet((int) $this->usd->ar_account_id))->toBe(0);
});

it('skips and reports a reversal that no longer mirrors its original, writing nothing', function () {
    $reversalId = fxBackfillOldVoid(fxBackfillInvoice('INV-OLD-1', 100_000));
    $reversal = JournalEntry::query()->findOrFail($reversalId);

    // Someone edited the posted reversal's amounts (still balanced).
    DB::table('journal_lines')->where('journal_entry_id', $reversalId)->where('debit_cents', '>', 0)->increment('debit_cents');
    DB::table('journal_lines')->where('journal_entry_id', $reversalId)->where('credit_cents', '>', 0)->increment('credit_cents');

    // Each expectation matches its own output line (one write satisfies one).
    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain('copied the foreign-currency memo onto 0 line(s) of 0 reversal(s).')
        ->expectsOutputToContain("Skipped reversal {$reversal->entry_no} (#{$reversalId}) of entry {$reversal->reverses->entry_no} (#{$reversal->reverses_entry_id}): its line 1 mirrors none of the original's lines")
        ->assertSuccessful();

    expect(fxBackfillMemos($reversalId))->toBe([[null, null, 0, 0], [null, null, 0, 0]]);

    // It stays listed on every run until someone fixes it by hand.
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain("Skipped reversal {$reversal->entry_no} (#{$reversalId})")
        ->assertSuccessful();
});

it('skips a reversal whose line carries a memo that is not its original\'s mirror', function () {
    $invoice = fxBackfillInvoice('INV-1', 100_000);
    app(InvoicePoster::class)->void($invoice, CarbonImmutable::parse('2026-03-10'));
    $reversalId = (int) $invoice->fresh()->journalEntry->reversed_by_entry_id;

    // The income mirror lost its memo; the AR mirror carries one that is wrong.
    DB::table('journal_lines')->where('journal_entry_id', $reversalId)->where('account_id', $this->income->id)
        ->update(['currency_code' => null, 'fx_rate' => null, 'foreign_debit_cents' => 0, 'foreign_credit_cents' => 0]);
    DB::table('journal_lines')->where('journal_entry_id', $reversalId)->where('account_id', $this->usd->ar_account_id)
        ->update(['foreign_credit_cents' => 90_000]);

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain('copied the foreign-currency memo onto 0 line(s) of 0 reversal(s).')
        ->expectsOutputToContain("Skipped reversal REV-{$invoice->fresh()->journalEntry->entry_no}")
        ->assertSuccessful();

    expect(fxBackfillMemos($reversalId))->toBe([
        ['USD', '1.35', 0, 90_000],
        [null, null, 0, 0],
    ]);
});

it('skips a reversal when two original lines it could mirror share a line order but not a foreign amount', function () {
    // Two USD AR lines at the same line order, each 40 home cents at 0.40, but
    // USD 1.00 and USD 1.01: their mirrors are identical, their memos are not.
    $original = JournalEntry::create(['entry_no' => 'JE-AMB', 'entry_date' => '2026-03-01']);
    foreach ([100, 101] as $foreign) {
        $original->lines()->create([
            'account_id' => $this->usd->ar_account_id, 'contact_id' => $this->customer->id,
            'debit_cents' => 40, 'credit_cents' => 0, 'line_order' => 0,
            'currency_code' => 'USD', 'fx_rate' => '0.40', 'foreign_debit_cents' => $foreign, 'foreign_credit_cents' => 0,
        ]);
    }
    $original->lines()->create(['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 80, 'line_order' => 0]);
    app(JournalPoster::class)->post($original);
    $reversal = app(JournalPoster::class)->void($original->fresh(), CarbonImmutable::parse('2026-03-10'));
    fxBackfillRewind($reversal->id);

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain("Skipped reversal {$reversal->entry_no} (#{$reversal->id}) of entry JE-AMB (#{$original->id}): its line 1 could mirror either of two original lines that share a line order but carry different foreign amounts, so which one it copied cannot be told. Check it by hand.")
        ->assertSuccessful();

    expect(fxBackfillMemos($reversal->id))->toBe([[null, null, 0, 0], [null, null, 0, 0], [null, null, 0, 0]]);
});

it('fills only the blank lines of a reversal whose other lines already carry their mirror', function () {
    $invoice = fxBackfillInvoice('INV-1', 100_000);
    app(InvoicePoster::class)->void($invoice, CarbonImmutable::parse('2026-03-10'));
    $reversalId = (int) $invoice->fresh()->journalEntry->reversed_by_entry_id;

    // Only the income mirror lost its memo; the AR mirror keeps its correct one.
    DB::table('journal_lines')->where('journal_entry_id', $reversalId)->where('account_id', $this->income->id)
        ->update(['currency_code' => null, 'fx_rate' => null, 'foreign_debit_cents' => 0, 'foreign_credit_cents' => 0]);

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain('copied the foreign-currency memo onto 1 line(s) of 1 reversal(s).')
        ->assertSuccessful();

    expect(fxBackfillMemos($reversalId))->toBe([
        ['USD', '1.35', 0, 100_000],
        ['USD', '1.35', 100_000, 0],
    ]);
});

it('fills an accrual reversal draft written without the memo', function () {
    $original = JournalEntry::create(['entry_no' => 'JE-ACC', 'entry_date' => '2026-03-31', 'memo' => 'Accrued export sale']);
    $original->lines()->create([
        'account_id' => $this->usd->ar_account_id, 'contact_id' => $this->customer->id,
        'debit_cents' => 27_000, 'credit_cents' => 0, 'line_order' => 0,
        'currency_code' => 'USD', 'fx_rate' => '1.35', 'foreign_debit_cents' => 20_000, 'foreign_credit_cents' => 0,
    ]);
    $original->lines()->create(['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 27_000, 'line_order' => 1]);
    app(JournalPoster::class)->post($original);

    $draft = app(ReverseJournalEntry::class)->handle($original->fresh(), CarbonImmutable::parse('2026-04-01'));
    fxBackfillRewind($draft->id);

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain('copied the foreign-currency memo onto 1 line(s) of 1 reversal(s).')
        ->assertSuccessful();

    expect(fxBackfillMemos($draft->id))->toBe([
        ['USD', '1.35', 0, 20_000],
        [null, null, 0, 0],
    ]);
});

it('never pairs a reversal with another organization\'s entry', function () {
    $theirs = fxBackfillInvoice('INV-THEIRS', 100_000)->journalEntry;

    // A reversal in a second organization whose link points across tenants.
    $mine = fxBackfillCompany();
    $reversal = JournalEntry::create(['entry_no' => 'REV-X', 'entry_date' => '2026-03-10', 'reverses_entry_id' => $theirs->id]);
    foreach ($theirs->lines as $i => $line) {
        $reversal->lines()->create(['account_id' => $line->account_id, 'debit_cents' => $line->credit_cents, 'credit_cents' => $line->debit_cents, 'line_order' => $i]);
    }

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos')
        ->expectsOutputToContain("Company {$mine->slug} — copied the foreign-currency memo onto 0 line(s) of 0 reversal(s).")
        ->assertSuccessful();

    expect(fxBackfillMemos($reversal->id))->toBe([[null, null, 0, 0], [null, null, 0, 0]]);
});

it('limits the backfill to the organization named, by slug or id', function () {
    $mine = fxBackfillOldVoid(fxBackfillInvoice('INV-MINE', 100_000));

    $other = fxBackfillCompany();
    $otherCustomer = Contact::create(['display_name' => 'Other US Co', 'is_customer' => true, 'currency_code' => 'USD']);
    $otherIncome = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $theirs = fxBackfillOldVoid(fxBackfillInvoice('INV-THEIRS', 100_000, $otherCustomer, $otherIncome));

    fxBackfillUnbind();
    $this->artisan('currency:backfill-reversal-memos', ['company' => $this->company->slug])
        ->expectsOutputToContain("Company {$this->company->slug} — copied the foreign-currency memo onto 2 line(s) of 1 reversal(s).")
        ->doesntExpectOutputToContain("Company {$other->slug}")
        ->assertSuccessful();

    expect(fxBackfillMemos($mine)[0][0])->toBe('USD')
        ->and(fxBackfillMemos($theirs)[0][0])->toBeNull();

    $this->artisan('currency:backfill-reversal-memos', ['company' => (string) $other->id])
        ->expectsOutputToContain("Company {$other->slug} — copied the foreign-currency memo onto 2 line(s) of 1 reversal(s).")
        ->doesntExpectOutputToContain("Company {$this->company->slug}")
        ->assertSuccessful();

    expect(fxBackfillMemos($theirs)[0][0])->toBe('USD');
});
