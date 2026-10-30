<?php

/*
 * CRA T4 and T4A XML for Internet File Transfer (audit findings D6Payroll-03,
 * -04, -05, -06 and -20).
 *
 * Every generated file is validated against the CRA's own schema package
 * 1-27-1 (tests/fixtures/cra-xml/1-27-1, see its README): the version the CRA
 * accepts from January 11, 2027, when the 2026 slips are filed. The rules the
 * schema leaves to prose come from the XML specifications on canada.ca:
 * T619 2027V1, T4 2027V2 and T4A 2027V1.
 */

use App\Actions\Payroll\SavePayRun;
use App\Enums\ChequeStatus;
use App\Enums\CompanyRole;
use App\Enums\DentalBenefitsCode;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\T4ASlipCalculator;
use App\Services\Reporting\T4AXmlGenerator;
use App\Services\Reporting\T4SlipCalculator;
use App\Services\Reporting\T4XmlGenerator;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    // A realistic employer: the company tax number is its GST/HST account (RT);
    // the payroll program account (RP) and the CRA contact live in Payroll
    // settings.
    $this->company = Company::factory()->create([
        'name' => 'Maple Leaf Bakery',
        'address_country' => 'CA',
        'features_payroll' => true,
        'slug' => 'maple-leaf-bakery',
        'tax_number' => '123456789RT0001',
        'payroll_business_number' => '123456789',
        'payroll_rp_account' => 'RP0001',
        'payroll_contact_name' => 'Pat Payroll',
        'payroll_contact_email' => 'pat@example.com',
        'payroll_contact_phone' => '613-555-0101',
        'payroll_dental_benefits_code' => DentalBenefitsCode::NotEligible,
        'address_line1' => '1 Main St',
        'address_city' => 'Ottawa',
        'address_region' => 'ON',
        'address_postal_code' => 'K1A 0B1',
    ]);
    app()->instance('current_company', $this->company);
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * The libxml errors from validating $xml against a schema in the vendored CRA
 * package; an empty list means the file is valid.
 *
 * @return list<string>
 */
