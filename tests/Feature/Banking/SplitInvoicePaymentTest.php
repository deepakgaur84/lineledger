<?php

use App\Actions\Banking\SplitStatementLine;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
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
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
    $this->customer = Contact::factory()->customer()->create();
    $this->income = Account::query()->where('type', AccountType::Income->value)->where('is_active', true)->orderBy('code')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function splitLine(int $cents): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => test()->import->id,
        'account_id' => test()->bank->id,
        'txn_date' => now()->toDateString(),
        'amount_cents' => $cents,
        'description' => 'SPLIT '.$cents,
        'match_status' => StatementLineMatchStatus::Unmatched->value,
        'created_journal_entry_id' => null,
    ]);
}

it('pays an invoice as one part of a split, matching the line to both bank legs', function () {
    $invoice = overpaymentInvoice($this->customer, 40000);
    $line = splitLine(115000);

    app(SplitStatementLine::class)->handle($line, [
        ['invoice_id' => $invoice->id, 'amount_cents' => 40000],
        ['account_id' => $this->income->id, 'amount_cents' => 75000],
    ]);

    $line->refresh();
    $receipt = CustomerReceipt::query()->firstOrFail();
    $extra = DB::table('bank_statement_line_entries')->where('bank_statement_line_id', $line->id)->first();
    $debits = JournalLine::query()->where('account_id', $this->bank->id)->where('debit_cents', '>', 0)->pluck('debit_cents')->map(fn ($c) => (int) $c)->sort()->values()->all();

    expect($line->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($line->matchedJournalLine->debit_cents)->toBe(75000)
        ->and($debits)->toBe([40000, 75000])
        ->and(CustomerReceipt::query()->count())->toBe(1)
        ->and($receipt->deposit_to_account_id)->toBe($this->bank->id)
        ->and($extra->journal_entry_id)->toBe($receipt->journal_entry_id)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('records a split of only invoices as the receipt alone', function () {
    $a = overpaymentInvoice($this->customer, 10000);
    $b = overpaymentInvoice($this->customer, 20000);
    $line = splitLine(30000);

    app(SplitStatementLine::class)->handle($line, [
        ['invoice_id' => $a->id, 'amount_cents' => 10000],
        ['invoice_id' => $b->id, 'amount_cents' => 20000],
    ]);

    expect($line->fresh()->matchedJournalLine->debit_cents)->toBe(30000)
        ->and(CustomerReceipt::query()->count())->toBe(1)
        ->and(DB::table('bank_statement_line_entries')->count())->toBe(0);
});

it('allows a partial payment of an invoice inside a split', function () {
    $invoice = overpaymentInvoice($this->customer, 40000);

    app(SplitStatementLine::class)->handle(splitLine(30000), [
        ['invoice_id' => $invoice->id, 'amount_cents' => 10000],
        ['account_id' => $this->income->id, 'amount_cents' => 20000],
    ]);

    expect($invoice->fresh()->balanceCents())->toBe(30000);
});

it('refuses to overpay an invoice, and leaves nothing behind', function () {
    $invoice = overpaymentInvoice($this->customer, 10000);
    $line = splitLine(30000);

    expect(fn () => app(SplitStatementLine::class)->handle($line, [
        ['invoice_id' => $invoice->id, 'amount_cents' => 20000],
        ['account_id' => $this->income->id, 'amount_cents' => 10000],
    ]))->toThrow(PostingValidationException::class);

    expect(CustomerReceipt::query()->count())->toBe(0)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull();
});

it('refuses an invoice part on money going out', function () {
    $invoice = overpaymentInvoice($this->customer, 10000);

    expect(fn () => app(SplitStatementLine::class)->handle(splitLine(-30000), [
        ['invoice_id' => $invoice->id, 'amount_cents' => 10000],
        ['account_id' => $this->income->id, 'amount_cents' => 20000],
    ]))->toThrow(PostingValidationException::class);
});

it('refuses a part with neither a category nor an invoice', function () {
    expect(fn () => app(SplitStatementLine::class)->handle(splitLine(30000), [
        ['amount_cents' => 30000],
    ]))->toThrow(PostingValidationException::class);
});
