<?php

/**
 * Reconciling a foreign-currency bank account (audit D4Banking-04). A USD
 * account's statement is in USD, so the reconciliation (cleared balance,
 * marked totals, difference, completion), the statement matcher and the
 * register read each journal line's USD amount — its foreign memo — and never
 * its home cents, which carry the exchange rate. Home-currency accounts are
 * unchanged; BankReconciliationTest covers them.
 *
 * A line on the USD account with no USD amount counts at its home cents only
 * where those were the statement's own figure: an entry the old statement feed
 * added, or a service charge / interest an older reconciliation posted (and the
 * reversal of either). Any other such line counts as zero and is flagged.
 */

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\SaveAccount;
use App\Actions\Purchasing\SaveExpense;
use App\Enums\AccountSubtype;
use App\Enums\BankReconciliationStatus;
use App\Enums\CompanyRole;
use App\Enums\DepositStatus;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\ReconciliationOutOfBalanceException;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CurrencyRevaluation;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Banking\Import\StatementMatcher;
use App\Services\Currency\ExchangeRateProvider;
use App\Services\Currency\ExchangeRateService;
use App\Services\Currency\MissingExchangeRateException;
use App\Services\Currency\Providers\NullExchangeRateProvider;
use App\Services\Posting\CurrencyRevaluationService;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\ExpensePoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
use App\Services\Reconciliation\BankReconciliationService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
    $this->company->refresh();

    $this->usdBank = app(SaveAccount::class)->handle([
        'code' => '1015', 'name' => 'USD Chequing', 'subtype' => AccountSubtype::Bank->value,
        'currency_code' => 'USD',
    ]);
    app(ExchangeRateService::class)->setManualRate($this->company, 'USD', '1.35', CarbonImmutable::parse('2026-03-01'));

    $this->cadBank = Account::query()->where('subtype', AccountSubtype::Bank->value)->whereNull('currency_code')->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->bankCharges = Account::query()->where('code', '6010')->firstOrFail();
    $this->interestIncome = Account::query()->where('subtype', AccountSubtype::OtherIncome->value)->orderBy('code')->firstOrFail();
    $this->service = app(BankReconciliationService::class);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/**
 * Post a deposit of $usdCents into the USD account at $rate; returns its bank line.
 */
function fxRecDeposit(int $usdCents, string $rate = '1.40', string $date = '2026-03-01'): JournalLine
{
    $deposit = Deposit::create([
        'bank_account_id' => test()->usdBank->id,
        'deposit_no' => 'DEP-'.str()->random(8),
        'deposit_date' => $date,
        'amount_cents' => $usdCents,
        'fx_rate' => $rate,
        'status' => DepositStatus::Draft,
    ]);
    $deposit->lines()->create([
        'account_id' => test()->income->id,
        'description' => 'USD sale',
        'amount_cents' => $usdCents,
        'line_order' => 0,
    ]);

    app(DepositPoster::class)->post($deposit);

    return $deposit->refresh()->journalEntry->lines->firstWhere('account_id', test()->usdBank->id);
}

/**
 * A posted entry whose USD-account line has no foreign memo: the shape the old
 * statement feed and the old reconciliation service charge / interest wrote,
 * and that a journal entry made without a foreign amount still has. A positive
 * $signedHomeCents is money in.
 */
function fxRecMemoLessLine(int $signedHomeCents, ?string $sourceType = null, ?int $sourceId = null, string $date = '2026-03-02'): JournalLine
{
    $entry = JournalEntry::create([
        'entry_no' => 'JE-'.str()->random(8),
        'entry_date' => $date,
        'memo' => 'No USD amount',
        'source_type' => $sourceType,
        'source_id' => $sourceId,
    ]);

    $amount = abs($signedHomeCents);
    $in = $signedHomeCents > 0;

    $bankLine = $entry->lines()->create([
        'account_id' => test()->usdBank->id,
        'debit_cents' => $in ? $amount : 0,
        'credit_cents' => $in ? 0 : $amount,
        'line_order' => 0,
    ]);
    $entry->lines()->create([
        'account_id' => test()->income->id,
        'debit_cents' => $in ? 0 : $amount,
        'credit_cents' => $in ? $amount : 0,
        'line_order' => 1,
    ]);

    app(JournalPoster::class)->post($entry->refresh());

    return $bankLine->fresh();
}

function fxRecImport(?Account $account = null): BankStatementImport
{
    return BankStatementImport::factory()->create(['account_id' => ($account ?? test()->usdBank)->id]);
}

