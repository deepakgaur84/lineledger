<?php

use App\Actions\Payroll\PullTimeEntriesIntoPayRun;
use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayRun;
use App\Actions\Payroll\SaveTimeOffPolicy;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeePayrollProfile;
use App\Models\JournalLine;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\PayRunLine;
use App\Models\TimeEntry;
use App\Models\TimeOffPolicy;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;

/*
|--------------------------------------------------------------------------
| Accrued vacation pay taken relieves its liability (audit D6Payroll-02)
|--------------------------------------------------------------------------
|
| Vacation pay that accrues (the built-in "Accrue to liability" policy, or a
| dollar time-off policy) is expensed as it is earned: DR Vacation Pay Expense /
| CR Vacation Payable. Paying it out later must settle that liability, not
| expense the same dollars again as wages — and the employee's vacation-dollar
| balance must go down by what was paid. Only the part covered by the accrued
| balance is settled; anything beyond it is paid as before and stays wage
| expense. The payout's source deductions are untouched: it is still gross pay.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->value('id');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/** An Ontario employee enrolled through the action (hourly $25 unless overridden). */
function vacReliefEmployee(string $name, array $profile = []): Contact
{
    $employee = Contact::create(['display_name' => $name, 'is_employee' => true, 'is_active' => true]);

    app(SaveEmployeePayrollProfile::class)->handle(array_merge([
        'contact_id' => $employee->id,
        'province_of_employment' => 'ON',
        'pay_basis' => PayBasis::Hourly->value,
        'hourly_rate_cents' => 2500,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1645200,
        'td1_provincial_claim_cents' => 1298900,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 400,
    ], $profile));

    return $employee;
}

/** A "Vacation" hours policy whose code matches the built-in vacation-pay balance. */
function vacReliefHoursPolicy(): TimeOffPolicy
{
    return app(SaveTimeOffPolicy::class)->handle([
        'name' => 'Vacation', 'code' => 'vacation', 'category' => 'vacation', 'unit' => 'hours',
        'accrual_method' => 'manual', 'paid' => true,
    ]);
}

/**
 * Save, calculate and post one run.
 *
 * @param  array<int, array<string, mixed>>  $lines  contact_id + hours_worked (+ manual_earnings)
 */
function vacReliefRun(string $start, string $end, string $payDate, array $lines, bool $pull = false): PayRun
{
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => $start,
        'period_end_date' => $end,
        'pay_date' => $payDate,
        'bank_account_id' => test()->bank,
        'lines' => $lines,
    ]);

    if ($pull) {
        app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    }

    app(CalculatePayRun::class)->calculate($run->fresh());
    app(PayRunPoster::class)->post($run->fresh());

    return $run->fresh();
}

function vacReliefBalanceOf(string $code): int
{
    return (int) Account::query()->where('code', $code)->firstOrFail()->fresh()->balance_cents;
}

function vacReliefAccrual(Contact $employee, string $code): EmployeeAccrualBalance
{
    return EmployeeAccrualBalance::query()
        ->whereHas('profile', fn ($q) => $q->where('contact_id', $employee->id))
        ->where('code', $code)
        ->firstOrFail();
}

/** Sum of one side of the posted lines a run's journal entry put on an account. */
function vacReliefPosted(PayRun $run, string $code, string $side): int
{
    $accountId = Account::query()->where('code', $code)->value('id');

    return (int) JournalLine::query()
        ->where('journal_entry_id', $run->journal_entry_id)
        ->where('account_id', $accountId)
        ->sum($side.'_cents');
}

