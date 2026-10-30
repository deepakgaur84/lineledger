<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\RunHomeCurrencyAdjustment;
use App\Actions\Accounting\SaveAccount;
use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Accounting\SaveJournalEntryTemplate;
use App\Actions\Accounting\SaveRecurringJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\DepositStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OpeningBalanceRow;
use App\Models\OpeningBalanceState;
use App\Models\User;
use App\Services\Currency\ExchangeRateService;
use App\Services\OpeningBalances\OpeningBalanceJournalSynchronizer;
use App\Services\OpeningBalances\OpeningBalanceStatusBuilder;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\InvoiceReconciler;
use App\Services\Posting\JournalPoster;
use App\Services\Recurring\RecurringJournalEntryGenerator;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Regression for D1Ledger-04. The account form refuses an opening balance on a
 * foreign bank account and the docs say to record it with a journal entry at
 * the correct exchange rate, but journal lines carried no foreign units: the
 * form had no foreign amount or rate, the API dropped them from validated(),
 * and SaveJournalEntry only kept a memo nobody could send. The opening balance
 * landed as home cents with zero USD behind it, so the first Home Currency
 * Adjustment after any real USD activity wrote the whole balance off as an
 * unrealized loss — at an unchanged rate. Manual write-offs of foreign
 * invoices, which InvoiceReconciler reads in foreign units, never moved the
 * customer's foreign balance either.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-15 12:00:00'));

    // Every rate these tests use is entered by hand; a lookup that would go to
    // the provider finds nothing.
    Http::fake(['*' => Http::response(['rates' => []])]);

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
    $this->company->refresh();

    app(ExchangeRateService::class)->setManualRate($this->company, 'USD', '1.35', CarbonImmutable::parse('2026-01-01'));

    $this->obe = Account::query()->where('code', '3000')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->usdBank = app(SaveAccount::class)->handle([
        'code' => '1015', 'name' => 'USD Chequing', 'subtype' => AccountSubtype::Bank->value,
        'currency_code' => 'USD',
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

/** Unrealized gain (negative) or loss (positive) booked on or before a date. */
function fxjeUnrealizedAsOf(Company $company, string $date): int
{
    return (int) DB::table('journal_lines')
        ->where('account_id', $company->unrealized_gain_loss_account_id)
        ->where('is_posted', true)
        ->where('entry_date', '<=', $date)
        ->sum(DB::raw('debit_cents - credit_cents'));
}

/** Post the documented opening balance: 10,000 USD at 1.35 against Opening Balance Equity. */
function fxjePostOpeningBalance(Account $usdBank, Account $obe): JournalEntry
{
    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-03-01',
        'memo' => 'Opening balance USD Chequing',
        'lines' => [
            ['account_id' => $usdBank->id, 'foreign_debit_cents' => 1_000_000, 'fx_rate' => '1.35'],
            ['account_id' => $obe->id, 'debit_cents' => 0, 'credit_cents' => 1_350_000],
        ],
    ]);

    return app(JournalPoster::class)->post($entry);
}

function fxjeApiKey(Company $company): array
{
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'FX journal');

    return ['Authorization' => "Bearer {$plain}"];
}