function craSlipXmlSchemaErrors(string $xml, string $schema): array
{
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    try {
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $valid = $doc->schemaValidate(base_path('tests/fixtures/cra-xml/1-27-1/'.$schema));
        $errors = array_map(fn (LibXMLError $e): string => 'line '.$e->line.': '.trim($e->message), libxml_get_errors());

        return $valid ? [] : ($errors !== [] ? $errors : ['invalid']);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function craSlipXmlPath(string $xml): DOMXPath
{
    $doc = new DOMDocument;
    $doc->loadXML($xml);

    return new DOMXPath($doc);
}

/**
 * An employee with a payroll profile. By default a high earner: one $90,000
 * biweekly cheque crosses the YMPE, so CPP2 (box 16A) is owed.
 *
 * @param  array<string, mixed>  $profile
 * @param  array<string, mixed>  $contact
 */
function craSlipXmlEmployee(array $profile = [], ?string $sin = '046454286', array $contact = []): Contact
{
    $schedule = PayrollSchedule::query()->first() ?? PayrollSchedule::factory()->create();
    $employee = Contact::create(array_merge([
        'display_name' => 'Robin Highpay',
        'first_name' => 'Robin',
        'last_name' => 'Highpay',
        'is_employee' => true,
    ], $contact));

    $model = EmployeePayrollProfile::factory()->create(array_merge([
        'contact_id' => $employee->id,
        'province_of_employment' => 'ON',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 234000000,
        'payroll_schedule_id' => $schedule->id,
        'td1_federal_claim_cents' => 1645200,
        'td1_provincial_claim_cents' => 1298900,
    ], $profile));
    $model->setSin($sin);
    $model->save();

    return $employee;
}

/** Post one March 2026 pay run paying each employee their regular cheque. */
function craSlipXmlPostRun(Contact ...$employees): void
{
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => PayrollSchedule::query()->firstOrFail()->id,
        'period_start_date' => '2026-03-01',
        'period_end_date' => '2026-03-14',
        'pay_date' => '2026-03-20',
        'bank_account_id' => test()->bank->id,
        'lines' => array_map(fn (Contact $employee): array => ['contact_id' => $employee->id], $employees),
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());
}

/**
 * The posted year's T4 slips, summary and generated XML.
 *
 * @return array{xml: string, x: DOMXPath, slips: array<int, array<string, mixed>>, summary: array<string, mixed>}
 */
function craSlipXmlT4(int $year = 2026): array
{
    $calc = app(T4SlipCalculator::class);
    $slips = $calc->slipsForYear(test()->company, $year);
    $summary = $calc->summary(test()->company, $year);
    $xml = app(T4XmlGenerator::class)->generate(test()->company, $year, $slips, $summary);

    return ['xml' => $xml, 'x' => craSlipXmlPath($xml), 'slips' => $slips, 'summary' => $summary];
}

/**
 * A hand-built T4 slip for $employee (all boxes zero unless given), for the
 * cases a single pay run can't produce.
 *
 * @param  array<string, mixed>  $boxes
 * @return array<string, mixed>
 */
function craSlipXmlSlip(Contact $employee, array $boxes = []): array
{
    return array_merge([
        'contact_id' => $employee->id, 'name' => $employee->display_name, 'sin_last4' => '4286', 'province' => 'ON',
        'box14' => 5000000, 'box16' => 0, 'box16a' => 0, 'box17' => 0, 'box17a' => 0, 'box18' => 0, 'box22' => 0,
        'box24' => 0, 'box26' => 0, 'box55' => 0, 'box56' => 0, 'other' => [],
    ], $boxes);
}

/**
 * @param  array<int, array<string, mixed>>  $slips
 * @return array<string, mixed>
 */
function craSlipXmlSummary(array $slips): array
{
    $summary = ['slip_count' => count($slips), 'employer_cpp' => 0, 'box27' => 0, 'box27a' => 0, 'employer_ei' => 0];

    foreach (['box14', 'box16', 'box16a', 'box17', 'box17a', 'box18', 'box22', 'box24', 'box26', 'box55', 'box56'] as $box) {
        $summary[$box] = array_sum(array_column($slips, $box));
    }

    return $summary;
}

function craSlipXmlCheque(Contact $vendor, int $cents, string $date = '2026-04-10'): void
{
    Cheque::create([
        'bank_account_id' => test()->bank->id,
        'cheque_no' => (string) fake()->unique()->numberBetween(1000, 99999),
        'cheque_date' => $date,
        'payee_contact_id' => $vendor->id,
        'payee_name' => $vendor->display_name,
        'amount_cents' => $cents,
        'status' => ChequeStatus::Posted,
    ]);
}

/**
 * The year's T4A slips, summary and generated XML.
 *
 * @return array{xml: string, x: DOMXPath, slips: array<int, array<string, mixed>>, summary: array<string, int>}
 */
function craSlipXmlT4a(int $year = 2026): array
{
    $calc = app(T4ASlipCalculator::class);
    $slips = $calc->slipsForYear(test()->company, $year);
    $summary = $calc->summary(test()->company, $year);
    $xml = app(T4AXmlGenerator::class)->generate(test()->company, $year, $slips, $summary);

    return ['xml' => $xml, 'x' => craSlipXmlPath($xml), 'slips' => $slips, 'summary' => $summary];
}

function craSlipXmlOwner(): User
{
    $user = User::factory()->create();
    test()->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    test()->actingAs($user);

    return $user;
}

// --- T4: schema and layout ----------------------------------------------------

it('builds a T4 file that passes the CRA schema', function () {
    craSlipXmlPostRun(craSlipXmlEmployee(), craSlipXmlEmployee(['annual_salary_cents' => 5200000], '123456782', [
        'display_name' => 'Sam Lowpay', 'first_name' => 'Sam', 'last_name' => 'Lowpay',
    ]));

    expect(craSlipXmlSchemaErrors(craSlipXmlT4()['xml'], 'T619_T4.xsd'))->toBe([]);
});

it('files the T4 under the payroll (RP) account from Payroll settings, not the GST/HST number', function () {
    craSlipXmlPostRun(craSlipXmlEmployee());
    ['x' => $x] = craSlipXmlT4();

    // T4 spec: <bn> is the "Payroll Account Number — 9 digits, RP, 4 digits" on
    // both the slip (box 54) and the summary.
    expect($x->evaluate('string(/Submission/Return/T4/T4Slip/bn)'))->toBe('123456789RP0001')
        ->and($x->evaluate('string(/Submission/Return/T4/T4Summary/bn)'))->toBe('123456789RP0001')
        ->and($x->evaluate('string(/Submission/T619/TransmitterAccountNumber/bn15)'))->toBe('123456789RP0001');
});

it('writes the T619 transmittal in the 2027 layout', function () {
    craSlipXmlPostRun(craSlipXmlEmployee());
    ['x' => $x] = craSlipXmlT4();

    $t619 = '/Submission/T619';

    // T619 2027V1: TransmitterAccountNumber (the account used to sign in)
    // replaces the retired "MM" transmitter number; sbmt_ref_id is up to 8
    // alphanumeric with no hyphen; the country code and CNTC are required.
    expect($x->query("$t619/trnmtr_nbr")->length)->toBe(0)
        ->and($x->query("$t619/rpt_tcd")->length)->toBe(0)
        ->and($x->query("$t619/TRNMTR_NM")->length)->toBe(0)
        ->and($x->query("$t619/TransmitterRepID")->length)->toBe(0)
        ->and($x->evaluate("string($t619/sbmt_ref_id)"))->toMatch('/^[A-Za-z0-9]{1,8}$/')
        ->and($x->evaluate("string($t619/summ_cnt)"))->toBe('1')
        ->and($x->evaluate("string($t619/lang_cd)"))->toBe('E')
        ->and($x->evaluate("string($t619/TransmitterName/l1_nm)"))->toBe('Maple Leaf Bakery')
        ->and($x->evaluate("string($t619/TransmitterCountryCode)"))->toBe('CAN')
        ->and($x->evaluate("string($t619/CNTC/cntc_nm)"))->toBe('Pat Payroll')
        ->and($x->evaluate("string($t619/CNTC/cntc_area_cd)"))->toBe('613')
        ->and($x->evaluate("string($t619/CNTC/cntc_phn_nbr)"))->toBe('555-0101')
        ->and($x->evaluate("string($t619/CNTC/cntc_email_area)"))->toBe('pat@example.com');
});

it('adds the representative ID to the transmittal when one is set', function () {
    $this->company->update(['payroll_cra_rep_id' => 'A1B2C3D', 'payroll_contact_phone' => '(613) 555-0101 ext. 22']);
    craSlipXmlPostRun(craSlipXmlEmployee());
    ['xml' => $xml, 'x' => $x] = craSlipXmlT4();

    expect($x->evaluate('string(/Submission/T619/TransmitterRepID/RepID)'))->toBe('A1B2C3D')
        ->and($x->evaluate('string(/Submission/T619/TransmitterAccountNumber/bn15)'))->toBe('123456789RP0001')
        ->and($x->evaluate('string(/Submission/T619/CNTC/cntc_extn_nbr)'))->toBe('22')
        ->and($x->evaluate('string(/Submission/Return/T4/T4Summary/CNTC/cntc_extn_nbr)'))->toBe('22')
        ->and(craSlipXmlSchemaErrors($xml, 'T619_T4.xsd'))->toBe([]);
});

it('writes the T4 slip with the CRA element names and its required boxes', function () {
    craSlipXmlPostRun(craSlipXmlEmployee());
    ['x' => $x, 'slips' => [$slip]] = craSlipXmlT4();

    expect($slip['box16a'])->toBeGreaterThan(0); // the fixture really has CPP2

    $s = '/Submission/Return/T4/T4Slip';
    $amount = fn (int $cents): string => number_format($cents / 100, 2, '.', '');

    expect($x->evaluate("string($s/sin)"))->toBe('046454286')
        ->and($x->evaluate("string($s/EMPE_NM/snm)"))->toBe('Highpay')
        ->and($x->evaluate("string($s/EMPE_NM/gvn_nm)"))->toBe('Robin')
        ->and($x->evaluate("string($s/empt_prov_cd)"))->toBe('ON')
        ->and($x->evaluate("string($s/rpt_tcd)"))->toBe('O')
        // Box 45 (employer-offered dental benefits) is required from 2023, 1–5.
        ->and($x->evaluate("string($s/empr_dntl_ben_rpt_cd)"))->toBe('1')
        ->and($x->evaluate("string($s/T4_AMT/empt_incamt)"))->toBe($amount($slip['box14']))
        ->and($x->evaluate("string($s/T4_AMT/cpp_cntrb_amt)"))->toBe($amount($slip['box16']))
        // Box 16A is cppe_cntrb_amt; cpp2_cntrb_amt does not exist.
        ->and($x->evaluate("string($s/T4_AMT/cppe_cntrb_amt)"))->toBe($amount($slip['box16a']))
        ->and($x->evaluate("string($s/T4_AMT/empe_eip_amt)"))->toBe($amount($slip['box18']))
        ->and($x->evaluate("string($s/T4_AMT/itx_ddct_amt)"))->toBe($amount($slip['box22']))
        // Box 24 is required and named ei_insu_ern_amt.
        ->and($x->evaluate("string($s/T4_AMT/ei_insu_ern_amt)"))->toBe($amount($slip['box24']))
        ->and($x->evaluate("string($s/T4_AMT/cpp_qpp_ern_amt)"))->toBe($amount($slip['box26']));

    // Names the CRA does not define, and QPP/QPIP on a rest-of-Canada slip (the
    // spec forbids CPP and QPP amounts on the same slip).
    foreach (['cpp2_cntrb_amt', 'qpp2_cntrb_amt', 'ei_insur_ern_amt', 'prov_insur_ern_amt', 'pens_adjt_amt', 'qpp_cntrb_amt', 'qppe_cntrb_amt', 'prov_pip_amt', 'prov_insu_ern_amt'] as $absent) {
        expect($x->query("$s/T4_AMT/$absent")->length)->toBe(0, $absent);
    }
});

it('writes the T4 Summary totals under the CRA names, with employer CPP and CPP2 apart', function () {
    craSlipXmlPostRun(craSlipXmlEmployee());
    ['x' => $x, 'summary' => $summary] = craSlipXmlT4();

    $t = '/Submission/Return/T4/T4Summary';
    $amount = fn (int $cents): string => number_format($cents / 100, 2, '.', '');

    expect($summary['box27a'])->toBeGreaterThan(0)
        ->and($summary['box27'] + $summary['box27a'])->toBe($summary['employer_cpp']);

    expect($x->evaluate("string($t/tx_yr)"))->toBe('2026')
        ->and($x->evaluate("string($t/slp_cnt)"))->toBe('1')
        ->and($x->evaluate("string($t/rpt_tcd)"))->toBe('O')
        ->and($x->evaluate("string($t/EMPR_NM/l1_nm)"))->toBe('Maple Leaf Bakery')
        ->and($x->evaluate("string($t/EMPR_ADDR/pstl_cd)"))->toBe('K1A0B1')
        ->and($x->evaluate("string($t/T4_TAMT/tot_empt_incamt)"))->toBe($amount($summary['box14']))
        ->and($x->evaluate("string($t/T4_TAMT/tot_empe_cpp_amt)"))->toBe($amount($summary['box16']))
        ->and($x->evaluate("string($t/T4_TAMT/tot_empe_cppe_amt)"))->toBe($amount($summary['box16a']))
        ->and($x->evaluate("string($t/T4_TAMT/tot_empe_eip_amt)"))->toBe($amount($summary['box18']))
        ->and($x->evaluate("string($t/T4_TAMT/tot_itx_ddct_amt)"))->toBe($amount($summary['box22']))
        // Employer CPP2 has its own field; tot_empr_cpp_amt is base + first additional only.
        ->and($x->evaluate("string($t/T4_TAMT/tot_empr_cpp_amt)"))->toBe($amount($summary['box27']))
        ->and($x->evaluate("string($t/T4_TAMT/tot_empr_cppe_amt)"))->toBe($amount($summary['box27a']))
        ->and($x->evaluate("string($t/T4_TAMT/tot_empr_eip_amt)"))->toBe($amount($summary['employer_ei']))
        // The summary contact is required.
        ->and($x->evaluate("string($t/CNTC/cntc_nm)"))->toBe('Pat Payroll')
        ->and($x->evaluate("string($t/CNTC/cntc_area_cd)"))->toBe('613')
        ->and($x->evaluate("string($t/CNTC/cntc_phn_nbr)"))->toBe('555-0101');

    foreach (['tot_cpp_cntrb_amt', 'tot_cpp2_cntrb_amt', 'tot_qpp_cntrb_amt', 'tot_prov_pip_amt'] as $absent) {
        expect($x->query("$t/T4_TAMT/$absent")->length)->toBe(0, $absent);
    }
});

it('takes box 45 from the employee payroll setup over the organization default', function () {
    craSlipXmlPostRun(craSlipXmlEmployee(['dental_benefits_code' => DentalBenefitsCode::PayeeSpouseAndChildren]));

    expect(craSlipXmlT4()['x']->evaluate('string(/Submission/Return/T4/T4Slip/empr_dntl_ben_rpt_cd)'))->toBe('3');
});

it('reports QPP and QPIP for a Quebec employee, never CPP', function () {
    craSlipXmlPostRun(craSlipXmlEmployee(['province_of_employment' => 'QC', 'annual_salary_cents' => 5200000, 'td1_provincial_claim_cents' => 1895200]));
    ['xml' => $xml, 'x' => $x, 'slips' => [$slip]] = craSlipXmlT4();

    $s = '/Submission/Return/T4/T4Slip';

    expect($slip['box17'])->toBeGreaterThan(0)
        ->and($x->evaluate("string($s/empt_prov_cd)"))->toBe('QC')
        ->and($x->evaluate("string($s/T4_AMT/qpp_cntrb_amt)"))->toBe(number_format($slip['box17'] / 100, 2, '.', ''))
        ->and($x->evaluate("string($s/T4_AMT/prov_pip_amt)"))->toBe(number_format($slip['box55'] / 100, 2, '.', ''))
        ->and($x->evaluate("string($s/T4_AMT/prov_insu_ern_amt)"))->toBe(number_format($slip['box56'] / 100, 2, '.', ''))
        ->and($x->evaluate("string($s/prov_pip_xmpt_cd)"))->toBe('0')
        ->and($x->query("$s/T4_AMT/cpp_cntrb_amt")->length)->toBe(0)
        ->and($x->query("$s/T4_AMT/cppe_cntrb_amt")->length)->toBe(0)
        ->and(craSlipXmlSchemaErrors($xml, 'T619_T4.xsd'))->toBe([]);
});

it('reports other-information amounts under their CRA element names', function () {
    $employee = craSlipXmlEmployee();
    $slips = [craSlipXmlSlip($employee, ['box24' => 4000000, 'box26' => 4500000, 'other' => [
        '20' => 120000, '44' => 30000, '46' => 5000, '52' => 250000, '40' => 45000, '34' => 100000,
    ]])];

    $xml = app(T4XmlGenerator::class)->generate($this->company, 2026, $slips, craSlipXmlSummary($slips));
    $x = craSlipXmlPath($xml);
    $s = '/Submission/Return/T4/T4Slip';

    expect($x->evaluate("string($s/T4_AMT/rpp_cntrb_amt)"))->toBe('1200.00')   // box 20
        ->and($x->evaluate("string($s/T4_AMT/unn_dues_amt)"))->toBe('300.00')   // box 44
        ->and($x->evaluate("string($s/T4_AMT/chrty_dons_amt)"))->toBe('50.00')  // box 46
        ->and($x->evaluate("string($s/T4_AMT/padj_amt)"))->toBe('2500.00')      // box 52
        ->and($x->evaluate("string($s/OTH_INFO/oth_tx_ben_amt)"))->toBe('450.00')  // code 40
        ->and($x->evaluate("string($s/OTH_INFO/prsnl_vhcl_amt)"))->toBe('1000.00') // code 34
        ->and($x->evaluate('string(/Submission/Return/T4/T4Summary/T4_TAMT/tot_rpp_cntrb_amt)'))->toBe('1200.00')
        ->and($x->evaluate('string(/Submission/Return/T4/T4Summary/T4_TAMT/tot_padj_amt)'))->toBe('2500.00')
        ->and(craSlipXmlSchemaErrors($xml, 'T619_T4.xsd'))->toBe([]);
});

it('writes other information in CRA code order, the Indian Act codes included', function () {
    $employee = craSlipXmlEmployee();

    // Indian Act exempt employment income is code 71 with box 14 at 0.00;
    // RPP contributions and union dues on it are codes 94 and 95.
    $slips = [craSlipXmlSlip($employee, ['box14' => 0, 'other' => ['95' => 6000, '71' => 200000, '40' => 1500, '94' => 9000]])];

    $xml = app(T4XmlGenerator::class)->generate($this->company, 2026, $slips, craSlipXmlSummary($slips));
    $x = craSlipXmlPath($xml);
    $s = '/Submission/Return/T4/T4Slip';
    $written = array_map(fn (DOMNode $node): string => $node->nodeName, iterator_to_array($x->query("$s/OTH_INFO/*")));

    expect($written)->toBe(['oth_tx_ben_amt', 'indn_empe_amt', 'indn_xmpt_rpp_amt', 'indn_xmpt_unn_amt'])
        ->and($x->evaluate("string($s/T4_AMT/empt_incamt)"))->toBe('0.00')
        ->and($x->evaluate("string($s/OTH_INFO/indn_empe_amt)"))->toBe('2000.00')
        ->and($x->evaluate("string($s/OTH_INFO/indn_xmpt_rpp_amt)"))->toBe('90.00')
        ->and($x->evaluate("string($s/OTH_INFO/indn_xmpt_unn_amt)"))->toBe('60.00')
        ->and(craSlipXmlSchemaErrors($xml, 'T619_T4.xsd'))->toBe([]);
});

it('moves other-information amounts past six onto an additional T4 slip', function () {
    $employee = craSlipXmlEmployee();
    $slips = [craSlipXmlSlip($employee, ['other' => [
        '30' => 1000, '31' => 2000, '32' => 3000, '33' => 4000, '34' => 5000, '36' => 6000, '40' => 7000, '42' => 8000,
    ]])];

    $xml = app(T4XmlGenerator::class)->generate($this->company, 2026, $slips, craSlipXmlSummary($slips));
    $x = craSlipXmlPath($xml);

    // T4 spec: at most six Other Information entries per slip; more need an
    // additional slip, which repeats the identification but not the boxes.
    expect($x->query('/Submission/Return/T4/T4Slip')->length)->toBe(2)
        ->and($x->query('/Submission/Return/T4/T4Slip[1]/OTH_INFO/*')->length)->toBe(6)
        ->and($x->query('/Submission/Return/T4/T4Slip[1]/T4_AMT')->length)->toBe(1)
        ->and($x->query('/Submission/Return/T4/T4Slip[2]/OTH_INFO/*')->length)->toBe(2)
        ->and($x->query('/Submission/Return/T4/T4Slip[2]/T4_AMT')->length)->toBe(0)
        ->and($x->evaluate('string(/Submission/Return/T4/T4Slip[2]/sin)'))->toBe('046454286')
        ->and($x->evaluate('string(/Submission/Return/T4/T4Summary/slp_cnt)'))->toBe('2')
        ->and(craSlipXmlSchemaErrors($xml, 'T619_T4.xsd'))->toBe([]);
});

it('leaves box 45 off slips for tax years before 2023', function () {
    $employee = craSlipXmlEmployee();
    $slips = [craSlipXmlSlip($employee)];

    $xml = app(T4XmlGenerator::class)->generate($this->company, 2022, $slips, craSlipXmlSummary($slips));

    // EFV error 207 rejects the dental benefits code on a return before 2023.
    expect(craSlipXmlPath($xml)->query('/Submission/Return/T4/T4Slip/empr_dntl_ben_rpt_cd')->length)->toBe(0)
        ->and(craSlipXmlSchemaErrors($xml, 'T619_T4.xsd'))->toBe([]);
});

// --- T4: refusing to file -----------------------------------------------------

it('is ready to file once the payroll account, contact and box 45 are set', function () {
    craSlipXmlPostRun(craSlipXmlEmployee());
    ['slips' => $slips] = craSlipXmlT4();

    $readiness = app(T4XmlGenerator::class)->readiness($this->company, 2026, $slips);

    expect($readiness->errors)->toBe([])
        ->and($readiness->warnings)->toBe([])
        ->and($readiness->isReady())->toBeTrue();
});

it('refuses to file the T4 without the payroll account, contact and box 45 code', function () {
    craSlipXmlPostRun(craSlipXmlEmployee());
    $this->company->update([
        'payroll_business_number' => null, 'payroll_rp_account' => null, 'payroll_contact_name' => null,
        'payroll_contact_email' => null, 'payroll_contact_phone' => null, 'payroll_dental_benefits_code' => null,
    ]);
    ['slips' => $slips, 'x' => $x] = craSlipXmlT4();

    $readiness = app(T4XmlGenerator::class)->readiness($this->company->fresh(), 2026, $slips);

    expect($readiness->isReady())->toBeFalse()
        ->and($readiness->errors)->toContain(__('Enter your Business Number and payroll (RP) program account in Payroll settings. The CRA files T4 and T4A slips under that account (for example 123456789RP0001), not under your GST/HST number.'))
        ->and($readiness->errors)->toContain(__('Enter the payroll contact name in Payroll settings. The CRA requires a contact on the transmittal and on the summary.'))
        ->and($readiness->errors)->toContain(__('Enter the payroll contact phone in Payroll settings as a 10-digit number, for example 613-555-0101.'))
        ->and($readiness->errors)->toContain(__('Enter the payroll contact email in Payroll settings. The CRA sends its messages about the file there.'))
        ->and($readiness->errors)->toContain(__('Choose the employer-offered dental benefits code (T4 box 45) in Payroll settings. The CRA requires it on every T4 slip for 2023 and later.'))
        // The GST/HST number is never filed in its place.
        ->and($x->evaluate('string(/Submission/Return/T4/T4Summary/bn)'))->not->toBe('123456789RT0001');
});

it('refuses to file a T4 when the payroll account in settings is malformed', function () {
    craSlipXmlPostRun(craSlipXmlEmployee());
    $this->company->update(['payroll_business_number' => '12345678', 'payroll_rp_account' => 'RT0001']);

    $readiness = app(T4XmlGenerator::class)->readiness($this->company->fresh(), 2026, craSlipXmlT4()['slips']);

    expect($readiness->errors)->toContain(__('The Business Number and payroll (RP) account in Payroll settings don’t form a valid payroll account: 9 digits, RP, then 4 digits, for example 123456789RP0001.'));
});

it('refuses to file a T4 whose stored SIN fails the CRA check digit, and never writes it', function () {
    craSlipXmlPostRun(craSlipXmlEmployee([], '123456789'));
    ['slips' => $slips, 'x' => $x] = craSlipXmlT4();

    expect(app(T4XmlGenerator::class)->readiness($this->company, 2026, $slips)->errors)
        ->toContain(__(':name: the SIN on file isn’t a valid social insurance number (9 digits that pass the CRA check digit). Correct it on the employee’s payroll setup, or enter 000 000 000 if they haven’t given you one.', ['name' => 'Robin Highpay']))
        ->and($x->evaluate('string(/Submission/Return/T4/T4Slip/sin)'))->toBe('000000000');
});

it('refuses to file a T4 whose stored SIN can no longer be decrypted', function () {
    $employee = craSlipXmlEmployee();
    craSlipXmlPostRun($employee);

    // Ciphertext written under another APP_KEY, as after a restore elsewhere.
    DB::table('employee_payroll_profiles')->where('contact_id', $employee->id)
        ->update(['sin_encrypted' => 'eyJpdiI6IjEyMyIsInZhbHVlIjoiYWJjIiwibWFjIjoiZGVmIn0=']);
    ['slips' => $slips, 'xml' => $xml] = craSlipXmlT4();

    expect(app(T4XmlGenerator::class)->readiness($this->company, 2026, $slips)->errors)
        ->toContain(__(':name: the SIN on file can’t be read. It was encrypted under a different APP_KEY, for example before a restore. Enter it again on the employee’s payroll setup.', ['name' => 'Robin Highpay']))
        // The file itself still builds, with the unknown-SIN placeholder.
        ->and($xml)->toContain('<sin>000000000</sin>');
});

it('files 000000000 with a warning when an employee has not given a SIN', function () {
    craSlipXmlPostRun(craSlipXmlEmployee([], null));
    ['slips' => $slips, 'x' => $x] = craSlipXmlT4();

    $readiness = app(T4XmlGenerator::class)->readiness($this->company, 2026, $slips);

    // T4 spec: "When the employee has failed to provide a SIN, the only
    // acceptable value to enter in the field is all zeroes."
    expect($readiness->errors)->toBe([])
        ->and($readiness->warnings)->toContain(__(':name has no SIN on file, so the slip is filed with 000000000. The CRA accepts that only when the employee hasn’t given you a SIN, and their CPP contributions aren’t credited to them until you file the correct one.', ['name' => 'Robin Highpay']))
        ->and($x->evaluate('string(/Submission/Return/T4/T4Slip/sin)'))->toBe('000000000');
});

it('refuses to file a T4 slip that would carry both CPP and QPP, or a negative amount', function () {
    $employee = craSlipXmlEmployee();
    $slips = [craSlipXmlSlip($employee, ['box16' => 10000, 'box17' => 20000, 'box22' => -500])];

    $errors = app(T4XmlGenerator::class)->readiness($this->company, 2026, $slips)->errors;

    expect($errors)->toContain(__(':name has both CPP and QPP contributions for :year. The CRA needs a separate T4 slip for each province of employment, which this file can’t produce yet.', ['name' => 'Robin Highpay', 'year' => 2026]))
        ->and($errors)->toContain(__(':name: :box is negative (:amount). The CRA doesn’t accept negative amounts on a slip.', ['name' => 'Robin Highpay', 'box' => __('box :box', ['box' => '22']), 'amount' => '-5.00']));
});

it('refuses to file an amount tagged with a T4 box the CRA does not define for the year', function () {
    $employee = craSlipXmlEmployee();
    $slips = [craSlipXmlSlip($employee, ['other' => ['99' => 1000, '40' => 2000]])];

    $readiness = app(T4XmlGenerator::class)->readiness($this->company, 2026, $slips);
    $x = craSlipXmlPath(app(T4XmlGenerator::class)->generate($this->company, 2026, $slips, craSlipXmlSummary($slips)));

    // Code 99 was only valid for 2000 and earlier (EFV error 303 rejects it).
    expect($readiness->errors)->toBe([__(':name has :amount tagged with T4 box :code, which can’t be reported on a :year T4 slip. Change the T4 box on that pay item.', ['name' => 'Robin Highpay', 'amount' => '10.00', 'code' => '99', 'year' => 2026])])
        ->and($x->evaluate('string(/Submission/Return/T4/T4Slip/OTH_INFO/oth_tx_ben_amt)'))->toBe('20.00');
});

it('refuses the T4 XML download until the organization is ready to file', function () {
    craSlipXmlOwner();
    craSlipXmlPostRun(craSlipXmlEmployee());
    $this->company->update(['payroll_rp_account' => null]);

    Livewire::test('pages::payroll.reports.t4', ['company' => $this->company->fresh(), 'year' => 2026])
        ->assertSee(__('Enter your Business Number and payroll (RP) program account in Payroll settings. The CRA files T4 and T4A slips under that account (for example 123456789RP0001), not under your GST/HST number.'))
        ->call('exportXml')
        ->assertNoFileDownloaded()
        ->assertDispatched('toast-show');
});

it('downloads the T4 XML once the organization is ready to file', function () {
    craSlipXmlOwner();
    craSlipXmlPostRun(craSlipXmlEmployee());

    Livewire::test('pages::payroll.reports.t4', ['company' => $this->company, 'year' => 2026])
        ->assertDontSee(__('Before you download the CRA XML'))
        ->call('exportXml')
        ->assertFileDownloaded('t4-2026.xml');
});

// --- T4A ------------------------------------------------------------------------

it('builds a T4A file that passes the CRA schema', function () {
    craSlipXmlCheque(Contact::create(['display_name' => 'Dana Freelance', 'first_name' => 'Dana', 'last_name' => 'Freelance', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '046 454 286']), 400000);
    craSlipXmlCheque(Contact::create(['display_name' => 'Acme Contracting', 'company_name' => 'Acme Contracting Ltd', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '111222333RT0001']), 150000);

    expect(craSlipXmlSchemaErrors(craSlipXmlT4a()['xml'], 'T619_T4A.xsd'))->toBe([]);
});

it('reports T4A box 048 and its summary total under the CRA element names', function () {
    craSlipXmlCheque(Contact::create(['display_name' => 'Dana Freelance', 'first_name' => 'Dana', 'last_name' => 'Freelance', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '046454286']), 400000);
    ['x' => $x] = craSlipXmlT4a();

    // T4A spec: box 048 is <fee_or_oth_srvc_amt>, its total <rpt_tot_fee_srvc_amt>;
    // the payer account is the RP BN15; the summary contact is required.
    expect($x->evaluate('string(/Submission/Return/T4A/T4ASlip/T4A_AMT/fee_or_oth_srvc_amt)'))->toBe('4000.00')
        ->and($x->evaluate('string(/Submission/Return/T4A/T4ASummary/T4A_TAMT/rpt_tot_fee_srvc_amt)'))->toBe('4000.00')
        ->and($x->evaluate('string(/Submission/Return/T4A/T4ASlip/bn)'))->toBe('123456789RP0001')
        ->and($x->evaluate('string(/Submission/Return/T4A/T4ASummary/bn)'))->toBe('123456789RP0001')
        ->and($x->evaluate('string(/Submission/Return/T4A/T4ASummary/CNTC/cntc_nm)'))->toBe('Pat Payroll')
        ->and($x->evaluate('string(/Submission/Return/T4A/T4ASummary/PAYR_NM/l1_nm)'))->toBe('Maple Leaf Bakery')
        ->and($x->evaluate('string(/Submission/T619/TransmitterAccountNumber/bn15)'))->toBe('123456789RP0001')
        ->and($x->query('//fees_srvc_amt')->length)->toBe(0)
        ->and($x->query('//tot_fees_srvc_amt')->length)->toBe(0);
});

it('files an individual contractor by name and SIN with the CRA placeholder business number', function () {
    craSlipXmlCheque(Contact::create(['display_name' => 'Dana Freelance', 'first_name' => 'Dana', 'last_name' => 'Freelance', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '046454286']), 400000);
    ['x' => $x] = craSlipXmlT4a();

    $s = '/Submission/Return/T4A/T4ASlip';

    // "Include either the Corporation Information or the Recipient
    // Information, NOT both"; rcpnt_bn is required, with 000000000RT0000 when
    // the recipient has no account number.
    expect($x->evaluate("string($s/RCPNT_NM/snm)"))->toBe('Freelance')
        ->and($x->evaluate("string($s/RCPNT_NM/gvn_nm)"))->toBe('Dana')
        ->and($x->evaluate("string($s/sin)"))->toBe('046454286')
        ->and($x->evaluate("string($s/rcpnt_bn)"))->toBe('000000000RT0000')
        ->and($x->query("$s/RCPNT_CORP_NM")->length)->toBe(0);
});

it('files a business contractor by corporation name and business number with SIN zeros', function () {
    craSlipXmlCheque(Contact::create(['display_name' => 'Acme Contracting', 'company_name' => 'Acme Contracting Ltd', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '111222333rt0001']), 150000);
    ['x' => $x] = craSlipXmlT4a();

    $s = '/Submission/Return/T4A/T4ASlip';

    expect($x->evaluate("string($s/RCPNT_CORP_NM/l1_nm)"))->toBe('Acme Contracting Ltd')
        ->and($x->evaluate("string($s/rcpnt_bn)"))->toBe('111222333RT0001')
        ->and($x->evaluate("string($s/sin)"))->toBe('000000000')
        ->and($x->query("$s/RCPNT_NM")->length)->toBe(0);
});

it('refuses to file a T4A whose recipient tax number is neither a valid SIN nor a business number', function () {
    craSlipXmlCheque(Contact::create(['display_name' => 'Typo Freelance', 'first_name' => 'Typo', 'last_name' => 'Freelance', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '123456789']), 400000);
    ['slips' => $slips, 'x' => $x] = craSlipXmlT4a();

    expect(app(T4AXmlGenerator::class)->readiness($this->company, 2026, $slips)->errors)
        ->toBe([__(':name: the tax number on file isn’t a valid SIN (9 digits that pass the CRA check digit) or a 15-character business number (for example 123456789RT0001). Correct it on the vendor.', ['name' => 'Typo Freelance'])])
        ->and($x->evaluate('string(/Submission/Return/T4A/T4ASlip/sin)'))->toBe('000000000');
});

it('files a T4A recipient without a SIN or business number with a warning', function () {
    craSlipXmlCheque(Contact::create(['display_name' => 'Tiny Gig Co', 'is_vendor' => true, 'track_t4a' => true]), 90000);
    ['slips' => $slips, 'xml' => $xml] = craSlipXmlT4a();

    $readiness = app(T4AXmlGenerator::class)->readiness($this->company, 2026, $slips);

    expect($readiness->errors)->toBe([])
        ->and($readiness->warnings)->toBe([__(':name has no SIN or business number on file, so the slip is filed with the CRA’s placeholder. Add it to the vendor so the CRA can match the slip.', ['name' => 'Tiny Gig Co'])])
        ->and(craSlipXmlSchemaErrors($xml, 'T619_T4A.xsd'))->toBe([]);
});

it('refuses the T4A XML download until the organization is ready to file', function () {
    craSlipXmlOwner();
    craSlipXmlCheque(Contact::create(['display_name' => 'Dana Freelance', 'first_name' => 'Dana', 'last_name' => 'Freelance', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '046454286']), 400000);
    $this->company->update(['payroll_contact_email' => null]);

    Livewire::test('pages::payroll.reports.t4a', ['company' => $this->company->fresh(), 'year' => 2026])
        ->assertSee(__('Enter the payroll contact email in Payroll settings. The CRA sends its messages about the file there.'))
        ->call('exportXml')
        ->assertNoFileDownloaded()
        ->assertDispatched('toast-show');
});

it('downloads the T4A XML once the organization is ready to file', function () {
    craSlipXmlOwner();
    craSlipXmlCheque(Contact::create(['display_name' => 'Dana Freelance', 'first_name' => 'Dana', 'last_name' => 'Freelance', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '046454286']), 400000);

    Livewire::test('pages::payroll.reports.t4a', ['company' => $this->company, 'year' => 2026])
        ->call('exportXml')
        ->assertFileDownloaded('t4a-2026.xml');
});