function fxRecStatementLine(BankStatementImport $import, int $amountCents, string $date = '2026-03-01'): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $import->account_id,
        'txn_date' => $date,
        'amount_cents' => $amountCents,
        'description' => 'USD TXN',
        'match_status' => StatementLineMatchStatus::Unmatched->value,
    ]);
}

/**
 * Assert a USD-memo journal line's home amounts and its foreign amounts at 1.35.
 */
function fxRecExpectLeg(JournalLine $leg, int $debit, int $credit, int $foreignDebit, int $foreignCredit): void
{
    expect((int) $leg->debit_cents)->toBe($debit)
        ->and((int) $leg->credit_cents)->toBe($credit)
        ->and($leg->currency_code)->toBe('USD')
        ->and((int) $leg->foreign_debit_cents)->toBe($foreignDebit)
        ->and((int) $leg->foreign_credit_cents)->toBe($foreignCredit)
        ->and(bccomp((string) $leg->fx_rate, '1.35', 8))->toBe(0);
}

function fxRecUsdInvoice(Contact $customer, int $usdCents, string $rate): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-'.str()->random(6),
        'invoice_date' => '2026-03-01',
        'due_date' => '2026-03-31',
        'currency_code' => 'USD',
        'fx_rate' => $rate,
    ]);
    $invoice->lines()->create([
        'account_id' => test()->income->id,
        'description' => 'Export sale',
        'quantity' => '1',
        'unit_price_cents' => $usdCents,
        'line_subtotal_cents' => $usdCents,
        'line_tax_cents' => 0,
        'line_total_cents' => $usdCents,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    return $invoice->refresh();
}

// ── The reconciliation ──────────────────────────────────────────────────────

it('reconciles a USD account against its USD statement balance', function () {
    // 1,000 USD deposited at 1.40 is 1,400.00 CAD in the ledger; the USD statement shows 1,000.00.
    $bankLine = fxRecDeposit(100_000, '1.40');

    $rec = $this->service->begin($this->usdBank, CarbonImmutable::parse('2026-03-31'), 100_000);
    $rec = $this->service->toggleMark($rec, (int) $bankLine->id);

    expect($this->service->clearedBalanceCents($rec))->toBe(100_000)
        ->and($this->service->differenceCents($rec))->toBe(0);

    $completed = $this->service->complete($rec);

    expect($completed->status)->toBe(BankReconciliationStatus::Completed)
        ->and((int) $bankLine->fresh()->bank_reconciliation_id)->toBe($completed->id);
});

it('will not complete a USD reconciliation that only ties in home cents', function () {
    $bankLine = fxRecDeposit(100_000, '1.40');

    // 1,400.00 is the deposit's CAD value, not what the USD statement says.
    $rec = $this->service->begin($this->usdBank, CarbonImmutable::parse('2026-03-31'), 140_000);
    $rec = $this->service->toggleMark($rec, (int) $bankLine->id);

    expect($this->service->differenceCents($rec))->toBe(40_000)
        ->and(fn () => $this->service->complete($rec))->toThrow(ReconciliationOutOfBalanceException::class);
});

it('starts the next USD reconciliation from the last USD ending balance', function () {
    $march = fxRecDeposit(100_000, '1.40', '2026-03-01');
    $this->service->complete($this->service->toggleMark(
        $this->service->begin($this->usdBank, CarbonImmutable::parse('2026-03-31'), 100_000),
        (int) $march->id,
    ));

    $april = fxRecDeposit(50_000, '1.30', '2026-04-10');
    $next = $this->service->begin($this->usdBank, CarbonImmutable::parse('2026-04-30'), 150_000);

    expect($next->beginning_balance_cents)->toBe(100_000);

    $next = $this->service->toggleMark($next, (int) $april->id);

    expect($this->service->differenceCents($next))->toBe(0);
});

it('posts a USD service charge and interest at their home value, keeping the USD amounts', function () {
    $deposit = fxRecDeposit(100_000, '1.40');

    // The statement: +1,000.00 deposit, -5.00 fee, +2.00 interest = 997.00 USD.
    $rec = $this->service->begin(
        $this->usdBank,
        CarbonImmutable::parse('2026-03-31'),
        99_700,
        serviceCharge: ['cents' => 500, 'date' => CarbonImmutable::parse('2026-03-31'), 'account_id' => $this->bankCharges->id],
        interestEarned: ['cents' => 200, 'date' => CarbonImmutable::parse('2026-03-31'), 'account_id' => $this->interestIncome->id],
    );
    $rec = $this->service->toggleMark($rec, (int) $deposit->id);

    expect($this->service->differenceCents($rec))->toBe(0);

    // 5.00 USD at 1.35 = 6.75 CAD on both legs, the USD amount kept as their memo.
    $fee = JournalEntry::with('lines')->findOrFail($rec->service_charge_entry_id)->lines;
    fxRecExpectLeg($fee->firstWhere('account_id', $this->usdBank->id), 0, 675, 0, 500);
    fxRecExpectLeg($fee->firstWhere('account_id', $this->bankCharges->id), 675, 0, 500, 0);

    // 2.00 USD at 1.35 = 2.70 CAD.
    $interest = JournalEntry::with('lines')->findOrFail($rec->interest_earned_entry_id)->lines;
    fxRecExpectLeg($interest->firstWhere('account_id', $this->usdBank->id), 270, 0, 200, 0);
    fxRecExpectLeg($interest->firstWhere('account_id', $this->interestIncome->id), 0, 270, 0, 200);

    expect($this->service->complete($rec)->status)->toBe(BankReconciliationStatus::Completed)
        ->and($this->usdBank->fresh()->foreignBalanceCents())->toBe(99_700)
        ->and($this->usdBank->fresh()->balance_cents)->toBe(140_000 - 675 + 270);
});

it('re-posts a changed USD service charge at the rate for its new date', function () {
    app(ExchangeRateService::class)->setManualRate($this->company, 'USD', '1.40', CarbonImmutable::parse('2026-03-20'));

    $rec = $this->service->begin(
        $this->usdBank,
        CarbonImmutable::parse('2026-03-31'),
        -500,
        serviceCharge: ['cents' => 500, 'date' => CarbonImmutable::parse('2026-03-10'), 'account_id' => $this->bankCharges->id],
    );

    $rec = $this->service->updateDetails(
        $rec,
        CarbonImmutable::parse('2026-03-31'),
        -800,
        0,
        serviceCharge: ['cents' => 800, 'date' => CarbonImmutable::parse('2026-03-25'), 'account_id' => $this->bankCharges->id],
    );

    $fee = JournalEntry::with('lines')->findOrFail($rec->service_charge_entry_id)->lines->firstWhere('account_id', $this->usdBank->id);

    expect((int) $fee->credit_cents)->toBe(1_120) // 8.00 USD at 1.40
        ->and((int) $fee->foreign_credit_cents)->toBe(800)
        ->and($this->service->differenceCents($rec))->toBe(0);
});

it('refuses a USD service charge when no rate is known for its date, rather than guess', function () {
    app()->instance(ExchangeRateProvider::class, new NullExchangeRateProvider);

    expect(fn () => app(BankReconciliationService::class)->begin(
        $this->usdBank,
        CarbonImmutable::parse('2026-02-28'),
        -500,
        serviceCharge: ['cents' => 500, 'date' => CarbonImmutable::parse('2026-02-15'), 'account_id' => $this->bankCharges->id],
    ))->toThrow(MissingExchangeRateException::class);

    expect(BankReconciliation::query()->count())->toBe(0)
        ->and(JournalLine::query()->where('account_id', $this->usdBank->id)->count())->toBe(0);
});

it('still posts a home-currency service charge in home cents with no foreign memo', function () {
    $rec = $this->service->begin(
        $this->cadBank,
        CarbonImmutable::parse('2026-03-31'),
        -500,
        serviceCharge: ['cents' => 500, 'date' => CarbonImmutable::parse('2026-03-31'), 'account_id' => $this->bankCharges->id],
    );

    $fee = JournalEntry::with('lines')->findOrFail($rec->service_charge_entry_id)->lines->firstWhere('account_id', $this->cadBank->id);

    expect((int) $fee->credit_cents)->toBe(500)
        ->and($fee->currency_code)->toBeNull()
        ->and((int) $fee->foreign_credit_cents)->toBe(0)
        ->and($this->service->differenceCents($rec))->toBe(0);
});

// ── Statement matching ──────────────────────────────────────────────────────

it('matches a USD statement line to the USD deposit it records', function () {
    $bankLine = fxRecDeposit(100_000, '1.40');

    $import = fxRecImport();
    $line = fxRecStatementLine($import, 100_000); // the bank's USD statement: +1,000.00 on the same day
    app(StatementMatcher::class)->match($import);

    expect($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Matched)
        ->and((int) $line->fresh()->matched_journal_line_id)->toBe((int) $bankLine->id);
});

it('matches money leaving a USD account on its USD amount', function () {
    $expense = app(SaveExpense::class)->handle([
        'payment_account_id' => $this->usdBank->id,
        'expense_date' => '2026-03-05',
        'payee_name' => 'US Supplier',
        'lines' => [['account_id' => $this->bankCharges->id, 'amount_cents' => 5_000, 'description' => 'Wire fee']],
    ]);
    $entry = app(ExpensePoster::class)->post($expense);
    $bankLine = $entry->lines()->where('account_id', $this->usdBank->id)->firstOrFail();

    expect((int) $bankLine->credit_cents)->toBe(6_750); // 50.00 USD at 1.35

    $import = fxRecImport();
    $line = fxRecStatementLine($import, -5_000, '2026-03-05');
    app(StatementMatcher::class)->match($import);

    expect($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Matched)
        ->and((int) $line->fresh()->matched_journal_line_id)->toBe((int) $bankLine->id);
});

it('never offers a USD-account line with no USD amount as a match', function () {
    // A journal entry of 135.00 CAD on the USD account, made without its USD
    // amount: 135.00 USD on the statement is not it.
    fxRecMemoLessLine(13_500);

    $import = fxRecImport();
    $line = fxRecStatementLine($import, 13_500, '2026-03-02');
    app(StatementMatcher::class)->match($import);

    expect($line->fresh()->match_status)->not->toBe(StatementLineMatchStatus::Matched)
        ->and($line->fresh()->matched_journal_line_id)->toBeNull();
});

it('keeps matching home-currency statement lines on home cents', function () {
    $entry = JournalEntry::create(['entry_no' => 'JE-CAD-1', 'entry_date' => '2026-03-01', 'memo' => 'CAD deposit']);
    $bankLine = $entry->lines()->create(['account_id' => $this->cadBank->id, 'debit_cents' => 25_000, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 25_000, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry->refresh());

    $import = fxRecImport($this->cadBank);
    $line = fxRecStatementLine($import, 25_000);
    app(StatementMatcher::class)->match($import);

    expect($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Matched)
        ->and((int) $line->fresh()->matched_journal_line_id)->toBe((int) $bankLine->id);
});

// ── Lines that carry no USD amount ──────────────────────────────────────────

it('counts lines booked at a statement figure before foreign amounts were kept at that figure', function () {
    $oldImport = fxRecImport();
    $oldRec = BankReconciliation::factory()->completed()->create([
        'account_id' => $this->usdBank->id,
        'statement_date' => '2026-02-28',
        'ending_balance_cents' => 0,
    ]);

    // The old feed booked a +100.00 USD statement line as 100.00 "CAD"; an older
    // reconciliation booked its 3.00 USD service charge the same way.
    $feed = fxRecMemoLessLine(10_000, BankStatementImport::class, $oldImport->id);
    $fee = fxRecMemoLessLine(-300, BankReconciliation::class, $oldRec->id);

    $rec = $this->service->begin($this->usdBank, CarbonImmutable::parse('2026-03-31'), 9_700);
    $rec = $this->service->markLines($rec, [(int) $feed->id, (int) $fee->id]);

    expect($this->service->differenceCents($rec))->toBe(0);

    // The statement matcher keys the old feed line on that figure too.
    $import = fxRecImport();
    $line = fxRecStatementLine($import, 10_000, '2026-03-02');
    app(StatementMatcher::class)->match($import);

    expect((int) $line->fresh()->matched_journal_line_id)->toBe((int) $feed->id);
});

it('nets a voided old feed line against its reversal', function () {
    $feed = fxRecMemoLessLine(10_000, BankStatementImport::class, fxRecImport()->id);
    $reversal = app(JournalPoster::class)->void($feed->journalEntry, CarbonImmutable::parse('2026-03-03'));
    $reversalLine = $reversal->lines->firstWhere('account_id', $this->usdBank->id);

    $rec = $this->service->begin($this->usdBank, CarbonImmutable::parse('2026-03-31'), 0);
    $rec = $this->service->markLines($rec, [(int) $feed->id, (int) $reversalLine->id]);

    expect($this->service->clearedBalanceCents($rec))->toBe(0)
        ->and($this->service->differenceCents($rec))->toBe(0);
});

it('counts any other line with no USD amount as zero and flags it on the reconcile screen', function () {
    $deposit = fxRecDeposit(100_000, '1.40');
    $manual = fxRecMemoLessLine(13_500); // a journal entry made without its USD amount

    // A revaluation moves only the home value: it is zero in USD, and not
    // flagged. Nor is the reversal of one voided through the API.
    $revaluation = app(CurrencyRevaluationService::class)->revalue($this->company, CarbonImmutable::parse('2026-03-31'), ['USD' => '1.50']);
    $voided = app(JournalPoster::class)->void(JournalEntry::query()->findOrFail($revaluation->journal_entry_id), CarbonImmutable::parse('2026-04-02'));

    $revaluationLines = JournalLine::query()
        ->where('account_id', $this->usdBank->id)
        ->where(fn ($q) => $q
            ->whereHas('journalEntry', fn ($q) => $q->where('source_type', CurrencyRevaluation::class))
            ->orWhere('journal_entry_id', $voided->id))
        ->get();
    expect($revaluationLines)->toHaveCount(3);

    $rec = $this->service->begin($this->usdBank, CarbonImmutable::parse('2026-03-31'), 100_000);
    $rec = $this->service->markLines($rec, [(int) $deposit->id, (int) $manual->id, ...$revaluationLines->pluck('id')->map(fn ($id) => (int) $id)->all()]);

    expect($this->service->clearedBalanceCents($rec))->toBe(100_000)
        ->and($this->service->differenceCents($rec))->toBe(0);

    $html = Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->usdBank->id)
        ->assertSeeHtml('data-test="missing-account-amount-notice"')
        ->html();

    // Only the journal entry is flagged: the deposit has its USD amount, and the
    // revaluation lines have none by design.
    expect(substr_count($html, 'data-test="missing-account-amount"'))->toBe(1);
});

// ── A customer payment deposited into the USD account ───────────────────────

it('keeps the USD amount of a customer payment deposited straight into the USD account', function () {
    $customer = Contact::create(['display_name' => 'US Customer', 'is_customer' => true, 'currency_code' => 'USD']);
    $invoice = fxRecUsdInvoice($customer, 100_000, '1.35');

    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'REC-USD-1',
        'receipt_date' => '2026-03-10',
        'deposit_to_account_id' => $this->usdBank->id,
        'amount_cents' => 100_000,
        'currency_code' => 'USD',
        'fx_rate' => '1.40',
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 100_000]);
    app(ReceiptPoster::class)->post($receipt);

    $bankLine = $receipt->refresh()->journalEntry->lines->firstWhere('account_id', $this->usdBank->id);

    expect((int) $bankLine->debit_cents)->toBe(140_000)
        ->and($bankLine->currency_code)->toBe('USD')
        ->and(bccomp((string) $bankLine->fx_rate, '1.40', 8))->toBe(0)
        ->and((int) $bankLine->foreign_debit_cents)->toBe(100_000)
        ->and((int) $bankLine->foreign_credit_cents)->toBe(0)
        ->and($this->usdBank->fresh()->foreignBalanceCents())->toBe(100_000);

    $rec = $this->service->toggleMark($this->service->begin($this->usdBank, CarbonImmutable::parse('2026-03-31'), 100_000), (int) $bankLine->id);

    expect($this->service->differenceCents($rec))->toBe(0);
});

