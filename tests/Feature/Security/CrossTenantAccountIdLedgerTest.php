<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\TaxReturnStatus;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceTemplateLine;
use App\Models\JournalEntryTemplateLine;
use App\Models\JournalLine;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\TaxCalculator;
use App\Services\Reconciliation\BankReconciliationService;
use App\Services\Reporting\ReportCalculator;
use App\Services\Tax\TaxReturnFiler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Account ids sent by the browser are checked against the current organization.
 * journal_lines carry no company_id (a line's tenancy comes from its entry), so
 * an id that slipped through would post one organization's entry onto another
 * organization's account. These cover the general journal, the save-as-template
 * shortcuts, the tax-return payment form and the reconciliation adjustments.
 */

beforeEach(function () {
    // The other organization is created while nothing is bound, so its seeded
    // chart keeps its own company_id.
    app()->forgetInstance('current_company');
    $this->other = Company::factory()->create();

    $otherAccount = fn (array $where): Account => Account::withoutGlobalScopes()
        ->where('company_id', $this->other->id)
        ->where($where)
        ->orderBy('code')
        ->firstOrFail();

    $this->otherBank = $otherAccount(['subtype' => AccountSubtype::Bank->value]);
    $this->otherIncome = $otherAccount(['type' => AccountType::Income->value]);
    $this->otherExpense = $otherAccount(['type' => AccountType::Expense->value]);

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();

    // Nothing may ever land on the other organization's accounts.
    $this->linesOnOtherAccounts = fn (): int => JournalLine::query()
        ->whereIn('account_id', [$this->otherBank->id, $this->otherIncome->id, $this->otherExpense->id])
        ->count();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('refuses a journal line on another organization\'s account', function () {
    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-06-15')
        ->set('lines.0.account_id', $this->bank->id)
        ->set('lines.0.debit', '987.65')
        ->set('lines.1.account_id', $this->otherIncome->id)
        ->set('lines.1.credit', '987.65')
        ->call('postEntry')
        ->assertHasErrors('lines.1.account_id');

    expect(($this->linesOnOtherAccounts)())->toBe(0)
        ->and((int) $this->otherIncome->fresh()->balance_cents)->toBe(0);

    // The other organization's own books are untouched.
    app()->instance('current_company', $this->other);
    $calculator = app(ReportCalculator::class);

    expect($calculator->balanceAsOf($this->otherIncome->fresh(), CarbonImmutable::parse('2026-12-31')))->toBe(0)
        ->and($calculator->trialBalance($this->other->fresh(), CarbonImmutable::parse('2026-12-31')))->toHaveCount(0);
});

it('still posts a journal entry on the organization\'s own accounts', function () {
    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-06-15')
        ->set('lines.0.account_id', $this->bank->id)
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $this->income->id)
        ->set('lines.1.credit', '100.00')
        ->call('postEntry')
        ->assertHasNoErrors();

    expect(JournalLine::query()->where('account_id', $this->income->id)->where('is_posted', true)->count())->toBe(1);
});

it('refuses another organization\'s account when editing a posted journal entry in place', function () {
    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-06-15',
        'lines' => [
            ['account_id' => $this->bank->id, 'debit_cents' => 10_000, 'credit_cents' => 0],
            ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 10_000],
        ],
    ]);
    app(JournalPoster::class)->post($entry);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry->fresh()])
        ->set('lines.1.account_id', $this->otherIncome->id)
        ->call('saveChanges')
        ->assertHasErrors('lines.1.account_id');

    expect(($this->linesOnOtherAccounts)())->toBe(0)
        ->and($entry->fresh()->lines->pluck('account_id')->all())->toBe([$this->bank->id, $this->income->id]);
});

it('refuses another organization\'s account when saving journal lines as a template', function () {
    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $this->otherExpense->id)
        ->set('lines.0.debit', '75.00')
        ->set('template_name', 'Monthly accrual')
        ->call('saveAsTemplate')
        ->assertHasErrors('lines.0.account_id');

    expect(JournalEntryTemplateLine::withoutGlobalScopes()->where('account_id', $this->otherExpense->id)->exists())->toBeFalse();
});

it('refuses another organization\'s account when saving invoice lines as a template', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.account_id', $this->otherIncome->id)
        ->set('lines.0.description', 'Consulting')
        ->set('template_name', 'Retainer')
        ->call('saveAsTemplate')
        ->assertHasErrors('lines.0.account_id');

    expect(InvoiceTemplateLine::withoutGlobalScopes()->where('account_id', $this->otherIncome->id)->exists())->toBeFalse();
});

