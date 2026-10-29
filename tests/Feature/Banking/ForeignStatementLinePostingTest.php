<?php

/**
 * Statement lines on a foreign-currency bank account (D4Banking-03). The line's
 * amount is in the account's currency, so every path that turns it into a GL
 * posting must book home cents at the day's rate and keep the foreign amount
 * as the line memo, exactly as the Expense / Deposit / Transfer posters do.
 */

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\SaveAccount;
use App\Actions\Banking\BulkCategorizeStatementLines;
use App\Actions\Banking\PairStatementTransfer;
use App\Actions\Banking\RecordStatementLine;
use App\Actions\Banking\SplitStatementLine;
use App\Actions\Documents\MatchInboxItemToStatementLine;
use App\Actions\Purchasing\SaveBill;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InboxItemSource;
use App\Enums\InboxItemStatus;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deposit;
use App\Models\Expense;
use App\Models\InboxItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\TaxCode;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Banking\Import\StatementImportCommitter;
use App\Services\Currency\ExchangeRateProvider;
use App\Services\Currency\ExchangeRateService;
use App\Services\Currency\MissingExchangeRateException;
use App\Services\Currency\Providers\NullExchangeRateProvider;
use App\Services\Posting\BillPoster;
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

    $this->usdBank = fxFeedAccount('1015', 'USD Chequing', AccountSubtype::Bank);
    app(ExchangeRateService::class)->setManualRate($this->company, 'USD', '1.35', CarbonImmutable::parse('2026-03-01'));

    $this->cadBank = Account::query()->where('subtype', AccountSubtype::Bank->value)->whereNull('currency_code')->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->equity = Account::query()->where('subtype', AccountSubtype::Equity->value)->orderBy('code')->firstOrFail();
    [$this->expense, $this->expenseB] = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->take(2)->get()->all();
    $this->import = BankStatementImport::factory()->create(['account_id' => $this->usdBank->id]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function fxFeedAccount(string $code, string $name, AccountSubtype $subtype, string $currency = 'USD'): Account
{
    return app(SaveAccount::class)->handle([
        'code' => $code, 'name' => $name, 'subtype' => $subtype->value, 'currency_code' => $currency,
    ]);
}

function fxFeedLine(int $amount, ?Account $account = null, string $date = '2026-03-01', array $attributes = []): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => test()->import->id,
        'account_id' => ($account ?? test()->usdBank)->id,
        'txn_date' => $date,
        'amount_cents' => $amount,
        'description' => 'USD TXN',
        'match_status' => StatementLineMatchStatus::Unmatched->value,
        ...$attributes,
    ]);
}

function fxFeedLeg(JournalEntry $entry, Account $account): JournalLine
{
    return $entry->lines()->where('account_id', $account->id)->firstOrFail();
}

/**
 * Assert a journal line's home amounts and its foreign memo in one go.
 */
function fxFeedExpectLeg(JournalLine $leg, int $debit, int $credit, ?string $currency, int $foreignDebit, int $foreignCredit): void
{
    expect((int) $leg->debit_cents)->toBe($debit)
        ->and((int) $leg->credit_cents)->toBe($credit)
        ->and($leg->currency_code)->toBe($currency)
        ->and((int) $leg->foreign_debit_cents)->toBe($foreignDebit)
        ->and((int) $leg->foreign_credit_cents)->toBe($foreignCredit);

    if ($currency !== null) {
        expect(bccomp((string) $leg->fx_rate, '1.35', 8))->toBe(0);
    }
}

it('converts a USD money-in line to home cents when recorded from the feed', function () {
    $entry = app(RecordStatementLine::class)->handle(fxFeedLine(10_000), $this->income->id); // 100.00 USD received

    // 100 USD @ 1.35 = 135.00 CAD on both legs, the USD amount kept as their memo.
    fxFeedExpectLeg(fxFeedLeg($entry, $this->usdBank), 13_500, 0, 'USD', 10_000, 0);
    fxFeedExpectLeg(fxFeedLeg($entry, $this->income), 0, 13_500, 'USD', 0, 10_000);

    expect($entry->source_type)->toBe(BankStatementImport::class)
        ->and($this->usdBank->fresh()->balance_cents)->toBe(13_500)
        ->and($this->usdBank->fresh()->foreignBalanceCents())->toBe(10_000)
        ->and($this->income->fresh()->balance_cents)->toBe(13_500);
});

