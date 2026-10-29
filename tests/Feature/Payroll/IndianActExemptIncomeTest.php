<?php

/*
 * Indian Act tax-exempt employment income on the T4 (audit D6Payroll-13).
 *
 * CRA RC4120, "Indians – Employment": "Do not report an amount in box 14 for
 * tax-exempt employment income. Instead, in the Other information area, use
 * code 71 – Indian Act (exempt income) – Employment." RPP contributions and
 * union dues relating to it are reported "using code 94" and "using code 95",
 * not in boxes 20 and 44. "Tax-exempt employment income ... is insurable and
 * you must deduct EI premiums"; it "is not pensionable unless you elected to
 * provide CPP/QPP coverage" (T4001: Form CPT124), and box 26 is "0" without
 * that election. The CRA T4 XML specification (2026) files code 71 as
 * <indn_empe_amt> under <OTH_INFO>: "If you are reporting this type of income,
 * enter 0.00 in box 14."
 */

use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRunLine;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\T4SlipCalculator;
use App\Services\Reporting\T4XmlGenerator;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $this->company);
    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->value('id');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * A Manitoba employee paid $2,000 biweekly ($52,000 salary).
 *
 * @param  array<string, mixed>  $profile
 */
function indianexempt_employee(string $name, array $profile = []): Contact
{
    $employee = Contact::create(['display_name' => $name, 'is_employee' => true]);

    EmployeePayrollProfile::factory()->create($profile + [
        'contact_id' => $employee->id,
        'province_of_employment' => 'MB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 5200000,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1645200,
        'td1_provincial_claim_cents' => 1578000,
    ]);

    return $employee;
}

function indianexempt_post(Contact $employee, string $start, string $end, string $pay): void
{
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => $start,
        'period_end_date' => $end,
        'pay_date' => $pay,
        'bank_account_id' => test()->bank,
        'lines' => [['contact_id' => $employee->id]],
    ]);

    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());
}

/**
 * @return array<string, mixed>
 */
function indianexempt_slip(Contact $employee): array
{
    return collect(app(T4SlipCalculator::class)->slipsForYear(test()->company, 2026))->firstWhere('contact_id', $employee->id);
}