describe('tax-return payment form', function () {
    beforeEach(function () {
        $gst = TaxCode::where('code', 'GST')->firstOrFail();
        $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);

        $invoice = Invoice::create(['contact_id' => $customer->id, 'invoice_no' => 'INV-TP-1', 'invoice_date' => '2026-02-01', 'due_date' => '2026-02-01']);
        $totals = app(TaxCalculator::class)->line('1', 100_000, $gst);
        $invoice->lines()->create([
            'account_id' => $this->income->id, 'description' => 'Service', 'quantity' => '1',
            'unit_price_cents' => 100_000, 'tax_code_id' => $gst->id,
            'line_subtotal_cents' => $totals['subtotal_cents'], 'line_tax_cents' => $totals['tax_cents'],
            'line_total_cents' => $totals['total_cents'], 'line_order' => 0,
        ]);
        app(InvoicePoster::class)->post($invoice);

        $this->taxReturn = app(TaxReturnFiler::class)->file(TaxReturn::create([
            'tax_agency_id' => $gst->agency_id,
            'tax_return_no' => 'TR-Q1',
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'status' => TaxReturnStatus::Draft,
        ]));
    });

    it('refuses another organization\'s account for the :dataset leg', function (string $amountField, string $accountField) {
        Livewire::test('pages::tax-returns.payment-form', ['company' => $this->company, 'tax_return' => $this->taxReturn])
            ->set($amountField, '25.00')
            ->set($accountField, $this->otherExpense->id)
            ->call('save')
            ->assertHasErrors($accountField);

        expect(($this->linesOnOtherAccounts)())->toBe(0)
            ->and((int) $this->otherExpense->fresh()->balance_cents)->toBe(0);
    })->with([
        'penalty' => ['penalty', 'penalty_account_id'],
        'interest' => ['interest', 'interest_account_id'],
        'commission' => ['commission', 'commission_account_id'],
    ]);

    it('still records a payment with a penalty on the organization\'s own account', function () {
        Livewire::test('pages::tax-returns.payment-form', ['company' => $this->company, 'tax_return' => $this->taxReturn])
            ->set('penalty', '25.00')
            ->set('penalty_account_id', $this->expense->id)
            ->call('save')
            ->assertHasNoErrors();

        expect(JournalLine::query()->where('account_id', $this->expense->id)->where('debit_cents', 2500)->exists())->toBeTrue();
    });
});

describe('bank reconciliation adjustments', function () {
    it('refuses another organization\'s account for the :dataset when beginning a reconciliation', function (string $amountField, string $dateField, string $accountField, string $otherAccount) {
        Livewire::test('pages::banking.reconcile', ['company' => $this->company])
            ->set('account_id', $this->bank->id)
            ->set('statementDate', '2026-06-30')
            ->set('endingBalance', '0.00')
            ->set($amountField, '15.00')
            ->set($dateField, '2026-06-30')
            ->set($accountField, $this->{$otherAccount}->id)
            ->call('startReconciliation')
            ->assertHasErrors($accountField);

        expect(($this->linesOnOtherAccounts)())->toBe(0)
            ->and(BankReconciliation::query()->count())->toBe(0);
    })->with([
        'service charge' => ['serviceChargeAmount', 'serviceChargeDate', 'serviceChargeAccountId', 'otherExpense'],
        'interest earned' => ['interestAmount', 'interestDate', 'interestAccountId', 'otherIncome'],
    ]);

    it('refuses another organization\'s account when editing a reconciliation\'s service charge', function () {
        app(BankReconciliationService::class)->begin($this->bank, Carbon::parse('2026-06-30'), 0);

        Livewire::test('pages::banking.reconcile', ['company' => $this->company])
            ->set('account_id', $this->bank->id)
            ->call('openEditDetails')
            ->set('serviceChargeAmount', '15.00')
            ->set('serviceChargeDate', '2026-06-30')
            ->set('serviceChargeAccountId', $this->otherExpense->id)
            ->call('saveDetails')
            ->assertHasErrors('serviceChargeAccountId');

        expect(($this->linesOnOtherAccounts)())->toBe(0);
    });

    it('still posts a service charge to the organization\'s own expense account', function () {
        Livewire::test('pages::banking.reconcile', ['company' => $this->company])
            ->set('account_id', $this->bank->id)
            ->set('statementDate', '2026-06-30')
            ->set('endingBalance', '-15.00')
            ->set('serviceChargeAmount', '15.00')
            ->set('serviceChargeDate', '2026-06-30')
            ->set('serviceChargeAccountId', $this->expense->id)
            ->call('startReconciliation')
            ->assertHasNoErrors();

        expect(JournalLine::query()->where('account_id', $this->expense->id)->where('debit_cents', 1500)->exists())->toBeTrue();
    });
});