it('books a USD money-out line the same whether or not a payee is chosen', function (int $amount, int $home) {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'US Vendor']);

    // With a payee the line becomes an Expense; without one, the plain journal entry.
    $withPayee = app(RecordStatementLine::class)->handle(fxFeedLine($amount), $this->expense->id, $vendor->id);
    $withoutPayee = app(RecordStatementLine::class)->handle(fxFeedLine($amount), $this->expense->id);

    expect($withPayee->source_type)->toBe(Expense::class)
        ->and($withoutPayee->source_type)->toBe(BankStatementImport::class);

    foreach ([$withPayee, $withoutPayee] as $entry) {
        fxFeedExpectLeg(fxFeedLeg($entry, $this->usdBank), 0, $home, 'USD', 0, -$amount);
        fxFeedExpectLeg(fxFeedLeg($entry, $this->expense), $home, 0, 'USD', -$amount, 0);
    }

    expect($this->usdBank->fresh()->foreignBalanceCents())->toBe(2 * $amount);
})->with([
    '100.00 USD' => [-10_000, 13_500],
    '0.30 USD rounds half-up' => [-30, 41],   // 40.5 → 41
    '123.45 USD' => [-12_345, 16_666],        // 16 665.75 → 16 666
]);

it('converts an inflow recorded against a contact, keeping the contact on the contra leg', function () {
    $customer = Contact::factory()->create(['display_name' => 'US Customer']);

    $entry = app(RecordStatementLine::class)->handle(fxFeedLine(20_000), $this->income->id, $customer->id);

    fxFeedExpectLeg(fxFeedLeg($entry, $this->usdBank), 27_000, 0, 'USD', 20_000, 0);
    expect(fxFeedLeg($entry, $this->income)->contact_id)->toBe($customer->id);
});

it('keeps the payee-less taxed expense in step: the bank leg is the converted gross', function () {
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();

    $entry = app(RecordStatementLine::class)->handle(fxFeedLine(-10_500), $this->expense->id, taxCodeId: $gst->id);

    expect($entry->source_type)->toBe(Expense::class)
        ->and($entry->isBalanced())->toBeTrue();

    // 105.00 USD gross @ 1.35 = 141.75 CAD leaves the bank, as on the statement.
    fxFeedExpectLeg(fxFeedLeg($entry, $this->usdBank), 0, 14_175, 'USD', 0, 10_500);
    expect(Expense::query()->sole()->home_amount_cents)->toBe(14_175);
});

it('pays a USD bill from the USD account at the payment rate, booking the FX difference', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'US Supplier', 'currency_code' => 'USD']);
    app(ExchangeRateService::class)->setManualRate($this->company, 'USD', '1.30', CarbonImmutable::parse('2026-02-15'));

    $bill = app(SaveBill::class)->handle([
        'contact_id' => $vendor->id,
        'bill_no' => 'US-1',
        'bill_date' => '2026-02-15',
        'due_date' => '2026-03-15',
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Services', 'quantity' => '1', 'unit_price_cents' => 100_000]],
    ]);
    app(BillPoster::class)->post($bill);

    $entry = app(RecordStatementLine::class)->handle(fxFeedLine(-100_000), null, $vendor->id, $bill->id);

    $payment = BillPayment::query()->sole();
    $apControl = Account::query()->findOrFail($this->company->currencies()->where('currency_code', 'USD')->value('ap_account_id'));

    expect($entry->source_type)->toBe(BillPayment::class)
        ->and($payment->currency_code)->toBe('USD')
        ->and($entry->isBalanced())->toBeTrue()
        ->and($bill->fresh()->balanceCents())->toBe(0);

    // AP is cleared at the bill's 1.30; the bank pays 1,000 USD at 1.35 = 1,350.00 CAD.
    fxFeedExpectLeg(fxFeedLeg($entry, $this->usdBank), 0, 135_000, 'USD', 0, 100_000);
    expect((int) fxFeedLeg($entry, $apControl)->debit_cents)->toBe(130_000)
        ->and((int) $entry->lines()->where('account_id', $this->company->exchange_gain_loss_account_id)->value('debit_cents'))->toBe(5_000)
        ->and($apControl->fresh()->balance_cents)->toBe(0)
        ->and($apControl->fresh()->foreignBalanceCents())->toBe(0)
        ->and($this->usdBank->fresh()->balance_cents)->toBe(-135_000)
        ->and($this->usdBank->fresh()->foreignBalanceCents())->toBe(-100_000);
});