it('records the documented foreign opening balance in its own currency, so revaluation at an unchanged rate books nothing', function () {
    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-03-01')
        ->set('memo', 'Opening balance USD Chequing (10,000 USD @ 1.35)')
        ->set('lines.0.account_id', $this->usdBank->id)
        // The rate in force on the entry date fills in, as a document would lock it.
        ->assertSet('lines.0.fx_rate', '1.35')
        ->set('lines.0.foreign_debit', '10000.00')
        // The home equivalent is what balances.
        ->assertSet('lines.0.debit', '13500.00')
        ->set('lines.1.account_id', $this->obe->id)
        ->set('lines.1.credit', '13500.00')
        ->call('postEntry')
        ->assertHasNoErrors();

    $line = JournalLine::query()->where('account_id', $this->usdBank->id)->sole();

    expect($line->currency_code)->toBe('USD')
        ->and((string) $line->fx_rate)->toStartWith('1.35')
        ->and($line->foreign_debit_cents)->toBe(1_000_000)
        ->and($line->debit_cents)->toBe(1_350_000)
        ->and((int) $this->usdBank->fresh()->balance_cents)->toBe(1_350_000)
        ->and($this->usdBank->fresh()->foreignBalanceCents())->toBe(1_000_000);

    // One ordinary USD deposit of 1,000 USD at the same 1.35.
    $deposit = Deposit::create([
        'bank_account_id' => $this->usdBank->id, 'deposit_no' => 'DEP-1',
        'deposit_date' => '2026-03-10', 'amount_cents' => 100_000,
        'fx_rate' => '1.35', 'status' => DepositStatus::Draft,
    ]);
    $deposit->lines()->create(['account_id' => $this->obe->id, 'description' => 'Owner contribution', 'amount_cents' => 100_000, 'line_order' => 0]);
    app(DepositPoster::class)->post($deposit);

    // Month end, the rate has NOT moved: nothing to revalue.
    $revaluation = app(RunHomeCurrencyAdjustment::class)->handle($this->company, CarbonImmutable::parse('2026-03-31'), ['USD' => '1.35']);

    expect($revaluation)->toBeNull()
        ->and(fxjeUnrealizedAsOf($this->company, '2026-03-31'))->toBe(0);
});

it('revalues only the real foreign exposure when the rate moves', function () {
    fxjePostOpeningBalance($this->usdBank, $this->obe);

    app(RunHomeCurrencyAdjustment::class)->handle($this->company, CarbonImmutable::parse('2026-03-31'), ['USD' => '1.40']);

    // 10,000 USD from 1.35 to 1.40 is a 500.00 CAD gain — not a 13,500.00 loss.
    expect(fxjeUnrealizedAsOf($this->company, '2026-03-31'))->toBe(-50_000)
        ->and(fxjeUnrealizedAsOf($this->company, '2026-04-01'))->toBe(0);
});

it('keeps the foreign amount and rate an API client sends on a journal line', function () {
    $headers = fxjeApiKey($this->company);
    app()->forgetInstance('current_company');

    $response = $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-03-01',
        'memo' => 'Opening balance USD Chequing',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'debit_cents' => 1_350_000, 'credit_cents' => 0,
                'currency_code' => 'USD', 'fx_rate' => '1.35', 'foreign_debit_cents' => 1_000_000, 'foreign_credit_cents' => 0],
            ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 1_350_000],
        ],
    ], $headers)
        ->assertStatus(201)
        ->assertJsonPath('data.lines.0.currency_code', 'USD')
        ->assertJsonPath('data.lines.0.fx_rate', '1.35')
        ->assertJsonPath('data.lines.0.foreign_debit_cents', 1_000_000)
        ->assertJsonPath('data.lines.1.currency_code', null)
        ->assertJsonPath('data.lines.1.foreign_credit_cents', 0);

    $line = JournalLine::query()
        ->where('journal_entry_id', $response->json('data.id'))
        ->where('account_id', $this->usdBank->id)
        ->sole();

    expect($line->currency_code)->toBe('USD')
        ->and($line->foreign_debit_cents)->toBe(1_000_000)
        ->and($line->debit_cents)->toBe(1_350_000);
});

it('works out the home amount of an API line that sends only its foreign amount', function () {
    $headers = fxjeApiKey($this->company);
    app()->forgetInstance('current_company');

    $response = $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-03-01',
        'lines' => [
            // No rate: the one in force on the entry date, 1.35.
            ['account_id' => $this->usdBank->id, 'debit_cents' => 0, 'credit_cents' => 0, 'foreign_debit_cents' => 1_000_000],
            ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 1_350_000],
        ],
    ], $headers)->assertStatus(201);

    expect($response->json('data.is_posted'))->toBeTrue()
        ->and($response->json('data.lines.0.debit_cents'))->toBe(1_350_000)
        ->and($response->json('data.lines.0.fx_rate'))->toBe('1.35');
});

it('implies the rate from the home and foreign amounts an API line sends without one', function () {
    $headers = fxjeApiKey($this->company);
    app()->forgetInstance('current_company');

    // The old system carried 10,000 USD at 13,512.34 CAD.
    $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-03-01',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'debit_cents' => 1_351_234, 'credit_cents' => 0, 'foreign_debit_cents' => 1_000_000],
            ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 1_351_234],
        ],
    ], $headers)
        ->assertStatus(201)
        ->assertJsonPath('data.lines.0.debit_cents', 1_351_234)
        ->assertJsonPath('data.lines.0.fx_rate', '1.351234')
        ->assertJsonPath('data.lines.0.foreign_debit_cents', 1_000_000);
});

