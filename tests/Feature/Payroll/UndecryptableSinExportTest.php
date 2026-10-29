<?php

use App\Actions\Payroll\SavePayRun;
use App\Enums\PayBasis;
use App\Enums\RoeReason;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\Rl1SlipCalculator;
use App\Services\Reporting\Rl1XmlGenerator;
use App\Services\Reporting\RoeCalculator;
use App\Services\Reporting\RoeXmlGenerator;
use App\Services\Reporting\T4SlipCalculator;
use App\Services\Reporting\T4XmlGenerator;
use Illuminate\Support\Facades\DB;

/*
 * A SIN whose ciphertext no longer decrypts (a database restored under another
 * APP_KEY) files the unknown-SIN placeholder on every slip export instead of
 * failing it. The T4 already did; the RL-1 and ROE crashed. (D6Payroll-19.)
 */

beforeEach(function () {
    $this->company = Company::factory()->create([
        'address_country' => 'CA',
        'features_payroll' => true,
        // What the RL-1 and ROE exports need before they produce a file: the CRA
        // payroll account and payroll contact (ROE Blocks 5 and 16) and the
        // Revenu Québec identification and file numbers (RL-1).
        'payroll_business_number' => '123456789',
        'payroll_rp_account' => 'RP0001',
        'payroll_contact_name' => 'Pat Payroll',
        'payroll_contact_phone' => '613-555-0101',
        'payroll_rq_identification_number' => '1234567890',
        'payroll_rq_file_number' => 'RS0001',
    ]);
    app()->instance('current_company', $this->company);
    $schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Restored Employee', 'first_name' => 'Restored', 'last_name' => 'Employee', 'is_employee' => true]);
    $this->profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'QC',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 5200000,
        'payroll_schedule_id' => $schedule->id,
        'td1_federal_claim_cents' => 1645200,
        'td1_provincial_claim_cents' => 1895200,
        'hire_date' => '2025-01-06',
    ]);

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $schedule->id,
        'period_start_date' => '2026-03-01',
        'period_end_date' => '2026-03-14',
        'pay_date' => '2026-03-20',
        'bank_account_id' => Account::query()->where('code', '1000')->value('id'),
        'lines' => [['contact_id' => $this->employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());

    // Ciphertext written under another APP_KEY, as after a database restored
    // onto a server with a different key.
    DB::table('employee_payroll_profiles')->where('id', $this->profile->id)
        ->update(['sin_encrypted' => 'eyJpdiI6IjEyMyIsInZhbHVlIjoiYWJjIiwibWFjIjoiZGVmIn0=']);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('exports the T4 XML with the unknown-SIN placeholder', function () {
    $calc = app(T4SlipCalculator::class);
    $xml = app(T4XmlGenerator::class)->generate($this->company, 2026, $calc->slipsForYear($this->company, 2026), $calc->summary($this->company, 2026));

    expect($xml)->toContain('<sin>000000000</sin>');
});

it('exports the RL-1 XML with the unknown-SIN placeholder instead of crashing', function () {
    $calc = app(Rl1SlipCalculator::class);
    $xml = app(Rl1XmlGenerator::class)->generate($this->company, 2026, $calc->slipsForYear($this->company, 2026), $calc->summary($this->company, 2026));

    expect($xml)->toContain('<NoAssuranceSociale>000000000</NoAssuranceSociale>');
});

it('exports the ROE XML with the unknown-SIN placeholder instead of crashing', function () {
    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2026-03-14');
    $xml = app(RoeXmlGenerator::class)->generate($this->company, $this->employee, $roe);

    expect($xml)->toContain('<B8>000000000</B8>');
});

it('files the real SIN on every export once it decrypts', function () {
    $this->profile->setSin('046 454 286');
    $this->profile->save();

    $t4 = app(T4SlipCalculator::class);
    $rl1 = app(Rl1SlipCalculator::class);
    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2026-03-14');

    expect(app(T4XmlGenerator::class)->generate($this->company, 2026, $t4->slipsForYear($this->company, 2026), $t4->summary($this->company, 2026)))
        ->toContain('<sin>046454286</sin>')
        ->and(app(Rl1XmlGenerator::class)->generate($this->company, 2026, $rl1->slipsForYear($this->company, 2026), $rl1->summary($this->company, 2026)))
        ->toContain('<NoAssuranceSociale>046454286</NoAssuranceSociale>')
        ->and(app(RoeXmlGenerator::class)->generate($this->company, $this->employee, $roe))
        ->toContain('<B8>046454286</B8>');
});

it('reads the SIN for the exports, or null when there is none or it does not decrypt', function () {
    $profile = fn (): EmployeePayrollProfile => EmployeePayrollProfile::query()->findOrFail($this->profile->id);

    // Ciphertext from another APP_KEY (beforeEach).
    expect($profile()->sinOrNull())->toBeNull();

    // Unencrypted, as a restore made before the fix stored it:
    // backup:repair-encrypted-columns encrypts it, nothing reads it raw.
    DB::table('employee_payroll_profiles')->where('id', $this->profile->id)->update(['sin_encrypted' => '046454286']);
    expect($profile()->sinOrNull())->toBeNull();

    $this->profile->setSin('046 454 286');
    $this->profile->save();
    expect($profile()->sinOrNull())->toBe('046454286');

    $this->profile->setSin(null);
    $this->profile->save();
    expect($profile()->sinOrNull())->toBeNull();
});