it('leaves a USD customer payment deposited into a home account in home cents only', function () {
    $customer = Contact::create(['display_name' => 'US Customer', 'is_customer' => true, 'currency_code' => 'USD']);
    $invoice = fxRecUsdInvoice($customer, 100_000, '1.35');

    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'REC-USD-2',
        'receipt_date' => '2026-03-10',
        'deposit_to_account_id' => $this->cadBank->id,
        'amount_cents' => 100_000,
        'currency_code' => 'USD',
        'fx_rate' => '1.40',
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 100_000]);
    app(ReceiptPoster::class)->post($receipt);

    $bankLine = $receipt->refresh()->journalEntry->lines->firstWhere('account_id', $this->cadBank->id);

    expect((int) $bankLine->debit_cents)->toBe(140_000)
        ->and($bankLine->currency_code)->toBeNull()
        ->and((int) $bankLine->foreign_debit_cents)->toBe(0);
});

// ── The screens ─────────────────────────────────────────────────────────────

it('lists, totals and reconciles a USD account in USD on the reconcile screen', function () {
    $deposit = fxRecDeposit(100_000, '1.40');
    $this->service->begin($this->usdBank, CarbonImmutable::parse('2026-03-31'), 100_000);

    $component = Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->usdBank->id)
        ->assertSee(__('Amounts are in :currency, the currency of this account and its statements.', ['currency' => 'USD']))
        ->call('toggleMark', (int) $deposit->id);

    expect($component->instance()->availableLines('deposits')->pluck('id')->all())->toBe([(int) $deposit->id])
        ->and($component->instance()->markedTotals('deposits'))->toBe(['count' => 1, 'cents' => 100_000])
        ->and($component->instance()->clearedBalanceCents())->toBe(100_000)
        ->and($component->instance()->differenceCents())->toBe(0);

    $component->assertSeeHtml('data-test="reconciliation-difference">')
        ->assertSee('1,000.00')
        ->assertDontSee('1,400.00')
        ->call('reconcileNow');

    expect(BankReconciliation::query()->completed()->forAccount($this->usdBank->id)->count())->toBe(1);
});