it('refuses foreign amounts that contradict the line or its account', function (array $line, string $field) {
    $headers = fxjeApiKey($this->company);
    app()->forgetInstance('current_company');

    $resolved = array_map(fn ($value) => $value instanceof Closure ? $value() : $value, $line);

    $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-03-01',
        'lines' => [
            $resolved,
            ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 1_350_000],
        ],
    ], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors(["lines.0.{$field}"]);

    expect(JournalEntry::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'home that is not foreign × rate' => [
        ['account_id' => fn () => test()->usdBank->id, 'debit_cents' => 1_350_000, 'credit_cents' => 0, 'foreign_debit_cents' => 100_000, 'fx_rate' => '1.35'],
        'foreign_debit_cents',
    ],
    'home and foreign on opposite sides' => [
        ['account_id' => fn () => test()->usdBank->id, 'debit_cents' => 1_350_000, 'credit_cents' => 0, 'foreign_credit_cents' => 1_000_000],
        'foreign_debit_cents',
    ],
    'a currency other than the account’s' => [
        ['account_id' => fn () => test()->usdBank->id, 'debit_cents' => 1_350_000, 'credit_cents' => 0, 'currency_code' => 'EUR'],
        'currency_code',
    ],
    'a foreign amount on a home-currency account' => [
        ['account_id' => fn () => Account::query()->withoutGlobalScopes()->where('company_id', test()->company->id)->where('code', '1000')->value('id'), 'debit_cents' => 1_350_000, 'credit_cents' => 0, 'foreign_debit_cents' => 1_000_000],
        'foreign_debit_cents',
    ],
    'a rate with more than eight decimals' => [
        ['account_id' => fn () => test()->usdBank->id, 'debit_cents' => 1_350_000, 'credit_cents' => 0, 'fx_rate' => '1.350000001'],
        'fx_rate',
    ],
]);

it('asks for the rate when none is on file for the entry date', function () {
    $headers = fxjeApiKey($this->company);
    app()->forgetInstance('current_company');

    // The only USD rate starts on 2026-01-01, and the provider has nothing.
    $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2025-12-31',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'debit_cents' => 0, 'credit_cents' => 0, 'foreign_debit_cents' => 1_000_000],
            ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 1_350_000],
        ],
    ], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.fx_rate']);
});

it('balances a posted entry edited through the API on the home amounts its lines work out', function () {
    $entry = fxjePostOpeningBalance($this->usdBank, $this->obe);
    $headers = fxjeApiKey($this->company);
    app()->forgetInstance('current_company');

    $this->patchJson("/api/v1/journal-entries/{$entry->id}", [
        'entry_date' => '2026-03-01',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'debit_cents' => 0, 'credit_cents' => 0, 'foreign_debit_cents' => 1_200_000, 'fx_rate' => '1.35'],
            ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 1_620_000],
        ],
    ], $headers)
        ->assertStatus(200)
        ->assertJsonPath('data.lines.0.debit_cents', 1_620_000)
        ->assertJsonPath('data.lines.0.foreign_debit_cents', 1_200_000);

    app()->instance('current_company', $this->company);
    expect($this->usdBank->fresh()->foreignBalanceCents())->toBe(1_200_000)
        ->and((int) $this->usdBank->fresh()->balance_cents)->toBe(1_620_000);
});

it('gives a home-only line on a foreign account its foreign amount at the entry date’s rate', function () {
    // What a recurring schedule, a template or an older API client sends.
    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-03-01',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'debit_cents' => 1_350_000, 'credit_cents' => 0],
            ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 1_350_000],
        ],
    ]);

    $bankLine = $entry->lines->firstWhere('account_id', $this->usdBank->id);
    $obeLine = $entry->lines->firstWhere('account_id', $this->obe->id);

    expect($bankLine->currency_code)->toBe('USD')
        ->and($bankLine->foreign_debit_cents)->toBe(1_000_000)
        ->and($bankLine->debit_cents)->toBe(1_350_000)
        // A home-currency account carries no foreign memo.
        ->and($obeLine->currency_code)->toBeNull()
        ->and($obeLine->foreign_credit_cents)->toBe(0);
});

