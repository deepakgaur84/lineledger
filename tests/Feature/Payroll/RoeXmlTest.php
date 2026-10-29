<?php

/*
 * The Record of Employment file for Service Canada's ROE Web "Upload a Payroll
 * Extract File" (XML version 2.0).
 *
 * Official sources (canada.ca, retrieved 2026-10-29):
 *   - Schema: tests/fixtures/service-canada/roe-web-payroll-extract-2.0/PayrollExtractXmlV2.xsd
 *     (see the README beside it for the URL).
 *   - ROE Web user requirements, Appendix D: ROE payroll extract file transfer, XML
 *     version 2.0 (block-by-block edit rules, file naming).
 *   - How to complete the record of employment (ROE) form (block numbers, and Block
 *     15B step 3: "You must report all insurable earnings the employee received, not
 *     just the EI maximum insurable earnings amount").
 */

use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\PayFrequency;
use App\Enums\RoeReason;
use App\Exceptions\Payroll\RoeExportBlockedException;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Services\Reporting\RoeCalculator;
use App\Services\Reporting\RoeXmlGenerator;
use App\Services\Reporting\T4SlipCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create([
        'address_country' => 'CA',
        'features_payroll' => true,
        // The GST/HST account: it must never stand in for the payroll account.
        'tax_number' => '123456789RT0001',
        'payroll_business_number' => '123456789',
        'payroll_rp_account' => 'RP0001',
        'payroll_contact_name' => 'Pat Payroll',
        'payroll_contact_phone' => '613-555-0101',
        'address_line1' => '1 Main St',
        'address_city' => 'Calgary',
        'address_region' => 'AB',
        'address_postal_code' => 'T2P 1J9',
    ]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create([
        'display_name' => 'Wanda Leaver', 'first_name' => 'Wanda', 'last_name' => 'Leaver', 'is_employee' => true,
        'job_title' => 'Baker', 'employee_id' => 'E-0042',
        'billing_line1' => '22 Elm St', 'billing_city' => 'Calgary', 'billing_region' => 'AB', 'billing_postal_code' => 't2p 2b2',
    ]);
    $profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'default_hours_per_period' => 80,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1645200,
        'td1_provincial_claim_cents' => 2276900,
        'hire_date' => '2024-01-08',
    ]);
    $profile->setSin('046454286');
    $profile->save();

    $this->run = payrollFilingPostRun($this->schedule, $this->employee, '2026-03-01', '2026-03-14', '2026-03-20');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('generates a payroll extract that validates against the official ROE Web XML V2 schema', function () {
    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2026-03-14');
    $xml = app(RoeXmlGenerator::class)->generate($this->company, $this->employee, $roe);

    expect(payrollFilingSchemaErrors($xml, base_path('tests/fixtures/service-canada/roe-web-payroll-extract-2.0/PayrollExtractXmlV2.xsd')))->toBe([]);
});