it('splits a USD inflow into a deposit converted at the rate', function () {
    $line = fxFeedLine(10_000);

    app(SplitStatementLine::class)->handle($line, [
        ['account_id' => $this->income->id, 'amount_cents' => 6_000],
        ['account_id' => $this->equity->id, 'amount_cents' => 4_000],
    ]);

    $entry = $line->fresh()->createdJournalEntry;

    expect($entry->source_type)->toBe(Deposit::class);
    fxFeedExpectLeg(fxFeedLeg($entry, $this->usdBank), 13_500, 0, 'USD', 10_000, 0);
    fxFeedExpectLeg(fxFeedLeg($entry, $this->income), 0, 8_100, 'USD', 0, 6_000);
    fxFeedExpectLeg(fxFeedLeg($entry, $this->equity), 0, 5_400, 'USD', 0, 4_000);
});

it('splits a USD outflow into an expense converted at the rate', function () {
    $line = fxFeedLine(-10_000);

    app(SplitStatementLine::class)->handle($line, [
        ['account_id' => $this->expense->id, 'amount_cents' => 6_000],
        ['account_id' => $this->expenseB->id, 'amount_cents' => 4_000],
    ]);

    $entry = $line->fresh()->createdJournalEntry;

    expect($entry->source_type)->toBe(Expense::class);
    fxFeedExpectLeg(fxFeedLeg($entry, $this->usdBank), 0, 13_500, 'USD', 0, 10_000);
    fxFeedExpectLeg(fxFeedLeg($entry, $this->expense), 8_100, 0, 'USD', 6_000, 0);
    fxFeedExpectLeg(fxFeedLeg($entry, $this->expenseB), 5_400, 0, 'USD', 4_000, 0);
});

it('pairs a transfer between two USD accounts at the rate with no exchange difference', function () {
    $usdCard = fxFeedAccount('2105', 'USD Visa', AccountSubtype::CreditCard);
    $out = fxFeedLine(-10_000);
    $in = fxFeedLine(10_000, $usdCard);

    app(PairStatementTransfer::class)->handle($out, $in);

    $entry = $out->fresh()->createdJournalEntry;

    expect($entry->source_type)->toBe(Transfer::class)
        ->and($entry->lines()->count())->toBe(2);
    fxFeedExpectLeg(fxFeedLeg($entry, $this->usdBank), 0, 13_500, 'USD', 0, 10_000);
    fxFeedExpectLeg(fxFeedLeg($entry, $usdCard), 13_500, 0, 'USD', 10_000, 0);
});

it('converts every line of a bulk categorize', function () {
    $in = fxFeedLine(10_000);
    $out = fxFeedLine(-5_000);

    expect(app(BulkCategorizeStatementLines::class)->handle([$in->id, $out->id], $this->expense->id))->toBe(2);

    fxFeedExpectLeg(fxFeedLeg($in->fresh()->createdJournalEntry, $this->usdBank), 13_500, 0, 'USD', 10_000, 0);
    fxFeedExpectLeg(fxFeedLeg($out->fresh()->createdJournalEntry, $this->usdBank), 0, 6_750, 'USD', 0, 5_000);
    expect($this->usdBank->fresh()->balance_cents)->toBe(6_750)
        ->and($this->usdBank->fresh()->foreignBalanceCents())->toBe(5_000);
});