it('plugs the rounding cent when foreign lines balance before rounding', function () {
    $usdCard = app(SaveAccount::class)->handle([
        'code' => '2105', 'name' => 'USD Card', 'subtype' => AccountSubtype::CreditCard->value,
        'currency_code' => 'USD',
    ]);

    // 0.01 USD at 1.5 is 1.5 cents: each debit rounds up to 2, the 0.02 credit
    // is exactly 3. In USD the entry balances; in CAD it would miss by a cent,
    // which goes onto the largest line — the credit, now 4.
    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-03-01',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'foreign_debit_cents' => 1, 'fx_rate' => '1.5'],
            ['account_id' => $this->usdBank->id, 'foreign_debit_cents' => 1, 'fx_rate' => '1.5'],
            ['account_id' => $usdCard->id, 'foreign_credit_cents' => 2, 'fx_rate' => '1.5'],
        ],
    ]);

    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->lines->pluck('debit_cents')->all())->toBe([2, 2, 0])
        ->and($entry->lines->pluck('credit_cents')->all())->toBe([0, 0, 4])
        ->and($entry->lines->sum('foreign_debit_cents'))->toBe(2)
        ->and($entry->lines->sum('foreign_credit_cents'))->toBe(2);

    app(JournalPoster::class)->post($entry);
    expect($entry->fresh()->isPosted())->toBeTrue();
});

it('saves a posted foreign entry edited unchanged exactly as it was', function () {
    // 333.33 USD at 1.35 is 449.9955 CAD; the line carries 450.00.
    $entry = app(JournalPoster::class)->post(app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-03-01',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'debit_cents' => 45_000, 'credit_cents' => 0, 'foreign_debit_cents' => 33_333, 'fx_rate' => '1.35'],
            ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 45_000],
        ],
    ]));

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry])
        ->assertSet('lines.0.foreign_debit', '333.33')
        ->assertSet('lines.0.fx_rate', '1.35')
        ->assertSet('lines.0.debit', '450.00')
        ->call('saveChanges')
        ->assertHasNoErrors();

    $line = $entry->fresh()->lines->firstWhere('account_id', $this->usdBank->id);

    expect($line->debit_cents)->toBe(45_000)
        ->and($line->foreign_debit_cents)->toBe(33_333)
        ->and((float) $line->fx_rate)->toBe(1.35);
});

it('refreshes a filled-in rate when the entry date moves, but keeps one typed by hand', function () {
    app(ExchangeRateService::class)->setManualRate($this->company, 'USD', '1.40', CarbonImmutable::parse('2026-03-10'));

    $component = Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-03-01')
        ->set('lines.0.account_id', $this->usdBank->id)
        ->set('lines.0.foreign_debit', '100.00')
        ->assertSet('lines.0.fx_rate', '1.35')
        ->assertSet('lines.0.debit', '135.00')
        ->set('entryDate', '2026-03-12')
        ->assertSet('lines.0.fx_rate', '1.4')
        ->assertSet('lines.0.debit', '140.00');

    $component->set('lines.0.fx_rate', '1.38')
        ->assertSet('lines.0.debit', '138.00')
        ->set('entryDate', '2026-03-01')
        ->assertSet('lines.0.fx_rate', '1.38')
        ->assertSet('lines.0.debit', '138.00');
});

it('opens a line saved without its foreign amount in the account’s currency, keeping its home amount', function () {
    // A posted line from before journal lines recorded foreign amounts.
    $entry = JournalEntry::create(['entry_no' => 'JE-OLD', 'entry_date' => '2026-03-01', 'memo' => 'Opening balance']);
    $entry->lines()->createMany([
        ['account_id' => $this->usdBank->id, 'debit_cents' => 1_350_000, 'credit_cents' => 0, 'line_order' => 0],
        ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 1_350_000, 'line_order' => 1],
    ]);
    app(JournalPoster::class)->post($entry);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry])
        ->assertSet('lines.0.debit', '13500.00')
        ->assertSet('lines.0.fx_rate', '1.35')
        ->assertSet('lines.0.foreign_debit', '10000.00')
        // Correct it to the bank statement and the home amount follows.
        ->set('lines.0.foreign_debit', '10000.00')
        ->call('saveChanges')
        ->assertHasNoErrors();

    expect($this->usdBank->fresh()->foreignBalanceCents())->toBe(1_000_000)
        ->and((int) $this->usdBank->fresh()->balance_cents)->toBe(1_350_000);
});

