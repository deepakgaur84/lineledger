<?php

/*
 * Annual CPP, CPP2, EI, QPP, QPP2 and QPIP maximums across pay runs (audit
 * D6Payroll-12). T4127 122nd/123rd editions cap 2026 employee CPP at $4,230.45,
 * CPP2 at $416.00 and EI at $1,123.07 (Quebec: QPP $4,479.30, QPP2 $416.00,
 * EI $895.70, QPIP $442.90).
 *
 * The year-to-date used to count only POSTED runs with a strictly EARLIER pay
 * date, so a regular run and a bonus run on the same pay date each took the
 * full remaining room, and a run calculated before an earlier run was posted
 * never saw it. Every scenario below starts an employee $10 short of each
 * maximum (mid-year opening balances) and checks the year's posted T4 totals.
 */

use App\Actions\Payroll\SavePayRun;
use App\Enums\PayBasis;
use App\Exceptions\Payroll\AnnualMaximumExceededException;
use App\Exceptions\Payroll\StalePayRunCalculationException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\T4SlipCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;

const YTDMAX_CPP = 423045;
const YTDMAX_CPP2 = 41600;
const YTDMAX_EI = 112307;
const YTDMAX_QPP = 447930;
const YTDMAX_QPP2 = 41600;
const YTDMAX_EI_QC = 89570;
const YTDMAX_QPIP = 44290;

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $this->company);
    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->value('id');
    $this->employee = ytdmax_employee('Max Ceiling', 'ON');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * An employee hired mid-year whose onboarding balances leave $10 of room under
 * every maximum ($3.00 under QPIP). $78,000 salary: $3,000 a biweekly pay.
 */
function ytdmax_employee(string $name, string $province): Contact
{
    $employee = Contact::create(['display_name' => $name, 'is_employee' => true]);

    EmployeePayrollProfile::factory()->create([
        'contact_id' => $employee->id,
        'province_of_employment' => $province,
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 7800000,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1645200,
        'td1_provincial_claim_cents' => $province === 'QC' ? 1895200 : 1298900,
        'opening_balances_as_of' => '2026-11-30',
        'opening_pensionable_cents' => 8400000,     // above the YMPE, $1,000 under the YAMPE
        'opening_insurable_cents' => 6800000,
        'opening_cpp_employee_cents' => $province === 'QC' ? 0 : YTDMAX_CPP - 1000,
        'opening_cpp2_employee_cents' => $province === 'QC' ? 0 : YTDMAX_CPP2 - 1000,
        'opening_ei_employee_cents' => ($province === 'QC' ? YTDMAX_EI_QC : YTDMAX_EI) - 1000,
        'opening_qpp_employee_cents' => $province === 'QC' ? YTDMAX_QPP - 1000 : 0,
        'opening_qpp2_employee_cents' => $province === 'QC' ? YTDMAX_QPP2 - 1000 : 0,
        'opening_qpip_employee_cents' => $province === 'QC' ? YTDMAX_QPIP - 300 : 0,
        'opening_qpip_insurable_cents' => $province === 'QC' ? 10000000 : 0,
    ]);

    return $employee;
}

/**
 * @param  array<int, Contact>|null  $employees
 */
function ytdmax_run(string $payDate, int $bonusCents = 0, ?array $employees = null): PayRun
{
    $end = CarbonImmutable::parse($payDate);

    return app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => $end->subDays(13)->toDateString(),
        'period_end_date' => $end->toDateString(),
        'pay_date' => $end->toDateString(),
        'bank_account_id' => test()->bank,
        'lines' => array_map(fn (Contact $employee) => [
            'contact_id' => $employee->id,
            'manual_earnings' => $bonusCents > 0
                ? [['code' => 'bonus', 'name' => 'Bonus', 'calc_kind' => 'amount', 'amount_cents' => $bonusCents]]
                : [],
        ], $employees ?? [test()->employee]),
    ]);
}

function ytdmax_calculate(PayRun $run): PayRun
{
    return app(CalculatePayRun::class)->calculate($run->fresh());
}

function ytdmax_post(PayRun $run): void
{
    app(PayRunPoster::class)->post($run->fresh());
}

/**
 * @return array<string, int>
 */
function ytdmax_slip(Contact $employee): array
{
    return collect(app(T4SlipCalculator::class)->slipsForYear(test()->company, 2026))->firstWhere('contact_id', $employee->id);
}

function ytdmax_expectAtMaximums(Contact $employee): void
{
    $slip = ytdmax_slip($employee);

    // Posted deductions plus the opening balances reach each maximum exactly.
    expect($slip['box16'] + YTDMAX_CPP - 1000)->toBe(YTDMAX_CPP)
        ->and($slip['box16a'] + YTDMAX_CPP2 - 1000)->toBe(YTDMAX_CPP2)
        ->and($slip['box18'] + YTDMAX_EI - 1000)->toBe(YTDMAX_EI);
}

it('shares the remaining room between two runs on the same pay date', function () {
    // A regular run and a bonus run both paid 2026-12-18. The bonus run's
    // year-to-date includes the posted regular run.
    $regular = ytdmax_run('2026-12-18');
    ytdmax_calculate($regular);
    ytdmax_post($regular);

    $bonus = ytdmax_run('2026-12-18', 500000);
    ytdmax_calculate($bonus);
    ytdmax_post($bonus);

    $line = $bonus->fresh()->lines->first();

    expect($line->cppEmployeeCents())->toBe(0)
        ->and($line->cpp2EmployeeCents())->toBe(0)
        ->and($line->eiEmployeeCents())->toBe(0);

    ytdmax_expectAtMaximums($this->employee);
});

