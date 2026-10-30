<?php

use App\Actions\Payroll\SavePayRun;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeRecurringItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PayrollRemittance;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\TimeOffPolicy;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\PayRunPoster;
use Livewire\Livewire;

/*
 * Payroll pages take bank, wage-expense and liability account ids from the
 * browser. Each must be an account of the current organization, of the kind the
 * field holds (a bank account to pay from, an expense to charge, a liability to
 * owe), or nothing is saved or posted.
 */

beforeEach(function () {
    // The other organization, with $10,000 in its bank account. Created while
    // nothing else is bound, so its seeded chart keeps its own company_id.
    app()->forgetInstance('current_company');
    $this->other = Company::factory()->create(['address_country' => 'CA']);
    app()->instance('current_company', $this->other);
    $this->otherBank = Account::query()->where('code', '1000')->firstOrFail();
    $this->otherWages = Account::query()->where('code', '6200')->firstOrFail();
    $this->otherLiability = Account::query()->where('subtype', AccountSubtype::CurrentLiability->value)->orderBy('code')->firstOrFail();
    $this->otherSchedule = PayrollSchedule::factory()->create();
    $this->otherPolicy = TimeOffPolicy::create(['name' => 'Sick', 'code' => 'sick', 'category' => 'sick', 'unit' => 'hours', 'accrual_method' => 'manual']);

    $equity = Account::query()->where('code', 'like', '3%')->orderBy('code')->firstOrFail();
    $opening = JournalEntry::create(['entry_no' => 'B-1', 'entry_date' => '2025-01-05', 'memo' => 'Owner investment']);
    $opening->lines()->create(['account_id' => $this->otherBank->id, 'debit_cents' => 1_000_000, 'credit_cents' => 0, 'line_order' => 0]);
    $opening->lines()->create(['account_id' => $equity->id, 'debit_cents' => 0, 'credit_cents' => 1_000_000, 'line_order' => 1]);
    app(JournalPoster::class)->post($opening->fresh());
    app()->forgetInstance('current_company');

    // A payroll user of this organization, with one salaried employee.
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
    $this->wages = Account::query()->where('code', '6200')->firstOrFail();
    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Casey Cash', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6_000_000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1_612_900,
        'td1_provincial_claim_cents' => 2_232_300,
    ]);

    $this->postedRun = function (): PayRun {
        $run = app(SavePayRun::class)->handle([
            'payroll_schedule_id' => $this->schedule->id,
            'period_start_date' => '2025-06-01',
            'period_end_date' => '2025-06-14',
            'pay_date' => '2025-06-20',
            'bank_account_id' => $this->bank->id,
            'lines' => [['contact_id' => $this->employee->id]],
        ]);
        app(CalculatePayRun::class)->calculate($run);
        app(PayRunPoster::class)->post($run->fresh());

        return $run->fresh();
    };

    $this->otherBankUntouched = function (): void {
        expect(JournalLine::query()->where('account_id', $this->otherBank->id)->count())->toBe(1)
            ->and((int) $this->otherBank->fresh()->balance_cents)->toBe(1_000_000);
    };

    // The employee setup form, filled in as a valid salaried profile.
    $this->employeeForm = fn () => Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => Contact::create(['display_name' => 'Morgan Moved', 'is_employee' => true])])
        ->set('province_of_employment', 'AB')
        ->set('pay_basis', 'salary')
        ->set('annual_salary', '52000.00')
        ->set('payroll_schedule_id', $this->schedule->id)
        ->set('td1_federal_claim', '16129')
        ->set('td1_provincial_claim', '22323');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('refuses to write payroll cheques from :dataset', function (string $account) {
    $run = ($this->postedRun)();

    Livewire::test('pages::payroll.pay-runs.show', ['company' => $this->company, 'payRun' => $run])
        ->set('startingChequeNumber', '5001')
        ->set('bankAccountId', $this->{$account}->id)
        ->call('writeCheques')
        ->assertHasErrors('bankAccountId');

    expect($run->cheques()->count())->toBe(0);
    ($this->otherBankUntouched)();
})->with([
    'another organization\'s bank account' => ['otherBank'],
    'an account that is not a bank account' => ['wages'],
]);

it('still writes payroll cheques from the organization\'s own bank account', function () {
    $run = ($this->postedRun)();

    Livewire::test('pages::payroll.pay-runs.show', ['company' => $this->company, 'payRun' => $run])
        ->set('startingChequeNumber', '5001')
        ->set('bankAccountId', $this->bank->id)
        ->call('writeCheques')
        ->assertHasNoErrors();

    expect($run->cheques()->count())->toBe(1);
});