it('shows a foreign line’s amount and rate on the entry', function () {
    $entry = fxjePostOpeningBalance($this->usdBank, $this->obe);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->assertSeeHtml('data-test="entry-show-line-foreign"')
        ->assertSee('USD 10,000.00 @ 1.35');
});

it('lets a manual write-off of a foreign invoice move the customer’s foreign balance', function () {
    $customer = Contact::create(['display_name' => 'Stateside Imports', 'is_customer' => true, 'currency_code' => 'USD']);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $usdAr = Account::query()->find($this->company->currencies()->where('currency_code', 'USD')->value('ar_account_id'));

    $invoice = Invoice::create([
        'contact_id' => $customer->id, 'invoice_no' => 'INV-USD',
        'invoice_date' => '2026-03-01', 'due_date' => '2026-03-31',
        'currency_code' => 'USD', 'fx_rate' => '1.35',
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id, 'description' => 'Sale', 'quantity' => '1',
        'unit_price_cents' => 100_000, 'line_subtotal_cents' => 100_000,
        'line_tax_cents' => 0, 'line_total_cents' => 100_000, 'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    // Write 100 USD of it off at the invoice's own rate.
    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-03-12')
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.debit', '135.00')
        ->set('lines.1.account_id', $usdAr->id)
        ->set('lines.1.contact_id', $customer->id)
        ->set('lines.1.foreign_credit', '100.00')
        ->assertSet('lines.1.credit', '135.00')
        ->call('postEntry')
        ->assertHasNoErrors();

    // Saved in the order the form showed the lines.
    expect(JournalEntry::query()->latest('id')->firstOrFail()->lines->pluck('account_id')->all())
        ->toBe([$this->expense->id, $usdAr->id]);

    $reconciler = app(InvoiceReconciler::class);

    expect($reconciler->availableToReconcile($this->company, $customer->id, CarbonImmutable::parse('2026-03-15')))->toBe(10_000)
        ->and($reconciler->reconcileInvoice($invoice->fresh()))->toBe(10_000)
        ->and($invoice->fresh()->balanceCents())->toBe(90_000);
});

it('leaves a foreign-currency account’s opening target to a journal entry in its currency', function () {
    $state = OpeningBalanceState::create(['company_id' => $this->company->id, 'as_of_date' => '2026-02-28']);
    $capital = Account::query()->where('code', '3100')->firstOrFail();

    OpeningBalanceRow::create(['opening_balance_state_id' => $state->id, 'company_id' => $this->company->id, 'account_id' => $this->usdBank->id, 'debit_cents' => 1_350_000, 'credit_cents' => 0]);
    OpeningBalanceRow::create(['opening_balance_state_id' => $state->id, 'company_id' => $this->company->id, 'account_id' => $capital->id, 'debit_cents' => 0, 'credit_cents' => 1_350_000]);

    $maintained = app(OpeningBalanceJournalSynchronizer::class)->apply($state->refresh());

    // The workspace posts no home-only line on the USD account…
    expect($maintained->lines->pluck('account_id'))->not->toContain($this->usdBank->id);

    Livewire::test('pages::opening-balances.trial-balance', ['company' => $this->company])
        ->assertSee('target for a USD journal entry')
        ->assertSeeHtml('data-test="ob-tb-foreign-hint"');

    // …the documented journal entry in USD meets the target instead.
    app(JournalPoster::class)->post(app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-02-28',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'foreign_debit_cents' => 1_000_000, 'fx_rate' => '1.35'],
            ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 1_350_000],
        ],
    ]));

    $status = app(OpeningBalanceStatusBuilder::class)->build($state->refresh());
    $bankRow = collect($status['rows'])->firstWhere('account_id', $this->usdBank->id);

    expect($bankRow['variance'])->toBe(0)
        ->and($status['obe']['residual'])->toBe(0)
        ->and($this->usdBank->fresh()->foreignBalanceCents())->toBe(1_000_000);
});

