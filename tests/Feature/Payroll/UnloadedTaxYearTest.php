<?php

/*
 * D6Payroll-11 regression: a pay date in a year whose tax tables are not loaded
 * must fail loud with MissingPayrollConstantsException, never fall back to the
 * last loaded table (it silently reused the July 2026 tables for January 2027,
 * over-deducting CPP at 5.95% although base CPP drops to 4.75% on Jan 1 2027).
 *
 * The year under test is derived from the loaded tables, so these keep guarding
 * every year-end: 2027 until the T4127 124th-edition tables are appended, then
 * 2028, and so on.
 */

use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\PayRunStatus;
use App\Exceptions\Payroll\MissingPayrollConstantsException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\JournalEntry;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Payroll\Data\EarningsBreakdown;
use App\Services\Payroll\Data\EmployeePayrollContext;
use App\Services\Payroll\Data\YtdTotals;
use App\Services\Payroll\PayrollDeductionEngine;
use App\Services\Posting\PayRunPoster;
use App\Support\Payroll\Constants\FederalConstants;
use App\Support\Payroll\Constants\PayrollConstantsRepository;
use App\Support\Payroll\Constants\PayrollTableCoverage;
use App\Support\Payroll\Constants\ProvincialConstants;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/** The last calendar year any loaded table reaches (2026 until the 2027 tables land). */
function unloadedYearLastLoaded(): int
{
    return (int) substr(max(FederalConstants::loadedPeriods()), 0, 4);
}

/** A date in the first year no loaded table covers, e.g. '01-15' → '2027-01-15'. */
function unloadedYearDate(string $monthDay): string
{
    return (unloadedYearLastLoaded() + 1).'-'.$monthDay;
}

/** The last day the loaded tables cover, e.g. '2026-12-31'. */
function unloadedYearCoveredThrough(): string
{
    return unloadedYearLastLoaded().'-12-31';
}

function unloadedYearContext(string $payDate, string $province = 'ON'): EmployeePayrollContext
{
    return new EmployeePayrollContext(
        province: $province,
        payPeriodsPerYear: 26,
        payDate: CarbonImmutable::parse($payDate),
        federalClaimCents: 1645200,
        provincialClaimCents: 1298900,
    );
}

/**
 * A biweekly schedule and an Ontario salaried employee enrolled on it.
 *
 * @return array{0: PayrollSchedule, 1: Contact}
 */
function unloadedYearEmployee(Company $company): array
{
    $schedule = PayrollSchedule::factory()->create(['company_id' => $company->id]);
    $employee = Contact::create(['display_name' => 'Jan Twentyseven', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $employee->id,
        'province_of_employment' => 'ON',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 7800000,
        'payroll_schedule_id' => $schedule->id,
        'td1_federal_claim_cents' => 1645200,
        'td1_provincial_claim_cents' => 1298900,
    ]);

    return [$schedule, $employee];
}

/** That employee on a draft run paid on $payDate. */
function unloadedYearDraftRun(Company $company, string $payDate): PayRun
{
    [$schedule, $employee] = unloadedYearEmployee($company);
    $pay = CarbonImmutable::parse($payDate);

    return app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $schedule->id,
        'period_start_date' => $pay->subDays(20)->toDateString(),
        'period_end_date' => $pay->subDays(7)->toDateString(),
        'pay_date' => $payDate,
        'bank_account_id' => Account::query()->where('code', '1000')->value('id'),
        'lines' => [['contact_id' => $employee->id]],
    ]);
}

/**
 * A run calculated on the last loaded tables and then dated into the unloaded
 * year: what a run calculated before this fix looks like (Calculated, with
 * amounts from the superseded table).
 */