it('shows a completed USD reconciliation in USD', function () {
    $deposit = fxRecDeposit(100_000, '1.40');
    $payment = fxRecMemoLessLine(-2_500, BankStatementImport::class, fxRecImport()->id); // an old feed fee of 25.00 USD

    $rec = $this->service->begin($this->usdBank, CarbonImmutable::parse('2026-03-31'), 97_500);
    $rec = $this->service->markLines($rec, [(int) $deposit->id, (int) $payment->id]);
    $completed = $this->service->complete($rec);

    $page = Livewire::test('pages::banking.reconciliation-show', ['company' => $this->company, 'reconciliation' => $completed])
        ->assertSee(__('Amounts are in :currency, the currency of this account and its statements.', ['currency' => 'USD']));

    expect($page->instance()->detail['deposits_total_cents'])->toBe(100_000)
        ->and($page->instance()->detail['payments_total_cents'])->toBe(2_500)
        ->and($page->instance()->clearedBalanceCents())->toBe(97_500)
        ->and($page->instance()->differenceCents())->toBe(0);
});

it('downloads a completed USD reconciliation in USD', function () {
    $deposit = fxRecDeposit(100_000, '1.40');
    $completed = $this->service->complete($this->service->toggleMark(
        $this->service->begin($this->usdBank, CarbonImmutable::parse('2026-03-31'), 100_000),
        (int) $deposit->id,
    ));

    $csv = base64_decode((string) data_get(
        Livewire::test('pages::banking.reconciliation-show', ['company' => $this->company, 'reconciliation' => $completed])
            ->call('exportCsv')
            ->effects,
        'download.content',
    ));

    expect($csv)->toContain('Currency,USD')
        ->toContain('"Cleared deposits (1)",,,,,1000.00')
        ->toContain('Difference,,,,,0.00')
        ->not->toContain('1400.00');
});