it('reports Indian Act exempt employment income under code 71, not box 14', function () {
    // No CPT124 election, so CPP is switched off for the exempt income.
    $employee = indianexempt_employee('Exempt Employee', ['indian_act_exempt' => true, 'cpp_exempt' => true]);

    indianexempt_post($employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $slip = indianexempt_slip($employee);

    expect($slip['box22'])->toBe(0)                 // no income tax withheld
        ->and($slip['box14'])->toBe(0)              // exempt income is not box 14 …
        ->and($slip['other']['71'] ?? 0)->toBe(200000) // … it is code 71
        ->and($slip['box18'])->toBe(3260)           // EI still deducted: 1.63% × $2,000
        ->and($slip['box24'])->toBe(200000)         // insurable earnings
        ->and($slip['box16'])->toBe(0)              // not pensionable without the election
        ->and($slip['box26'])->toBe(0);
});

it('reports CPP and pensionable earnings on exempt income when coverage was elected', function () {
    // An employer that filed CPT124 deducts CPP, so box 16 and box 26 carry it.
    $employee = indianexempt_employee('Covered Employee', ['indian_act_exempt' => true]);

    indianexempt_post($employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $slip = indianexempt_slip($employee);

    expect($slip['box14'])->toBe(0)
        ->and($slip['other']['71'] ?? 0)->toBe(200000)
        ->and($slip['box16'])->toBe(11099)          // (200000 − 350000/26) × 5.95%
        ->and($slip['box26'])->toBe(200000);
});

it('moves RPP contributions and union dues on exempt income to codes 94 and 95', function () {
    $employee = indianexempt_employee('Union Member', ['indian_act_exempt' => true, 'cpp_exempt' => true]);
    $profile = $employee->payrollProfile;

    foreach ([['rpp', 'Pension plan', '20', 6000], ['union', 'Union dues', '44', 2500]] as $i => [$code, $name, $box, $cents]) {
        $profile->recurringItems()->create([
            'kind' => 'deduction', 'code' => $code, 'name' => $name, 'calc_type' => 'fixed',
            'amount_cents' => $cents, 't4_box' => $box, 'is_active' => true, 'line_order' => $i,
        ]);
    }

    indianexempt_post($employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $other = indianexempt_slip($employee)['other'];

    expect($other['94'] ?? 0)->toBe(6000)
        ->and($other['95'] ?? 0)->toBe(2500)
        ->and($other)->not->toHaveKey('20')
        ->and($other)->not->toHaveKey('44');
});

it('counts a taxable benefit paid on exempt employment in code 71', function () {
    $employee = indianexempt_employee('Benefit Employee', ['indian_act_exempt' => true, 'cpp_exempt' => true]);

    $employee->payrollProfile->recurringItems()->create([
        'kind' => 'contribution', 'code' => 'group_life', 'name' => 'Group life', 'calc_type' => 'fixed',
        'amount_cents' => 10000, 't4_box' => '40', 'is_active' => true, 'line_order' => 0,
        'taxable_federal' => true, 'taxable_provincial' => true,
    ]);

    indianexempt_post($employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $slip = indianexempt_slip($employee);

    expect($slip['box14'])->toBe(0)
        ->and($slip['other']['71'] ?? 0)->toBe(210000);
});

it('keeps box 14 for an employee who is income tax exempt without the Indian Act exemption', function () {
    $employee = indianexempt_employee('Low Earner', ['income_tax_exempt' => true]);

    indianexempt_post($employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $slip = indianexempt_slip($employee);

    expect($slip['box22'])->toBe(0)
        ->and($slip['box14'])->toBe(200000)
        ->and($slip['other'])->not->toHaveKey('71');
});

it('splits the year between box 14 and code 71 when the exemption changes', function () {
    $employee = indianexempt_employee('Moved On Reserve', ['cpp_exempt' => true]);
    $profile = $employee->payrollProfile;

    indianexempt_post($employee, '2026-03-01', '2026-03-14', '2026-03-20'); // taxable

    $profile->forceFill(['indian_act_exempt' => true])->save();
    indianexempt_post($employee, '2026-03-15', '2026-03-28', '2026-04-03'); // exempt

    // Each line keeps the status it was calculated with.
    $profile->forceFill(['indian_act_exempt' => false])->save();

    $slip = indianexempt_slip($employee);

    expect($slip['box14'])->toBe(200000)
        ->and($slip['other']['71'] ?? 0)->toBe(200000)
        ->and($slip['box22'])->toBeGreaterThan(0);
});

it('follows the profile for lines calculated before the exemption was recorded on them', function () {
    $employee = indianexempt_employee('Earlier Line', ['indian_act_exempt' => true, 'cpp_exempt' => true]);

    indianexempt_post($employee, '2026-03-01', '2026-03-14', '2026-03-20');

    // A line posted before the column existed has no stamp.
    $employee->payrollProfile->forceFill(['indian_act_exempt' => false])->save();
    PayRunLine::query()->where('contact_id', $employee->id)->update(['indian_act_exempt' => null]);

    expect(indianexempt_slip($employee)['box14'])->toBe(200000);

    $employee->payrollProfile->forceFill(['indian_act_exempt' => true])->save();

    expect(indianexempt_slip($employee)['box14'])->toBe(0)
        ->and(indianexempt_slip($employee)['other']['71'] ?? 0)->toBe(200000);
});

it('files code 71 as indn_empe_amt with box 14 at 0.00 in the T4 XML', function () {
    $employee = indianexempt_employee('Xml Employee', ['indian_act_exempt' => true, 'cpp_exempt' => true]);

    indianexempt_post($employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $calculator = app(T4SlipCalculator::class);
    $xml = app(T4XmlGenerator::class)->generate($this->company, 2026, $calculator->slipsForYear($this->company, 2026), $calculator->summary($this->company, 2026));

    $doc = new DOMDocument;
    $doc->loadXML($xml);
    $xpath = new DOMXPath($doc);

    expect($xpath->evaluate('string(//T4Slip/T4_AMT/empt_incamt)'))->toBe('0.00')
        ->and($xpath->evaluate('string(//T4Slip/OTH_INFO/indn_empe_amt)'))->toBe('2000.00')
        ->and($xpath->evaluate('string(//T4Slip/cpp_qpp_xmpt_cd)'))->toBe('1');
});

it('saves the Indian Act exemption from the payroll setup form', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);

    $employee = Contact::create(['display_name' => 'Form Employee', 'is_employee' => true]);

    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $employee])
        ->set('province_of_employment', 'MB')
        ->set('pay_basis', 'salary')
        ->set('annual_salary', '52,000.00')
        ->set('payroll_schedule_id', $this->schedule->id)
        ->set('td1_federal_claim', '16,452.00')
        ->set('td1_provincial_claim', '15,780.00')
        ->set('indian_act_exempt', true)
        ->set('cpp_exempt', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($employee->fresh()->payrollProfile->indian_act_exempt)->toBeTrue();

    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $employee->fresh()])
        ->assertSet('indian_act_exempt', true);
});
