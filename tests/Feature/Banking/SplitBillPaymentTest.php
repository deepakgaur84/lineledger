<?php

use App\Actions\Banking\SplitStatementLine;
use App\Actions\Purchasing\SaveBill;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Posting\BillPoster;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
    $this->vendor = Contact::factory()->vendor()->create();
    $this->expense = Account::query()->where('is_active', true)->selectableForItemAccount()->orderBy('code')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function splitOutflow(int $cents): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => test()->import->id,
        'account_id' => test()->bank->id,
        'txn_date' => now()->toDateString(),
        'amount_cents' => -$cents,
        'description' => 'SPLIT OUT '.$cents,
        'match_status' => StatementLineMatchStatus::Unmatched->value,
        'created_journal_entry_id' => null,
    ]);
}

function splitOpenBill(Contact $vendor, int $cents)
{
    $bill = app(SaveBill::class)->handle([
        'contact_id' => $vendor->id,
        'bill_no' => 'BILL-'.fake()->unique()->numerify('####'),
        'bill_date' => '2026-06-01',
        'due_date' => '2026-06-15',
        'lines' => [['account_id' => test()->expense->id, 'quantity' => '1', 'unit_price_cents' => $cents]],
    ]);
    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('pays a bill as one part of an outflow split with a single bank leg', function () {
    $bill = splitOpenBill($this->vendor, 40000);
    $line = splitOutflow(100000);

    app(SplitStatementLine::class)->handle($line, [
        ['bill_id' => $bill->id, 'amount_cents' => 40000],
        ['account_id' => $this->expense->id, 'amount_cents' => 60000],
    ], $this->vendor->id);

    $line->refresh();
    $bankLegs = JournalLine::query()->where('account_id', $this->bank->id)->where('credit_cents', '>', 0)->get();

    expect($line->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($line->matchedJournalLine->credit_cents)->toBe(100000)
        ->and($bankLegs)->toHaveCount(1)
        ->and(BillPayment::query()->count())->toBe(1)
        ->and($bill->fresh()->balanceCents())->toBe(0);

    // The clearing account nets to nothing.
    $clearing = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $net = JournalLine::query()->where('account_id', $clearing->id)->selectRaw('sum(debit_cents) - sum(credit_cents) as net')->value('net');
    expect((int) $net)->toBe(0);
});

it('allows a partial bill payment inside a split', function () {
    $bill = splitOpenBill($this->vendor, 40000);

    app(SplitStatementLine::class)->handle(splitOutflow(30000), [
        ['bill_id' => $bill->id, 'amount_cents' => 10000],
        ['account_id' => $this->expense->id, 'amount_cents' => 20000],
    ], $this->vendor->id);

    expect($bill->fresh()->balanceCents())->toBe(30000);
});

it('refuses a bill part without a vendor, and leaves nothing behind', function () {
    $bill = splitOpenBill($this->vendor, 10000);
    $line = splitOutflow(30000);

    expect(fn () => app(SplitStatementLine::class)->handle($line, [
        ['bill_id' => $bill->id, 'amount_cents' => 10000],
        ['account_id' => $this->expense->id, 'amount_cents' => 20000],
    ]))->toThrow(PostingValidationException::class);

    expect(BillPayment::query()->count())->toBe(0)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull();
});

it('refuses to overpay a bill, a bill of another vendor, and a bill part on money coming in', function () {
    $bill = splitOpenBill($this->vendor, 10000);
    $other = Contact::factory()->vendor()->create();

    expect(fn () => app(SplitStatementLine::class)->handle(splitOutflow(30000), [
        ['bill_id' => $bill->id, 'amount_cents' => 20000],
        ['account_id' => $this->expense->id, 'amount_cents' => 10000],
    ], $this->vendor->id))->toThrow(PostingValidationException::class);

    expect(fn () => app(SplitStatementLine::class)->handle(splitOutflow(30000), [
        ['bill_id' => $bill->id, 'amount_cents' => 10000],
        ['account_id' => $this->expense->id, 'amount_cents' => 20000],
    ], $other->id))->toThrow(PostingValidationException::class);

    $inflow = splitOutflow(30000);
    $inflow->forceFill(['amount_cents' => 30000])->save();

    expect(fn () => app(SplitStatementLine::class)->handle($inflow, [
        ['bill_id' => $bill->id, 'amount_cents' => 10000],
        ['account_id' => $this->expense->id, 'amount_cents' => 20000],
    ], $this->vendor->id))->toThrow(PostingValidationException::class);
});