function unloadedYearStaleCalculatedRun(Company $company): PayRun
{
    $run = unloadedYearDraftRun($company, unloadedYearLastLoaded().'-12-18');
    app(CalculatePayRun::class)->calculate($run);
    $run->forceFill(['pay_date' => unloadedYearDate('01-15')])->save();

    return $run->fresh();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

// ── The engine refuses the unloaded year (the original probe) ──────────────

it('refuses to resolve payroll constants for a January pay date in a year with no loaded tables', function () {
    app(PayrollConstantsRepository::class)->resolve('ON', CarbonImmutable::parse(unloadedYearDate('01-15')));
})->throws(MissingPayrollConstantsException::class);

it('refuses to compute source deductions in that year instead of reusing the last loaded table', function () {
    app(PayrollDeductionEngine::class)->compute(
        unloadedYearContext(unloadedYearDate('01-15')),
        new EarningsBreakdown(300000, 300000, 300000, 300000),
        YtdTotals::none(),
    );
})->throws(MissingPayrollConstantsException::class);

it('refuses to calculate a pay run dated in that January and leaves it an uncalculated draft', function () {
    $run = unloadedYearDraftRun($this->company, unloadedYearDate('01-15'));

    expect(fn () => app(CalculatePayRun::class)->calculate($run))
        ->toThrow(MissingPayrollConstantsException::class);

    $run->refresh();
    $line = $run->lines()->firstOrFail();

    // Nothing was computed from a superseded table: the run is still a draft
    // with no amounts on it.
    expect($run->status)->toBe(PayRunStatus::Draft)
        ->and((int) $run->gross_cents)->toBe(0)
        ->and((int) $line->gross_cents)->toBe(0)
        ->and((int) $line->cpp_employee_computed_cents)->toBe(0)
        ->and((int) $line->federal_tax_computed_cents)->toBe(0);
});

// ── Where coverage ends ─────────────────────────────────────────────────────

it('covers December 31 of the last loaded year but not January 1 after it, federally', function () {
    expect(FederalConstants::for(unloadedYearCoveredThrough()))->not->toBeNull()
        ->and(FederalConstants::for(unloadedYearDate('01-01')))->toBeNull()
        ->and(FederalConstants::for(unloadedYearDate('07-15')))->toBeNull()
        ->and(FederalConstants::coveredThrough())->toBe(unloadedYearCoveredThrough());
});

it('covers December 31 of the last loaded year but not January 1 after it, in each province', function (string $province) {
    expect(ProvincialConstants::for($province, unloadedYearCoveredThrough()))->not->toBeNull()
        ->and(ProvincialConstants::for($province, unloadedYearDate('01-01')))->toBeNull()
        ->and(ProvincialConstants::coveredThrough($province))->toBe(unloadedYearCoveredThrough());
})->with(['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT']);

it('still resolves every loaded table on its own effective date', function () {
    // payroll:verify-constants reads each period back through for().
    foreach (FederalConstants::loadedPeriods() as $period) {
        expect(FederalConstants::for($period))->not->toBeNull("federal {$period}")
            ->and(ProvincialConstants::for('QC', $period))->not->toBeNull("QC {$period}");
    }
});

it('lets a table cover only its own year, up to the next loaded table', function () {
    $tables = [
        '2025-01-01' => ['t' => 'jan-2025'],
        '2025-07-01' => ['t' => 'jul-2025'],
        '2027-07-01' => ['t' => 'jul-2027'], // no 2026 tables, no January 2027 table
    ];

    $pick = fn (string $date) => PayrollTableCoverage::select($tables, $date)['t'] ?? null;

    expect($pick('2024-12-31'))->toBeNull()
        ->and($pick('2025-06-30'))->toBe('jan-2025')
        ->and($pick('2025-07-01'))->toBe('jul-2025')
        ->and($pick('2025-12-31'))->toBe('jul-2025')
        ->and($pick('2026-01-01'))->toBeNull()   // a year with no table
        ->and($pick('2027-06-30'))->toBeNull()   // its January table is missing
        ->and($pick('2027-07-01'))->toBe('jul-2027')
        ->and($pick('2028-01-01'))->toBeNull()
        ->and(PayrollTableCoverage::coveredThrough($tables))->toBe('2027-12-31')
        ->and(PayrollTableCoverage::coveredThrough([]))->toBeNull();
});

// ── What the exception tells people ─────────────────────────────────────────

it('says the tables for that date are not installed yet, in English and French', function () {
    try {
        app(PayrollConstantsRepository::class)->resolve('ON', CarbonImmutable::parse(unloadedYearDate('01-15')));
        $this->fail('Expected MissingPayrollConstantsException.');
    } catch (MissingPayrollConstantsException $e) {
        $date = unloadedYearDate('01-15');
        $through = unloadedYearCoveredThrough();

        expect($e->isPastCoverage())->toBeTrue()
            ->and($e->payDate)->toBe($date)
            ->and($e->coveredThrough)->toBe($through)
            ->and($e->getMessage())->toContain("the loaded tables cover pay dates through {$through}")
            ->and($e->userMessage())->toBe("Payroll tax tables for {$date} aren’t installed yet. Pay dates after {$through} can be calculated once LineLedger is updated with the new tables.");

        app()->setLocale('fr');

        expect($e->userMessage())->toBe("Les tables de retenues sur la paie pour le {$date} ne sont pas encore installées. Les dates de paie après le {$through} pourront être calculées une fois LineLedger mis à jour avec les nouvelles tables.");
    }
});

it('says there are no tables for a pay date before the first loaded table', function () {
    try {
        app(PayrollConstantsRepository::class)->resolve('ON', CarbonImmutable::parse('2024-12-20'));
        $this->fail('Expected MissingPayrollConstantsException.');
    } catch (MissingPayrollConstantsException $e) {
        expect($e->isPastCoverage())->toBeFalse()
            ->and($e->userMessage())->toBe('LineLedger has no payroll tax tables for 2024-12-20.');
    }
});

// ── The pay-run pages block the run instead of a 500 ────────────────────────

it('saves the draft and explains the block when Calculate is pressed for that year', function () {
    $draft = unloadedYearDraftRun($this->company, unloadedYearDate('01-15'));
    $date = unloadedYearDate('01-15');

    Livewire::test('pages::payroll.pay-runs.form', ['company' => $this->company, 'payRun' => $draft])
        ->call('calculate')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['dataset']['variant'] ?? null) === 'danger'
            && str_contains($params['slots']['text'] ?? '', "Payroll tax tables for {$date} aren’t installed yet."))
        ->assertRedirect(route('pay-runs.show', ['company' => $this->company, 'payRun' => $draft]));

    $run = PayRun::query()->sole();

    expect($run->status)->toBe(PayRunStatus::Draft)
        ->and((int) $run->gross_cents)->toBe(0);
});