it('counts an earlier run that was calculated but not yet posted', function () {
    // Year-end prep: both runs calculated up front, then posted in date order.
    $dec4 = ytdmax_run('2026-12-04');
    $dec18 = ytdmax_run('2026-12-18');

    ytdmax_calculate($dec4);
    ytdmax_calculate($dec18);

    expect($dec18->fresh()->lines->first()->cppEmployeeCents())->toBe(0);

    ytdmax_post($dec4);
    ytdmax_post($dec18);

    ytdmax_expectAtMaximums($this->employee);
});

it('refuses to post a run whose year-to-date changed after it was calculated', function () {
    // Dec 18 is calculated while Dec 4 is still a draft, so it takes the last
    // $10; Dec 4 is then calculated and posted and takes the same $10.
    $dec4 = ytdmax_run('2026-12-04');
    $dec18 = ytdmax_run('2026-12-18');

    ytdmax_calculate($dec18);
    ytdmax_calculate($dec4);
    ytdmax_post($dec4);

    expect(fn () => ytdmax_post($dec18))->toThrow(StalePayRunCalculationException::class, 'Recalculate this pay run before posting it.');

    ytdmax_calculate($dec18);
    ytdmax_post($dec18);

    expect($dec18->fresh()->lines->first()->cppEmployeeCents())->toBe(0);
    ytdmax_expectAtMaximums($this->employee);
});

it('posts runs in order: an earlier calculated run for the same employee goes first', function () {
    $regular = ytdmax_run('2026-12-18');
    $bonus = ytdmax_run('2026-12-18', 500000);

    ytdmax_calculate($regular);
    ytdmax_calculate($bonus);

    // Same pay date: the run created first comes first, so the bonus run's
    // calculation already left the room to it.
    expect($bonus->fresh()->lines->first()->cppEmployeeCents())->toBe(0);

    expect(fn () => ytdmax_post($bonus))->toThrow(
        StalePayRunCalculationException::class,
        "Pay run {$regular->run_no} (pay date 2026-12-18) is calculated but not posted",
    );

    ytdmax_post($regular);
    ytdmax_post($bonus);

    ytdmax_expectAtMaximums($this->employee);
});

it('does not hold back a run for employees an earlier calculated run does not pay', function () {
    $other = ytdmax_employee('Other Employee', 'ON');

    $first = ytdmax_run('2026-12-04', employees: [$other]);
    ytdmax_calculate($first);

    $second = ytdmax_run('2026-12-18');
    ytdmax_calculate($second);
    ytdmax_post($second);

    expect($second->fresh()->status->isPosted())->toBeTrue();
});

it('counts a later run that is already posted when a back-dated run is calculated', function () {
    $dec18 = ytdmax_run('2026-12-18');
    ytdmax_calculate($dec18);
    ytdmax_post($dec18);

    // A correction run dated before it, created afterwards.
    $dec4 = ytdmax_run('2026-12-04');
    ytdmax_calculate($dec4);
    ytdmax_post($dec4);

    expect($dec4->fresh()->lines->first()->cppEmployeeCents())->toBe(0);
    ytdmax_expectAtMaximums($this->employee);
});

it('refuses an adjustment that would take posted CPP past the annual maximum', function () {
    $run = ytdmax_calculate(ytdmax_run('2026-12-18'));
    $line = $run->lines->first();

    expect($line->cppEmployeeCents())->toBe(1000);

    // Only $10.00 of room is left; an adjustment to $50.00 is refused.
    $line->forceFill(['cpp_employee_override_cents' => 5000])->save();
    $line->refresh()->recalculateTotals();

    $maximum = Money::fromCents(YTDMAX_CPP)->format();
    $left = Money::fromCents(1000)->format();

    expect(fn () => ytdmax_post($run))->toThrow(AnnualMaximumExceededException::class, "CPP contribution past the annual maximum of {$maximum}: only {$left} is left");
});

it('requires a recalculation for a run calculated before year-to-date fingerprints existed', function () {
    $run = ytdmax_calculate(ytdmax_run('2026-12-18'));
    $run->lines()->update(['ytd_basis_hash' => null]);

    expect(fn () => ytdmax_post($run))->toThrow(StalePayRunCalculationException::class);

    ytdmax_calculate($run);
    ytdmax_post($run);

    expect($run->fresh()->status->isPosted())->toBeTrue();
});

it('keeps QPP, QPP2, Quebec EI and QPIP within their maximums across same-date runs', function () {
    $employee = ytdmax_employee('Quebec Ceiling', 'QC');

    foreach ([0, 500000] as $bonus) {
        $run = ytdmax_run('2026-12-18', $bonus, [$employee]);
        ytdmax_calculate($run);
        ytdmax_post($run);
    }

    $slip = ytdmax_slip($employee);

    expect($slip['box17'] + YTDMAX_QPP - 1000)->toBe(YTDMAX_QPP)
        ->and($slip['box17a'] + YTDMAX_QPP2 - 1000)->toBe(YTDMAX_QPP2)
        ->and($slip['box18'] + YTDMAX_EI_QC - 1000)->toBe(YTDMAX_EI_QC)
        ->and($slip['box55'] + YTDMAX_QPIP - 300)->toBe(YTDMAX_QPIP);
});
