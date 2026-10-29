<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\StatementLineMatchStatus;
use App\Models\Account;
use App\Models\BankRule;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * The For Review feed, the statement import wizard and "Always do this" take a
 * category account id from the browser. It must be one of the organization's
 * own accounts: never another organization's account, and never the AR / AP /
 * Undeposited Funds control accounts the category picker leaves out.
 */

beforeEach(function () {
    // The other organization is created while nothing is bound, so its seeded
    // chart keeps its own company_id.
    app()->forgetInstance('current_company');
    $this->other = Company::factory()->create();

    $otherAccount = fn (AccountSubtype $subtype): Account => Account::withoutGlobalScopes()
        ->where('company_id', $this->other->id)
        ->where('subtype', $subtype->value)
        ->orderBy('code')
        ->firstOrFail();

    $this->otherIncome = $otherAccount(AccountSubtype::Income);
    $this->otherExpense = $otherAccount(AccountSubtype::Expense);

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Accountant->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);

    $this->statementLine = fn (int $amountCents, string $description = 'Card purchase'): BankStatementLine => BankStatementLine::factory()->create([
        'bank_statement_import_id' => $this->import->id,
        'account_id' => $this->bank->id,
        'txn_date' => '2026-06-10',
        'amount_cents' => $amountCents,
        'description' => $description,
        'match_status' => StatementLineMatchStatus::Unmatched->value,
        'created_journal_entry_id' => null,
    ]);

    $this->linesOnOtherAccounts = fn (): int => JournalLine::query()
        ->whereIn('account_id', [$this->otherIncome->id, $this->otherExpense->id])
        ->count();
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('does not accept a feed line into another organization\'s account', function () {
    $line = ($this->statementLine)(12345); // money in: a plain journal entry

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set('categories', [$line->id => $this->otherIncome->id])
        ->call('accept', $line->id);

    $otherIncomeForJune = app(ReportCalculator::class)->totalForType(
        $this->other,
        AccountType::Income,
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    expect(($this->linesOnOtherAccounts)())->toBe(0)
        ->and($otherIncomeForJune)->toBe(0)
        ->and((int) $this->otherIncome->fresh()->balance_cents)->toBe(0)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull();
});

it('does not bulk-categorize feed lines into another organization\'s account', function () {
    $line = ($this->statementLine)(-2500);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set('selected', [$line->id])
        ->set('bulkCategory', $this->otherExpense->id)
        ->call('bulkCategorize');

    expect(($this->linesOnOtherAccounts)())->toBe(0)
        ->and((int) $this->otherExpense->fresh()->balance_cents)->toBe(0)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull();
});

it('does not split a feed line into another organization\'s account', function (int $amountCents) {
    $line = ($this->statementLine)($amountCents); // out: an Expense, in: a Deposit
    $amount = number_format(abs($amountCents) / 100, 2, '.', '');

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('openSplit', $line->id)
        ->set('splits', [
            ['account_id' => $this->expense->id, 'amount' => '10.00', 'tax_code_ids' => []],
            ['account_id' => $this->otherExpense->id, 'amount' => number_format((float) $amount - 10, 2, '.', ''), 'tax_code_ids' => []],
        ])
        ->call('saveSplit');

    expect(($this->linesOnOtherAccounts)())->toBe(0)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull();
})->with([
    'money out' => [-4000],
    'money in' => [4000],
]);

it('does not save an "Always do this" rule that points at another organization\'s account', function (string $page) {
    $line = ($this->statementLine)(-1999, 'ACME HOSTING INVOICE 4471');

    $component = Livewire::test("pages::banking.{$page}", ['company' => $this->company]);

    $page === 'review'
        ? $component->set('categories', [$line->id => $this->otherExpense->id])
        : $component->set('account_id', $this->bank->id)->set('importId', $this->import->id)->set('lineCategory', [$line->id => $this->otherExpense->id]);

    $component->call('createRule', $line->id);

    expect(BankRule::query()->where('action_account_id', $this->otherExpense->id)->exists())->toBeFalse()
        ->and($line->fresh()->suggested_account_id)->not->toBe($this->otherExpense->id);
})->with(['review', 'import']);

it('does not let the import wizard post a line to another organization\'s account', function () {
    $line = ($this->statementLine)(7777);
    $this->import->update(['status' => 'ready']);

    Livewire::test('pages::banking.import', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->set('importId', $this->import->id)
        ->set("lineCategory.{$line->id}", $this->otherIncome->id)
        ->call('commitImport');

    expect($line->fresh()->suggested_account_id)->not->toBe($this->otherIncome->id)
        ->and(($this->linesOnOtherAccounts)())->toBe(0)
        ->and((int) $this->otherIncome->fresh()->balance_cents)->toBe(0);
});

it('does not post a feed line straight to the accounts receivable control account', function () {
    $line = ($this->statementLine)(5000);
    $receivable = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->firstOrFail();

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set('categories', [$line->id => $receivable->id])
        ->call('accept', $line->id);

    expect(JournalLine::query()->where('account_id', $receivable->id)->count())->toBe(0)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull();
});

it('still accepts a feed line into the organization\'s own expense account', function () {
    $line = ($this->statementLine)(-2500);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set('categories', [$line->id => $this->expense->id])
        ->call('accept', $line->id);

    expect($line->fresh()->created_journal_entry_id)->not->toBeNull()
        ->and(JournalLine::query()->where('account_id', $this->expense->id)->where('debit_cents', 2500)->exists())->toBeTrue();
});