it('keeps one saved draft when a new run in that year is calculated from the create form', function () {
    [$schedule, $employee] = unloadedYearEmployee($this->company);

    $form = Livewire::test('pages::payroll.pay-runs.form', ['company' => $this->company])
        ->set('payroll_schedule_id', $schedule->id)
        ->set('period_start_date', unloadedYearLastLoaded().'-12-26')
        ->set('period_end_date', unloadedYearDate('01-08'))
        ->set('pay_date', unloadedYearDate('01-15'))
        ->set("rows.{$employee->id}.selected", true)
        ->call('calculate')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['dataset']['variant'] ?? null) === 'danger');

    $run = PayRun::query()->sole();

    // It lands on the saved draft, so pressing Calculate again can't create another.
    $form->assertRedirect(route('pay-runs.show', ['company' => $this->company, 'payRun' => $run]));

    expect($run->status)->toBe(PayRunStatus::Draft)
        ->and($run->pay_date->toDateString())->toBe(unloadedYearDate('01-15'));
});

it('shows why a draft in that year cannot be calculated, and refuses a recalculation', function () {
    $run = unloadedYearDraftRun($this->company, unloadedYearDate('01-15'));
    $date = unloadedYearDate('01-15');

    Livewire::test('pages::payroll.pay-runs.show', ['company' => $this->company, 'payRun' => $run])
        ->assertSee('This pay run can’t be calculated or posted')
        ->assertSee("Payroll tax tables for {$date} aren’t installed yet.")
        ->call('recalculate')
        ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['dataset']['variant'] ?? null) === 'danger'
            && str_contains($params['slots']['text'] ?? '', "Payroll tax tables for {$date} aren’t installed yet."));

    expect($run->fresh()->status)->toBe(PayRunStatus::Draft);
});

it('shows no tables notice on a draft the loaded tables cover', function () {
    $run = unloadedYearDraftRun($this->company, unloadedYearLastLoaded().'-12-18');

    Livewire::test('pages::payroll.pay-runs.show', ['company' => $this->company, 'payRun' => $run])
        ->assertDontSee('This pay run can’t be calculated or posted');
});

it('refuses to post a run calculated on the superseded tables before this fix', function () {
    $run = unloadedYearStaleCalculatedRun($this->company);

    expect($run->status)->toBe(PayRunStatus::Calculated)
        ->and((int) $run->gross_cents)->toBeGreaterThan(0)
        ->and(fn () => app(PayRunPoster::class)->post($run))->toThrow(MissingPayrollConstantsException::class);

    expect($run->fresh()->status)->toBe(PayRunStatus::Calculated)
        ->and($run->fresh()->journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->where('source_type', PayRun::class)->count())->toBe(0);
});

it('explains the refusal when posting that run from the pay-run page', function () {
    $run = unloadedYearStaleCalculatedRun($this->company);
    $date = unloadedYearDate('01-15');

    Livewire::test('pages::payroll.pay-runs.show', ['company' => $this->company, 'payRun' => $run])
        ->assertSee('This pay run can’t be calculated or posted')
        ->call('post')
        ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['dataset']['variant'] ?? null) === 'danger'
            && str_contains($params['slots']['text'] ?? '', "Payroll tax tables for {$date} aren’t installed yet."));

    expect($run->fresh()->status)->toBe(PayRunStatus::Calculated)
        ->and($run->fresh()->journal_entry_id)->toBeNull();
});

// ── The employee setup form no longer offers last year's basic amounts ─────

it('does not prefill the last loaded year’s basic amounts for a new employee in that year', function () {
    $this->travelTo(CarbonImmutable::parse(unloadedYearDate('01-05').' 12:00'));
    $employee = Contact::create(['display_name' => 'Nora Newyear', 'is_employee' => true]);

    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $employee])
        ->assertSet('td1_federal_claim', '')
        ->assertSet('td1_provincial_claim', '')
        ->call('prefillTd1')
        ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['dataset']['variant'] ?? null) === 'danger'
            && ($params['slots']['text'] ?? '') === 'No payroll tables are loaded for that province and date.')
        ->assertSet('td1_federal_claim', '');
});