it('relieves Vacation Payable and the dollar balance when accrued vacation pay is taken', function () {
    // The audit probe: a dollar (percent-of-earnings) policy, built-in vacation off.
    $policy = app(SaveTimeOffPolicy::class)->handle([
        'name' => 'Vacation pay', 'code' => 'vacation_pay', 'category' => 'vacation', 'unit' => 'dollars',
        'accrual_method' => 'per_pay_period', 'rate_bp' => 400, 'paid' => true,
        'liability_account_id' => Account::query()->where('code', '2430')->value('id'),
    ]);
    $vera = vacReliefEmployee('Vera Vacation', [
        'vacation_rate_bp' => 0,
        'time_off_policies' => [['time_off_policy_id' => $policy->id, 'opening_balance' => 0]],
    ]);

    // Run 1: 80 h × $25 = $2,000 worked, accruing 4% = $80.
    vacReliefRun('2026-03-01', '2026-03-14', '2026-03-20', [['contact_id' => $vera->id, 'hours_worked' => 80]]);

    expect(vacReliefAccrual($vera, 'vacation_pay')->balance_cents)->toBe(8000)
        ->and(vacReliefBalanceOf('2430'))->toBe(8000);

    // Run 2: no hours worked; 3.2 h of the vacation pay taken = $80, the whole balance.
    $run = vacReliefRun('2026-03-15', '2026-03-28', '2026-04-03', [[
        'contact_id' => $vera->id, 'hours_worked' => 0,
        'manual_earnings' => [['code' => 'vacation_pay', 'name' => 'Vacation pay', 'calc_kind' => 'hours', 'hours' => 3.2, 'multiplier_bp' => 10000]],
    ]]);

    // The $80 is still paid as gross (and accrues its own 4% = $3.20, as before) …
    expect((int) $run->gross_cents)->toBe(8000)
        // … but it is settled from Vacation Payable, not expensed again as wages.
        ->and(vacReliefPosted($run, '2430', 'debit'))->toBe(8000)
        ->and(vacReliefPosted($run, '6200', 'credit'))->toBe(8000)
        ->and(vacReliefBalanceOf('2430'))->toBe(320)
        ->and(vacReliefBalanceOf('6200'))->toBe(200000)
        ->and(vacReliefBalanceOf('6230'))->toBe(8320);

    // Expensed exactly once: every dollar of payroll expense is either paid or still owed.
    expect(vacReliefBalanceOf('6200') + vacReliefBalanceOf('6230'))->toBe(200000 + 8000 + vacReliefBalanceOf('2430'));

    // The employee's dollar balance goes down by what was paid; a dollar policy has no hours side.
    $balance = vacReliefAccrual($vera, 'vacation_pay');
    expect($balance->balance_cents)->toBe(320)
        ->and($balance->used_ytd_cents)->toBe(8000)
        ->and((float) $balance->balance_hours)->toBe(0.0)
        ->and((float) $balance->used_ytd_hours)->toBe(0.0);

    // The settlement is frozen on the earning, so a void puts back exactly what posting took.
    app(PayRunPoster::class)->void($run->fresh());

    $balance->refresh();
    expect($balance->balance_cents)->toBe(8000)
        ->and($balance->used_ytd_cents)->toBe(0)
        ->and(vacReliefBalanceOf('2430'))->toBe(8000)
        ->and(vacReliefBalanceOf('6200'))->toBe(200000);
});

it('settles a salaried employee’s vacation days from the built-in accrual, mirroring the portal balance', function () {
    $policy = vacReliefHoursPolicy();
    $sam = vacReliefEmployee('Sam Salaried', [
        'pay_basis' => PayBasis::Salary->value,
        'hourly_rate_cents' => null,
        'annual_salary_cents' => 5200000, // $2,000 a fortnight, $25/h over 2080 h
        'time_off_policies' => [['time_off_policy_id' => $policy->id, 'opening_balance' => 80]],
    ]);
    $profile = EmployeePayrollProfile::query()->where('contact_id', $sam->id)->firstOrFail();

    // Run 1 accrues 4% of $2,000 = $80 into the built-in vacation balance.
    vacReliefRun('2026-03-01', '2026-03-14', '2026-03-20', [['contact_id' => $sam->id]]);
    expect($profile->fresh()->vacation_balance_cents)->toBe(8000);

    // Run 2: half a day of approved vacation, pulled from time entries. The salary
    // keeps paying (the leave row is $0), so 4 h × $25 = $100 of that salary is
    // vacation pay settled from the $160 accrued by then.
    TimeEntry::create(['contact_id' => $sam->id, 'date_worked' => '2026-03-18', 'hours' => 4, 'pay_code' => 'vacation', 'status' => 'approved']);
    $run = vacReliefRun('2026-03-15', '2026-03-28', '2026-04-03', [['contact_id' => $sam->id]], pull: true);

    $line = PayRunLine::query()->where('pay_run_id', $run->id)->firstOrFail();
    expect((int) $line->earnings->firstWhere('code', 'vacation')?->amount_cents)->toBe(0)
        ->and((int) $line->gross_cents)->toBe(200000)
        ->and(vacReliefPosted($run, '2430', 'debit'))->toBe(10000);

    // GL: salary less the vacation it paid, plus the accrual expense; the liability is what's left.
    expect(vacReliefBalanceOf('6200'))->toBe(390000)
        ->and(vacReliefBalanceOf('6230'))->toBe(16000)
        ->and(vacReliefBalanceOf('2430'))->toBe(6000)
        ->and(vacReliefBalanceOf('6200') + vacReliefBalanceOf('6230'))->toBe(400000 + vacReliefBalanceOf('2430'));

    // The relief credit is tagged to the employee, like the wage debit it offsets.
    $credit = JournalLine::query()
        ->where('journal_entry_id', $run->journal_entry_id)
        ->where('account_id', Account::query()->where('code', '6200')->value('id'))
        ->where('credit_cents', '>', 0)
        ->firstOrFail();
    expect($credit->contact_id)->toBe($sam->id);

    // Both sides of the shared balance row move: dollars by the relief, hours by the leave.
    $balance = vacReliefAccrual($sam, 'vacation');
    expect($balance->balance_cents)->toBe(6000)
        ->and((float) $balance->balance_hours)->toBe(76.0)
        ->and($profile->fresh()->vacation_balance_cents)->toBe(6000);

    app(PayRunPoster::class)->void($run->fresh());

    $balance->refresh();
    expect($balance->balance_cents)->toBe(8000)
        ->and((float) $balance->balance_hours)->toBe(80.0)
        ->and($profile->fresh()->vacation_balance_cents)->toBe(8000)
        ->and(vacReliefBalanceOf('2430'))->toBe(8000);
});