it('gives a recurring draft on a foreign account its foreign amount, and pauses when there is no rate', function () {
    $schedule = app(SaveRecurringJournalEntry::class)->handle([
        'name' => 'Bank fee', 'memo' => 'Monthly USD bank fee', 'frequency' => 'monthly',
        'start_date' => '2026-03-15', 'day_of_month' => 15, 'end_type' => 'never',
        'lines' => [
            ['account_id' => $this->expense->id, 'debit_cents' => 1_350, 'credit_cents' => 0],
            ['account_id' => $this->usdBank->id, 'debit_cents' => 0, 'credit_cents' => 1_350],
        ],
    ]);

    $draft = app(RecurringJournalEntryGenerator::class)->generateDue($schedule, CarbonImmutable::parse('2026-03-15'))->sole();
    $bankLine = $draft->lines->firstWhere('account_id', $this->usdBank->id);

    expect($bankLine->currency_code)->toBe('USD')
        ->and($bankLine->foreign_credit_cents)->toBe(1_000);

    // No USD rate on file before 2026-01-01: the schedule pauses and says why.
    $early = app(SaveRecurringJournalEntry::class)->handle([
        'name' => 'Old fee', 'memo' => 'Old fee', 'frequency' => 'monthly',
        'start_date' => '2025-12-15', 'day_of_month' => 15, 'end_type' => 'never',
        'lines' => [
            ['account_id' => $this->expense->id, 'debit_cents' => 1_350, 'credit_cents' => 0],
            ['account_id' => $this->usdBank->id, 'debit_cents' => 0, 'credit_cents' => 1_350],
        ],
    ]);

    $created = app(RecurringJournalEntryGenerator::class)->generateDue($early, CarbonImmutable::parse('2025-12-15'));

    expect($created)->toBeEmpty()
        ->and($early->fresh()->is_active)->toBeFalse()
        ->and($early->fresh()->paused_reason)->toContain('USD');
});

it('reports a missing rate on the line rather than failing the save', function () {
    expect(fn () => app(SaveJournalEntry::class)->handle([
        'entry_date' => '2025-12-31',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'debit_cents' => 1_350_000, 'credit_cents' => 0],
            ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 1_350_000],
        ],
    ]))->toThrow(ValidationException::class);

    expect(JournalEntry::query()->count())->toBe(0);
});

it('fills a template’s foreign-account line in the account’s currency, keeping the template’s amounts', function () {
    $template = app(SaveJournalEntryTemplate::class)->handle([
        'name' => 'USD top-up',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'debit_cents' => 13_500, 'credit_cents' => 0],
            ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 13_500],
        ],
    ]);

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-03-01')
        ->set('template_id', $template->id)
        ->assertSet('lines.0.debit', '135.00')
        ->assertSet('lines.0.fx_rate', '1.35')
        ->assertSet('lines.0.foreign_debit', '100.00')
        ->call('postEntry')
        ->assertHasNoErrors();

    expect($this->usdBank->fresh()->foreignBalanceCents())->toBe(10_000)
        ->and((int) $this->usdBank->fresh()->balance_cents)->toBe(13_500);
});

it('drops the foreign amount when a line moves to a home-currency account, keeping its home amount', function () {
    $chequing = Account::query()->where('code', '1000')->firstOrFail();

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-03-01')
        ->set('lines.0.account_id', $this->usdBank->id)
        ->set('lines.0.foreign_debit', '100.00')
        ->assertSet('lines.0.debit', '135.00')
        ->set('lines.0.account_id', $chequing->id)
        ->assertSet('lines.0.debit', '135.00')
        ->assertSet('lines.0.foreign_debit', '')
        ->assertSet('lines.0.fx_rate', '')
        // …and back: the home amount carries over into USD at the day's rate.
        ->set('lines.0.account_id', $this->usdBank->id)
        ->assertSet('lines.0.fx_rate', '1.35')
        ->assertSet('lines.0.foreign_debit', '100.00')
        ->assertSet('lines.0.debit', '135.00');
});