it('numbers the blocks as the ROE form does and fills them from payroll settings', function () {
    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2026-03-14');
    $x = payrollFilingXpath(app(RoeXmlGenerator::class)->generate($this->company, $this->employee, $roe));
    $line = $this->run->lines->first();

    expect($x->evaluate('string(/ROEHEADER/@FileVersion)'))->toBe('W-2.0')
        ->and($x->evaluate('string(/ROEHEADER/@SoftwareVendor)'))->toBe('LineLedger')
        // Uploaded as a draft: the employer reviews and submits it in ROE Web.
        ->and($x->evaluate('string(/ROEHEADER/ROE/@Issue)'))->toBe('D')
        ->and($x->evaluate('string(/ROEHEADER/ROE/@PrintingLanguage)'))->toBe('E')
        // Block 3: the employee's payroll reference number.
        ->and($x->evaluate('string(/ROEHEADER/ROE/B3)'))->toBe('E-0042')
        // Block 5: the CRA payroll account (BN + RP), all 15 characters, never the GST/HST number.
        ->and($x->evaluate('string(/ROEHEADER/ROE/B5)'))->toBe('123456789RP0001')
        // Block 6: pay period type, biweekly.
        ->and($x->evaluate('string(/ROEHEADER/ROE/B6)'))->toBe('B')
        // Block 8: SIN. Block 9: the employee's name and address.
        ->and($x->evaluate('string(/ROEHEADER/ROE/B8)'))->toBe('046454286')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B9/FN)'))->toBe('Wanda')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B9/LN)'))->toBe('Leaver')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B9/A1)'))->toBe('22 Elm St')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B9/A2)'))->toBe('Calgary')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B9/A3)'))->toBe('Alberta, Canada')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B9/PC)'))->toBe('T2P2B2')
        // Blocks 10, 11, 12: first day worked, last day for which paid, final pay period ending.
        ->and($x->evaluate('string(/ROEHEADER/ROE/B10)'))->toBe('2024-01-08')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B11)'))->toBe('2026-03-14')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B12)'))->toBe('2026-03-14')
        // Block 13: occupation. Block 14: expected date of recall, unknown after a layoff.
        ->and($x->evaluate('string(/ROEHEADER/ROE/B13)'))->toBe('Baker')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B14/CD)'))->toBe('U')
        ->and($x->query('/ROEHEADER/ROE/B14/DT')->length)->toBe(0)
        // Block 15A: total insurable hours as a whole number.
        ->and($x->evaluate('string(/ROEHEADER/ROE/B15A)'))->toBe('80')
        // Block 15C: one <PP nbr> per pay period, the final pay period first.
        ->and($x->evaluate('string(/ROEHEADER/ROE/B15C/PP[@nbr="1"]/AMT)'))->toBe(number_format($line->gross_cents / 100, 2, '.', ''))
        ->and($x->query('/ROEHEADER/ROE/B15C/PP')->length)->toBe(1)
        // Block 15B is calculated by ROE Web from Block 15C, so the file has none.
        ->and($x->query('//B15B')->length)->toBe(0)
        // Block 16: the three-character reason code and the payroll contact.
        ->and($x->evaluate('string(/ROEHEADER/ROE/B16/CD)'))->toBe('A00')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B16/FN)'))->toBe('Pat')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B16/LN)'))->toBe('Payroll')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B16/AC)'))->toBe('613')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B16/TEL)'))->toBe('5550101')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B20)'))->toBe('E')
        // The old home-made envelope and block names are gone.
        ->and($x->query('//ROEWEB_BULK | //BLK14_SIN | //BLK19_EMPLOYEE | //BLK9_EMPLOYER')->length)->toBe(0);
});

it('marks an employee who quit as not returning and uses the quit code', function () {
    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::Quit, '2026-03-14');
    $x = payrollFilingXpath(app(RoeXmlGenerator::class)->generate($this->company, $this->employee, $roe));

    // Appendix D: code Y (expected recall) is not allowed with E00, G00 or M00.
    expect($x->evaluate('string(/ROEHEADER/ROE/B14/CD)'))->toBe('N')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B16/CD)'))->toBe('E00');
});

it('prints the ROE in French for an employee whose documents are in French', function () {
    $this->employee->update(['locale' => 'fr']);

    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2026-03-14');
    $x = payrollFilingXpath(app(RoeXmlGenerator::class)->generate($this->company, $this->employee->fresh(), $roe));

    expect($x->evaluate('string(/ROEHEADER/ROE/@PrintingLanguage)'))->toBe('F');
});

it('reports all insurable earnings per pay period on the ROE, not only earnings under the EI maximum', function () {
    // A $9,000 biweekly cheque after $66,000 of 2026 insurable earnings: only $2,900
    // of it was under the EI maximum insurable earnings ($68,900), so T4 box 24 and
    // the EI premium stop there. The ROE reports the whole cheque.
    $high = Contact::create(['display_name' => 'Hilda High', 'first_name' => 'Hilda', 'last_name' => 'High', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $high->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 23400000,
        'default_hours_per_period' => 80,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1645200,
        'td1_provincial_claim_cents' => 2276900,
        'hire_date' => '2025-01-06',
        'opening_balances_as_of' => '2026-03-31',
        'opening_insurable_cents' => 6600000,
        'opening_ei_employee_cents' => 107580,
    ]);
    payrollFilingPostRun($this->schedule, $high, '2026-04-05', '2026-04-18', '2026-04-24');

    $roe = app(RoeCalculator::class)->build($this->company, $high, RoeReason::ShortageOfWork, '2026-04-18');
    $t4 = collect(app(T4SlipCalculator::class)->slipsForYear($this->company, 2026))->firstWhere('contact_id', $high->id);

    expect($roe['periods'][0]['insurable_earnings_cents'])->toBe(900000)
        ->and($roe['total_insurable_earnings_cents'])->toBe(900000)
        // T4 box 24 stays capped at the EI maximum.
        ->and($t4['box24'])->toBe(290000);

    $x = payrollFilingXpath(app(RoeXmlGenerator::class)->generate($this->company, $high, $roe));

    expect($x->evaluate('string(/ROEHEADER/ROE/B15C/PP[@nbr="1"]/AMT)'))->toBe('9000.00');
});