it('converts the lines the import wizard adds when the statement is committed', function () {
    $fee = fxFeedLine(-2_500, attributes: [
        'match_status' => StatementLineMatchStatus::Created->value,
        'suggested_account_id' => $this->expense->id,
    ]);

    $rec = app(StatementImportCommitter::class)->commit($this->import->fresh());

    $bankLeg = JournalLine::query()->findOrFail($fee->fresh()->matched_journal_line_id);

    fxFeedExpectLeg($bankLeg, 0, 3_375, 'USD', 0, 2_500);
    expect($rec->markedLineIds())->toBe([$bankLeg->id]);
});

it('converts a line accepted from the For Review feed', function () {
    $line = fxFeedLine(10_000);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set('categories', [$line->id => $this->income->id])
        ->call('accept', $line->id)
        ->assertHasNoErrors();

    fxFeedExpectLeg(fxFeedLeg($line->fresh()->createdJournalEntry, $this->usdBank), 13_500, 0, 'USD', 10_000, 0);
});

it('converts a receipt matched to a USD line from the inbox', function () {
    $item = InboxItem::create([
        'source' => InboxItemSource::Upload,
        'status' => InboxItemStatus::NeedsReview,
        'original_filename' => 'receipt.pdf',
        'mime' => 'application/pdf',
        'created_by_user_id' => $this->user->id,
    ]);
    $line = fxFeedLine(-10_000);

    $expense = app(MatchInboxItemToStatementLine::class)->handle($item, $line, [
        'vendor' => 'US Supplies',
        'lines' => [['account_id' => $this->expense->id, 'amount_cents' => 10_000]],
    ]);

    fxFeedExpectLeg(fxFeedLeg($expense->journalEntry, $this->usdBank), 0, 13_500, 'USD', 0, 10_000);
});

it('posts a category in the same foreign currency with the foreign amount on both legs', function () {
    $usdCard = fxFeedAccount('2105', 'USD Visa', AccountSubtype::CreditCard);

    $entry = app(RecordStatementLine::class)->handle(fxFeedLine(-10_000), $usdCard->id); // card paid from USD chequing

    fxFeedExpectLeg(fxFeedLeg($entry, $usdCard), 13_500, 0, 'USD', 10_000, 0);
    expect($usdCard->fresh()->foreignBalanceCents())->toBe(-10_000);
});

it('refuses a category in a third currency on every path and posts nothing', function () {
    app(EnableCompanyCurrency::class)->handle($this->company, 'EUR');
    $eurCard = fxFeedAccount('2110', 'EUR Visa', AccountSubtype::CreditCard, 'EUR');
    $vendor = Contact::factory()->vendor()->create();
    $line = fxFeedLine(-10_000);

    $message = __('That category is an account in another currency — record a transfer between the two accounts instead.');

    expect(fn () => app(RecordStatementLine::class)->handle($line, $eurCard->id))
        ->toThrow(PostingValidationException::class, $message)
        ->and(fn () => app(RecordStatementLine::class)->handle($line, $eurCard->id, $vendor->id))
        ->toThrow(PostingValidationException::class, $message)
        ->and(fn () => app(SplitStatementLine::class)->handle($line, [
            ['account_id' => $this->expense->id, 'amount_cents' => 4_000],
            ['account_id' => $eurCard->id, 'amount_cents' => 6_000],
        ]))->toThrow(PostingValidationException::class, $message);

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(Expense::query()->count())->toBe(0)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull();
});

it('leaves a home-currency line exactly as before', function () {
    $entry = app(RecordStatementLine::class)->handle(fxFeedLine(10_000, $this->cadBank), $this->income->id);

    fxFeedExpectLeg(fxFeedLeg($entry, $this->cadBank), 10_000, 0, null, 0, 0);
    fxFeedExpectLeg(fxFeedLeg($entry, $this->income), 0, 10_000, null, 0, 0);
});

it('refuses to post a USD line when no rate is known for its date, rather than guess', function () {
    app()->instance(ExchangeRateProvider::class, new NullExchangeRateProvider);
    $line = fxFeedLine(10_000, date: '2026-02-01'); // before the only stored rate

    expect(fn () => app(RecordStatementLine::class)->handle($line, $this->income->id))
        ->toThrow(MissingExchangeRateException::class);

    expect(JournalEntry::query()->count())->toBe(0)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull();
});