it('asks for the rate on a foreign line when none is on file', function () {
    Livewire::test('pages::journal.form', ['company' => $this->company])
        // No USD rate before 2026-01-01, and the provider has nothing.
        ->set('entryDate', '2025-12-31')
        ->set('lines.0.account_id', $this->usdBank->id)
        ->assertSet('lines.0.fx_rate', '')
        ->set('lines.0.foreign_debit', '100.00')
        ->set('lines.1.account_id', $this->obe->id)
        ->set('lines.1.credit', '135.00')
        ->call('saveDraft')
        ->assertHasErrors(['lines.0.fx_rate' => 'required']);

    expect(JournalEntry::query()->count())->toBe(0);
});

it('renders a foreign line’s amount, rate and home equivalent', function () {
    $html = Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-03-01')
        ->set('lines.0.account_id', $this->usdBank->id)
        ->set('lines.0.foreign_debit', '10000.00')
        ->html();

    // Every Flux tag compiled (a directive inside one would leak it as text).
    expect($html)->not->toContain('<flux:')
        ->toContain('data-test="line-foreign-debit"')
        ->toContain('data-test="line-fx-rate"')
        ->toContain('1 USD = 1.35 CAD')
        ->toContain('data-test="line-home-debit"')
        ->toContain('13,500.00')
        // The home line keeps its own inputs.
        ->toContain('data-test="line-debit"');
});

/**
 * The four foreign memo columns of each line, by line order.
 *
 * @return list<array{currency_code: ?string, fx_rate: ?string, foreign_debit_cents: int, foreign_credit_cents: int}>
 */
function fxjeMemos(JournalEntry $entry): array
{
    return $entry->fresh('lines')->lines->sortBy('line_order')->values()->map(fn (JournalLine $line): array => [
        'currency_code' => $line->currency_code,
        'fx_rate' => $line->fx_rate !== null ? Currency::normalizeRate($line->fx_rate) : null,
        'foreign_debit_cents' => (int) $line->foreign_debit_cents,
        'foreign_credit_cents' => (int) $line->foreign_credit_cents,
    ])->all();
}

/**
 * An entry whose lines already carry their foreign memo, written straight to
 * the table the way a reversal copies it (foreign debit and credit swapped):
 * the USD bank and the USD receivable, plus a document-style memo on a home
 * income leg — 2 cents off its conversion, as a document's rounding plug can
 * leave one — and no memo on Opening Balance Equity.
 *
 * @param  array<string, mixed>  $header
 */
function fxjeSeedMemoEntry(Company $company, Account $usdBank, Account $obe, array $header): JournalEntry
{
    $customer = Contact::create(['display_name' => 'Stateside Imports', 'is_customer' => true, 'currency_code' => 'USD']);
    $usdAr = Account::query()->find($company->currencies()->where('currency_code', 'USD')->value('ar_account_id'));
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

    $entry = JournalEntry::create(['entry_date' => '2026-03-12', 'memo' => 'Reversal of JE-FX', ...$header]);
    $entry->lines()->createMany([
        ['account_id' => $usdBank->id, 'debit_cents' => 0, 'credit_cents' => 1_350_000, 'line_order' => 0,
            'currency_code' => 'USD', 'fx_rate' => '1.35', 'foreign_debit_cents' => 0, 'foreign_credit_cents' => 1_000_000],
        ['account_id' => $income->id, 'debit_cents' => 135_002, 'credit_cents' => 0, 'line_order' => 1,
            'currency_code' => 'USD', 'fx_rate' => '1.35', 'foreign_debit_cents' => 100_000, 'foreign_credit_cents' => 0],
        ['account_id' => $usdAr->id, 'contact_id' => $customer->id, 'debit_cents' => 0, 'credit_cents' => 135_000, 'line_order' => 2,
            'currency_code' => 'USD', 'fx_rate' => '1.35', 'foreign_debit_cents' => 0, 'foreign_credit_cents' => 100_000],
        ['account_id' => $obe->id, 'debit_cents' => 1_349_998, 'credit_cents' => 0, 'line_order' => 3],
    ]);

    return $entry->fresh('lines');
}