it('reads all insurable earnings from the earning lines of pay runs posted before they were stored', function () {
    $line = $this->run->lines->first();
    DB::table('pay_run_lines')->where('id', $line->id)->update(['roe_insurable_earnings_cents' => null]);

    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2026-03-14');

    expect($roe['periods'][0]['insurable_earnings_cents'])->toBe((int) $line->gross_cents);

    // An employee whose employment is not insurable has no insurable earnings.
    $this->employee->payrollProfile->update(['ei_exempt' => true]);

    $roe = app(RoeCalculator::class)->build($this->company, $this->employee->fresh(), RoeReason::ShortageOfWork, '2026-03-14');

    expect($roe['periods'][0]['insurable_earnings_cents'])->toBe(0);
});

it('lists every consecutive pay period in Block 15C, entering 0.00 for a period with no pay', function () {
    // Nothing was paid for the period ending 2026-03-28.
    payrollFilingPostRun($this->schedule, $this->employee, '2026-03-29', '2026-04-11', '2026-04-17');

    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2026-04-11');
    $x = payrollFilingXpath(app(RoeXmlGenerator::class)->generate($this->company, $this->employee, $roe));
    $paid = number_format($this->run->lines->first()->gross_cents / 100, 2, '.', '');

    expect(array_column($roe['periods'], 'period_end'))->toBe(['2026-04-11', '2026-03-28', '2026-03-14'])
        ->and($x->evaluate('string(/ROEHEADER/ROE/B15C/PP[@nbr="1"]/AMT)'))->toBe($paid)
        ->and($x->evaluate('string(/ROEHEADER/ROE/B15C/PP[@nbr="2"]/AMT)'))->toBe('0.00')
        ->and($x->evaluate('string(/ROEHEADER/ROE/B15C/PP[@nbr="3"]/AMT)'))->toBe($paid)
        ->and($x->evaluate('string(/ROEHEADER/ROE/B15A)'))->toBe('160');
});

it('rounds total insurable hours up to the next whole hour', function () {
    $this->employee->payrollProfile->update(['default_hours_per_period' => 75.25]);
    payrollFilingPostRun($this->schedule, $this->employee, '2026-03-15', '2026-03-28', '2026-04-03');

    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2026-03-28');
    $x = payrollFilingXpath(app(RoeXmlGenerator::class)->generate($this->company, $this->employee, $roe));

    // 80 + 75.25 = 155.25 hours, entered as 156.
    expect((float) $roe['total_insurable_hours'])->toBe(155.25)
        ->and($x->evaluate('string(/ROEHEADER/ROE/B15A)'))->toBe('156');
});

it('totals Block 15B over the most recent pay periods the ROE guide counts', function () {
    // Monthly pay: Block 15C lists up to 13 periods, Block 15B totals the last 7.
    $monthly = PayrollSchedule::factory()->frequency(PayFrequency::Monthly)->create();
    $staff = Contact::create(['display_name' => 'Molly Monthly', 'first_name' => 'Molly', 'last_name' => 'Monthly', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $staff->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 4800000,
        'default_hours_per_period' => 160,
        'payroll_schedule_id' => $monthly->id,
        'td1_federal_claim_cents' => 1645200,
        'td1_provincial_claim_cents' => 2276900,
        'hire_date' => '2025-01-01',
    ]);

    foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'] as $month) {
        $end = CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString();
        payrollFilingPostRun($monthly, $staff, $month.'-01', $end, $end);
    }

    $roe = app(RoeCalculator::class)->build($this->company, $staff, RoeReason::ShortageOfWork, '2026-08-31');
    $x = payrollFilingXpath(app(RoeXmlGenerator::class)->generate($this->company, $staff, $roe));

    expect($roe['periods'])->toHaveCount(8)
        ->and($roe['total_insurable_earnings_cents'])->toBe(7 * 400000)
        ->and($x->evaluate('string(/ROEHEADER/ROE/B6)'))->toBe('M')
        ->and($x->query('/ROEHEADER/ROE/B15C/PP')->length)->toBe(8);
});