it('sorts a USD account register on its USD amounts', function () {
    $dearer = fxRecDeposit(100_000, '1.40'); // 1,000.00 USD, 1,400.00 CAD
    $larger = fxRecDeposit(120_000, '1.10'); // 1,200.00 USD, 1,320.00 CAD

    $register = Livewire::test('pages::banking.register', ['company' => $this->company])
        ->set('account_id', $this->usdBank->id)
        ->call('sortBy', 'deposit'); // largest deposit first

    expect($register->instance()->lines->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->toBe([(int) $larger->id, (int) $dearer->id]);
});

it('shows a USD account register in USD, with its home value alongside', function () {
    $march = fxRecDeposit(100_000, '1.40', '2026-03-01');
    $april = fxRecDeposit(50_000, '1.30', '2026-04-10');

    $this->service->complete($this->service->toggleMark(
        $this->service->begin($this->usdBank, CarbonImmutable::parse('2026-03-31'), 100_000),
        (int) $march->id,
    ));

    $register = Livewire::test('pages::banking.register', ['company' => $this->company])
        ->set('account_id', $this->usdBank->id)
        ->assertSee(__('Amounts are in :currency, the currency of this account and its statements.', ['currency' => 'USD']))
        ->assertSeeHtml('data-test="cleared-balance">1,000.00<')
        ->assertSee('1,500.00')
        ->assertSee('2,050.00'); // the ledger's home value: 1,400.00 + 650.00 CAD

    expect($register->instance()->ledgerBalanceCents())->toBe(150_000)
        ->and($register->instance()->clearedBalanceCents())->toBe(100_000)
        ->and($register->instance()->runningBalances[(int) $march->id])->toBe(100_000)
        ->and($register->instance()->runningBalances[(int) $april->id])->toBe(150_000);
});