it('keeps every line’s foreign memo when a draft carrying one is posted from the journal form', function () {
    $original = fxjePostOpeningBalance($this->usdBank, $this->obe);
    $draft = fxjeSeedMemoEntry($this->company, $this->usdBank, $this->obe, ['entry_no' => 'JE-REV', 'reverses_entry_id' => $original->id]);
    $before = fxjeMemos($draft);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $draft])
        ->call('postEntry')
        ->assertHasNoErrors();

    $draft->refresh();

    expect($draft->isPosted())->toBeTrue()
        ->and(fxjeMemos($draft))->toBe($before)
        ->and($draft->lines->sortBy('line_order')->pluck('debit_cents')->all())->toBe([0, 135_002, 0, 1_349_998])
        // The reversal takes the opening balance's 10,000 USD back out.
        ->and($this->usdBank->fresh()->foreignBalanceCents())->toBe(0);
});

it('keeps every line’s foreign memo when a posted entry carrying one is saved unchanged', function () {
    $entry = fxjeSeedMemoEntry($this->company, $this->usdBank, $this->obe, ['entry_no' => 'JE-MEMO']);
    app(JournalPoster::class)->post($entry);
    $before = fxjeMemos($entry);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry->fresh()])
        ->assertSeeHtml('data-test="line-foreign-memo"')
        ->call('saveChanges')
        ->assertHasNoErrors();

    expect(fxjeMemos($entry))->toBe($before);
});

it('drops a home line’s saved memo once its amount changes', function () {
    $entry = fxjeSeedMemoEntry($this->company, $this->usdBank, $this->obe, ['entry_no' => 'JE-MEMO']);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry])
        ->set('lines.1.debit', '1400.00')
        ->set('lines.3.debit', '13450.00')
        ->call('postEntry')
        ->assertHasNoErrors();

    $memos = fxjeMemos($entry);

    expect($memos[1]['currency_code'])->toBeNull()
        ->and($memos[1]['foreign_debit_cents'])->toBe(0)
        // The foreign-account lines keep theirs.
        ->and($memos[0]['foreign_credit_cents'])->toBe(1_000_000)
        ->and($memos[2]['foreign_credit_cents'])->toBe(100_000);
});

it('gives a saved memo without a rate the one its amounts imply', function () {
    $entry = JournalEntry::create(['entry_no' => 'JE-NORATE', 'entry_date' => '2026-03-12']);
    $entry->lines()->createMany([
        ['account_id' => $this->usdBank->id, 'debit_cents' => 1_351_234, 'credit_cents' => 0, 'line_order' => 0,
            'currency_code' => 'USD', 'fx_rate' => null, 'foreign_debit_cents' => 1_000_000, 'foreign_credit_cents' => 0],
        ['account_id' => $this->obe->id, 'debit_cents' => 0, 'credit_cents' => 1_351_234, 'line_order' => 1],
    ]);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry->fresh()])
        ->assertSet('lines.0.fx_rate', '1.351234')
        ->call('postEntry')
        ->assertHasNoErrors();

    $line = $entry->fresh('lines')->lines->firstWhere('account_id', $this->usdBank->id);

    expect($line->debit_cents)->toBe(1_351_234)
        ->and($line->foreign_debit_cents)->toBe(1_000_000)
        ->and(Currency::normalizeRate($line->fx_rate))->toBe('1.351234');
});

it('keeps a complete memo an API client sends on a home-currency line, and refuses a partial one', function () {
    $headers = fxjeApiKey($this->company);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    app()->forgetInstance('current_company');

    $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-03-01',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'debit_cents' => 0, 'credit_cents' => 0, 'foreign_debit_cents' => 100_000, 'fx_rate' => '1.35'],
            ['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 135_000,
                'currency_code' => 'USD', 'fx_rate' => '1.35', 'foreign_credit_cents' => 100_000],
        ],
    ], $headers)
        ->assertStatus(201)
        ->assertJsonPath('data.lines.1.currency_code', 'USD')
        ->assertJsonPath('data.lines.1.foreign_credit_cents', 100_000);

    $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-03-01',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'debit_cents' => 0, 'credit_cents' => 0, 'foreign_debit_cents' => 100_000, 'fx_rate' => '1.35'],
            ['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 135_000, 'fx_rate' => '1.35'],
        ],
    ], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.1.fx_rate']);
});