it('refuses the ROE file until the CRA payroll account is set, never falling back to the GST/HST number', function () {
    $this->company->update(['payroll_business_number' => null, 'payroll_rp_account' => null]);
    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2026-03-14');

    expect(fn () => app(RoeXmlGenerator::class)->generate($this->company->fresh(), $this->employee, $roe))
        ->toThrow(RoeExportBlockedException::class, 'Settings → Payroll');

    // A Business Number with the GST/HST (RT) program account is not a payroll account.
    $this->company->update(['payroll_business_number' => '123456789RT0001']);

    expect(fn () => app(RoeXmlGenerator::class)->generate($this->company->fresh(), $this->employee, $roe))
        ->toThrow(RoeExportBlockedException::class);
});

it('takes the payroll account entered as one 15-character number or as the BN with a bare program number', function () {
    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2026-03-14');

    $this->company->update(['payroll_business_number' => '123 456 789 RP 0002', 'payroll_rp_account' => null]);
    $x = payrollFilingXpath(app(RoeXmlGenerator::class)->generate($this->company->fresh(), $this->employee, $roe));
    expect($x->evaluate('string(/ROEHEADER/ROE/B5)'))->toBe('123456789RP0002');

    $this->company->update(['payroll_business_number' => '123456789', 'payroll_rp_account' => '0003']);
    $x = payrollFilingXpath(app(RoeXmlGenerator::class)->generate($this->company->fresh(), $this->employee, $roe));
    expect($x->evaluate('string(/ROEHEADER/ROE/B5)'))->toBe('123456789RP0003');
});

it('refuses the ROE file until the payroll contact name and phone are set', function () {
    $this->company->update(['payroll_contact_name' => null, 'payroll_contact_phone' => '555-0101']);
    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2026-03-14');

    expect(fn () => app(RoeXmlGenerator::class)->generate($this->company->fresh(), $this->employee, $roe))
        ->toThrow(RoeExportBlockedException::class, 'Block 16');
});

it('downloads the payroll extract with the .BLK extension ROE Web requires', function () {
    Livewire::test('pages::payroll.reports.roe', ['company' => $this->company])
        ->set('contactId', $this->employee->id)
        ->set('reason', 'A')
        ->set('lastDay', '2026-03-14')
        ->call('downloadXml')
        ->assertFileDownloaded('roe-'.$this->employee->id.'.blk');
});

it('explains what is missing instead of downloading a file ROE Web would reject', function () {
    $this->company->update(['payroll_business_number' => null, 'payroll_rp_account' => null]);

    Livewire::test('pages::payroll.reports.roe', ['company' => $this->company->fresh()])
        ->set('contactId', $this->employee->id)
        ->set('lastDay', '2026-03-14')
        ->call('downloadXml')
        ->assertDispatched('toast-show')
        ->assertNoFileDownloaded();
});

it('files the unknown-SIN placeholder in Block 8 when the SIN no longer decrypts', function () {
    // Ciphertext written under another APP_KEY, as after a restore onto another server.
    DB::table('employee_payroll_profiles')->where('contact_id', $this->employee->id)
        ->update(['sin_encrypted' => 'eyJpdiI6IjEyMyIsInZhbHVlIjoiYWJjIiwibWFjIjoiZGVmIn0=']);

    $employee = $this->employee->fresh();
    $roe = app(RoeCalculator::class)->build($this->company, $employee, RoeReason::ShortageOfWork, '2026-03-14');
    $x = payrollFilingXpath(app(RoeXmlGenerator::class)->generate($this->company, $employee, $roe));

    expect($x->evaluate('string(/ROEHEADER/ROE/B8)'))->toBe('000000000');
});

it('refuses the ROE file for an employee with no posted pay, which would have no Block 15C', function () {
    $newHire = Contact::create(['display_name' => 'Nora New', 'first_name' => 'Nora', 'last_name' => 'New', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $newHire->id,
        'province_of_employment' => 'AB',
        'payroll_schedule_id' => $this->schedule->id,
        'hire_date' => '2026-03-02',
    ]);

    $roe = app(RoeCalculator::class)->build($this->company, $newHire, RoeReason::Quit, '2026-03-06');

    expect($roe['periods'])->toBe([])
        ->and(fn () => app(RoeXmlGenerator::class)->generate($this->company, $newHire, $roe))
        ->toThrow(RoeExportBlockedException::class, 'no posted pay');
});