it('does not let a pay run be saved against :dataset', function (string $field, string $value) {
    Livewire::test('pages::payroll.pay-runs.form', ['company' => $this->company])
        ->set('payroll_schedule_id', $this->schedule->id)
        ->set('period_start_date', '2025-06-01')
        ->set('period_end_date', '2025-06-14')
        ->set('pay_date', '2025-06-20')
        ->set("rows.{$this->employee->id}.selected", true)
        ->set($field, $this->{$value}->id)
        ->call('saveDraft')
        ->assertHasErrors($field);

    expect(PayRun::query()->count())->toBe(0);
})->with([
    'another organization\'s bank account' => ['bank_account_id', 'otherBank'],
    'another organization\'s pay schedule' => ['payroll_schedule_id', 'otherSchedule'],
]);

it('does not let a remittance be paid from another organization\'s bank account (:dataset)', function (string $page) {
    ($this->postedRun)();
    $this->travelTo('2025-07-10');

    Livewire::test("pages::payroll.reports.{$page}", ['company' => $this->company])
        ->call('openRecord')
        ->set('f_bank_account_id', $this->otherBank->id)
        ->set('f_payment_date', '2025-07-10')
        ->call('record')
        ->assertHasErrors('f_bank_account_id');

    expect(PayrollRemittance::query()->count())->toBe(0);
    ($this->otherBankUntouched)();

    $this->travelBack();
})->with(['pd7a', 'revenu-quebec', 'workers-comp']);

it('refuses another organization\'s account as an employee\'s wage expense account', function () {
    ($this->employeeForm)()
        ->set('wage_expense_account_id', $this->otherWages->id)
        ->call('save')
        ->assertHasErrors('wage_expense_account_id');

    expect(EmployeePayrollProfile::query()->where('wage_expense_account_id', $this->otherWages->id)->exists())->toBeFalse();
});

it('refuses another organization\'s account on a recurring :dataset', function (string $kind, string $code, string $field, string $account) {
    ($this->employeeForm)()
        ->call('addRecurringItem', $kind)
        ->set('recurring_items.0.name', 'Recurring item')
        ->set('recurring_items.0.code', $code)
        ->set('recurring_items.0.amount', '200')
        ->set("recurring_items.0.{$field}", $this->{$account}->id)
        ->call('save')
        ->assertHasErrors("recurring_items.0.{$field}");

    expect(EmployeeRecurringItem::query()->where($field, $this->{$account}->id)->exists())->toBeFalse();
})->with([
    'earning (expense account)' => ['earning', 'reimbursement', 'expense_account_id', 'otherWages'],
    'deduction (liability account)' => ['deduction', 'union_dues', 'liability_account_id', 'otherLiability'],
]);

it('refuses another organization\'s :dataset on the employee setup form', function (string $field, int $value) {
    $component = ($this->employeeForm)();

    if ($field === 'time_off_policies.0.time_off_policy_id') {
        $component->call('addTimeOffPolicy');
    }

    $component->set($field, $value)
        ->call('save')
        ->assertHasErrors($field);
})->with([
    'pay schedule' => ['payroll_schedule_id', fn () => $this->otherSchedule->id],
    'time-off policy' => ['time_off_policies.0.time_off_policy_id', fn () => $this->otherPolicy->id],
]);

it('will not post a pay run whose recurring earning was saved against another organization\'s account', function () {
    // An earning set up before account ids were checked, pointing elsewhere.
    ($this->employeeForm)()
        ->call('addRecurringItem', 'earning')
        ->set('recurring_items.0.name', 'Mileage reimbursement')
        ->set('recurring_items.0.code', 'reimbursement')
        ->set('recurring_items.0.amount', '200')
        ->set('recurring_items.0.add_to_net_pay_only', true)
        ->set('recurring_items.0.expense_account_id', $this->wages->id)
        ->call('save')
        ->assertHasNoErrors();
    $item = EmployeeRecurringItem::query()->firstOrFail();
    $item->forceFill(['expense_account_id' => $this->otherWages->id])->save();

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $this->bank->id,
        'lines' => [['contact_id' => $item->profile->contact_id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);

    expect(fn () => app(PayRunPoster::class)->post($run->fresh()))->toThrow(PostingValidationException::class);

    expect(JournalLine::query()->where('account_id', $this->otherWages->id)->count())->toBe(0)
        ->and($run->fresh()->isPosted())->toBeFalse();
});