it('settles only up to the accrued balance; the rest is paid as wages and the hours may go negative', function () {
    $policy = vacReliefHoursPolicy();
    $hana = vacReliefEmployee('Hana Hourly', [
        'time_off_policies' => [['time_off_policy_id' => $policy->id, 'opening_balance' => 4]],
    ]);

    // Run 1: 40 h × $25 = $1,000, accruing $40.
    vacReliefRun('2026-03-01', '2026-03-14', '2026-03-20', [['contact_id' => $hana->id, 'hours_worked' => 40]]);

    // Run 2: a full 8 h day off = $200, against $40 accrued (+ $8 on the payout itself).
    TimeEntry::create(['contact_id' => $hana->id, 'date_worked' => '2026-03-18', 'hours' => 8, 'pay_code' => 'vacation', 'status' => 'approved']);
    $run = vacReliefRun('2026-03-15', '2026-03-28', '2026-04-03', [['contact_id' => $hana->id]], pull: true);

    // The policy still pays the whole day at the employee's rate …
    expect((int) $run->gross_cents)->toBe(20000)
        // … $48 of it comes out of Vacation Payable, the other $152 is wage expense.
        ->and(vacReliefPosted($run, '2430', 'debit'))->toBe(4800)
        ->and(vacReliefBalanceOf('2430'))->toBe(0)
        ->and(vacReliefBalanceOf('6200'))->toBe(100000 + 20000 - 4800);

    // The dollar balance empties without going below zero; the hours overdraw is
    // allowed, as for any time off (a warning at approval, never a block).
    $balance = vacReliefAccrual($hana, 'vacation');
    expect($balance->balance_cents)->toBe(0)
        ->and($balance->used_ytd_cents)->toBe(4800)
        ->and((float) $balance->balance_hours)->toBe(-4.0);
});

it('leaves the payout’s source deductions exactly as for any other wage', function () {
    $policy = vacReliefHoursPolicy();
    $taker = vacReliefEmployee('Tara Taker', [
        'time_off_policies' => [['time_off_policy_id' => $policy->id, 'opening_balance' => 40]],
    ]);
    $earner = vacReliefEmployee('Eli Earner');

    vacReliefRun('2026-03-01', '2026-03-14', '2026-03-20', [
        ['contact_id' => $taker->id, 'hours_worked' => 80],
        ['contact_id' => $earner->id, 'hours_worked' => 80],
    ]);

    // Same $1,000 either way: a week of accrued vacation, or a plain "Other earnings" amount.
    $run = vacReliefRun('2026-03-15', '2026-03-28', '2026-04-03', [
        ['contact_id' => $taker->id, 'hours_worked' => 0, 'manual_earnings' => [
            ['code' => 'vacation', 'name' => 'Vacation', 'calc_kind' => 'hours', 'hours' => 40, 'multiplier_bp' => 10000],
        ]],
        ['contact_id' => $earner->id, 'hours_worked' => 0, 'manual_earnings' => [
            ['code' => 'other', 'name' => 'Other earnings', 'calc_kind' => 'amount', 'amount_cents' => 100000],
        ]],
    ]);

    $lines = PayRunLine::query()->where('pay_run_id', $run->id)->get()->keyBy('contact_id');
    $statutory = fn (PayRunLine $l): array => [
        $l->gross_cents, $l->cpp_pensionable_cents, $l->ei_insurable_cents,
        $l->cppEmployeeCents(), $l->eiEmployeeCents(), $l->federalTaxCents(), $l->provincialTaxCents(), $l->net_cents,
    ];

    expect($statutory($lines[$taker->id]))->toBe($statutory($lines[$earner->id]))
        ->and($lines[$taker->id]->cppEmployeeCents())->toBeGreaterThan(0)
        ->and($lines[$taker->id]->federalTaxCents())->toBeGreaterThan(0)
        // Only the taker's vacation pay is settled from the liability: all that has
        // accrued, 4% of the $2,000 worked plus 4% of the payout itself.
        ->and(vacReliefPosted($run, '2430', 'debit'))->toBe(8000 + 4000);
});

it('settles nothing for pay-as-you-go vacation, which is paid on every cheque and never accrues', function () {
    $policy = vacReliefHoursPolicy();
    $pia = vacReliefEmployee('Pia Paygo', [
        'vacation_policy' => 'pay_each_cheque',
        'time_off_policies' => [['time_off_policy_id' => $policy->id, 'opening_balance' => 40]],
    ]);

    TimeEntry::create(['contact_id' => $pia->id, 'date_worked' => '2026-03-03', 'hours' => 72, 'status' => 'approved']);
    TimeEntry::create(['contact_id' => $pia->id, 'date_worked' => '2026-03-04', 'hours' => 8, 'pay_code' => 'vacation', 'status' => 'approved']);
    $run = vacReliefRun('2026-03-01', '2026-03-14', '2026-03-20', [['contact_id' => $pia->id]], pull: true);

    // 72 h + 8 h of vacation at $25, plus 4% vacation pay on the cheque: all of it wages.
    expect((int) $run->gross_cents)->toBe(208000)
        ->and(vacReliefBalanceOf('6200'))->toBe(208000)
        ->and(vacReliefBalanceOf('2430'))->toBe(0)
        ->and(vacReliefBalanceOf('6230'))->toBe(0)
        ->and(vacReliefAccrual($pia, 'vacation')->balance_cents)->toBe(0)
        ->and((float) vacReliefAccrual($pia, 'vacation')->balance_hours)->toBe(32.0);
});

it('settles a dollar policy from the liability account the policy accrues to', function () {
    $custom = Account::create([
        'code' => '2431',
        'name' => 'Accrued vacation pay',
        'subtype' => AccountSubtype::CurrentLiability->value,
        'type' => AccountSubtype::CurrentLiability->type()->value,
        'normal_balance' => AccountSubtype::CurrentLiability->type()->normalBalance()->value,
    ]);
    $policy = app(SaveTimeOffPolicy::class)->handle([
        'name' => 'Vacation pay', 'code' => 'vacation_pay', 'category' => 'vacation', 'unit' => 'dollars',
        'accrual_method' => 'per_pay_period', 'rate_bp' => 400, 'paid' => true, 'liability_account_id' => $custom->id,
    ]);
    $cora = vacReliefEmployee('Cora Custom', [
        'vacation_rate_bp' => 0,
        'time_off_policies' => [['time_off_policy_id' => $policy->id, 'opening_balance' => 0]],
    ]);

    vacReliefRun('2026-03-01', '2026-03-14', '2026-03-20', [['contact_id' => $cora->id, 'hours_worked' => 80]]);
    $run = vacReliefRun('2026-03-15', '2026-03-28', '2026-04-03', [[
        'contact_id' => $cora->id, 'hours_worked' => 0,
        'manual_earnings' => [['code' => 'vacation_pay', 'name' => 'Vacation pay', 'calc_kind' => 'hours', 'hours' => 2, 'multiplier_bp' => 10000]],
    ]]);

    expect(vacReliefPosted($run, '2431', 'debit'))->toBe(5000)
        ->and(vacReliefBalanceOf('2431'))->toBe(8000 + 200 - 5000)
        ->and(vacReliefBalanceOf('2430'))->toBe(0);
});

it('voids a run posted before vacation relief existed by the rules it was posted under', function () {
    $policy = app(SaveTimeOffPolicy::class)->handle([
        'name' => 'Vacation pay', 'code' => 'vacation_pay', 'category' => 'vacation', 'unit' => 'dollars',
        'accrual_method' => 'manual', 'paid' => true,
    ]);
    $lou = vacReliefEmployee('Lou Legacy', [
        'vacation_rate_bp' => 0,
        'time_off_policies' => [['time_off_policy_id' => $policy->id, 'opening_balance' => 500]],
    ]);

    $run = vacReliefRun('2026-03-01', '2026-03-14', '2026-03-20', [[
        'contact_id' => $lou->id, 'hours_worked' => 0,
        'manual_earnings' => [['code' => 'vacation_pay', 'name' => 'Vacation pay', 'calc_kind' => 'amount', 'amount_cents' => 20000]],
    ]]);

    // Rewind the earning to how the old poster left it: no settlement recorded. The
    // old poster drew an amount take off a dollar balance in full — the same $200
    // the new one settled — so the balance already reads as it would have.
    $earning = PayRunLine::query()->where('pay_run_id', $run->id)->firstOrFail()->earnings->firstWhere('code', 'vacation_pay');
    $earning->forceFill(['accrual_relief_cents' => null])->save();

    $balance = vacReliefAccrual($lou, 'vacation_pay');
    expect($balance->balance_cents)->toBe(30000);

    app(PayRunPoster::class)->void($run->fresh());

    expect($balance->fresh()->balance_cents)->toBe(50000)
        ->and($balance->fresh()->used_ytd_cents)->toBe(0);
});
