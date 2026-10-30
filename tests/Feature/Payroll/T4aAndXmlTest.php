<?php

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
use App\Models\Expense;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\T4ASlipCalculator;
use App\Services\Reporting\T4AXmlGenerator;
use App\Services\Reporting\T4SlipCalculator;
use App\Services\Reporting\T4XmlGenerator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    // Filed under the payroll (RP) account and contact from Payroll settings.
    $this->company = Company::factory()->create([
        'address_country' => 'CA', 'features_payroll' => true, 'tax_number' => '123456789RT0001',
        'payroll_business_number' => '123456789', 'payroll_rp_account' => 'RP0001',
        'payroll_contact_name' => 'Avery Admin', 'payroll_contact_email' => 'avery@example.com', 'payroll_contact_phone' => '604-555-0100',
        'payroll_dental_benefits_code' => DentalBenefitsCode::NotEligible,
    ]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postedChequeTo(Contact $vendor, int $cents, string $date): Cheque
{
    return Cheque::create([
        'bank_account_id' => test()->bank->id,
        'cheque_no' => (string) fake()->unique()->numberBetween(1000, 99999),
        'cheque_date' => $date,
        'payee_contact_id' => $vendor->id,
        'payee_name' => $vendor->display_name,
        'amount_cents' => $cents,
        'status' => ChequeStatus::Posted,
    ]);
}

// --- T4A --------------------------------------------------------------------

it('sums fees paid to T4A-tracked contractors into Box 048 with a $500 threshold', function () {
    $big = Contact::create(['display_name' => 'Big Contractor', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '111222333']);
    $small = Contact::create(['display_name' => 'Small Contractor', 'is_vendor' => true, 'track_t4a' => true]);
    $untracked = Contact::create(['display_name' => 'Untracked Co', 'is_vendor' => true, 'track_t4a' => false]);

    postedChequeTo($big, 250000, '2025-04-10');   // $2,500
    postedChequeTo($big, 150000, '2025-08-02');   // +$1,500 → $4,000
    postedChequeTo($small, 30000, '2025-05-01');  // $300 (below threshold)
    postedChequeTo($untracked, 900000, '2025-06-01');

    $rows = app(T4ASlipCalculator::class)->rows($this->company, CarbonImmutable::create(2025, 1, 1), CarbonImmutable::create(2025, 12, 31)->endOfDay());

    expect($rows)->toHaveCount(2); // tracked only

    $bigRow = collect($rows)->firstWhere('contact_id', $big->id);
    $smallRow = collect($rows)->firstWhere('contact_id', $small->id);

    expect($bigRow['box048_cents'])->toBe(400000)
        ->and($bigRow['meets_threshold'])->toBeTrue()
        ->and($smallRow['box048_cents'])->toBe(30000)
        ->and($smallRow['meets_threshold'])->toBeFalse();
});

it('generates valid CRA T4A XML with slips and a summary (Box 048)', function () {
    $business = Contact::create(['display_name' => 'Acme Contracting', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '111222333RP0001']);
    $individual = Contact::create(['display_name' => 'Dana Freelance', 'first_name' => 'Dana', 'last_name' => 'Freelance', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '046454286']);
    $belowThreshold = Contact::create(['display_name' => 'Tiny Gig', 'is_vendor' => true, 'track_t4a' => true]);

    postedChequeTo($business, 400000, '2025-04-10');   // $4,000
    postedChequeTo($individual, 120000, '2025-09-01');  // $1,200
    postedChequeTo($belowThreshold, 20000, '2025-05-01'); // $200 — excluded from XML

    $slips = app(T4ASlipCalculator::class)->slipsForYear($this->company, 2025);
    $summary = app(T4ASlipCalculator::class)->summary($this->company, 2025);
    $xml = app(T4AXmlGenerator::class)->generate($this->company, 2025, $slips, $summary);

    $doc = new DOMDocument;
    expect($doc->loadXML($xml))->toBeTrue();

    expect($summary['slip_count'])->toBe(2)            // only the two above $500
        ->and($summary['box048'])->toBe(520000)
        ->and($xml)->toContain('<T619>')
        ->and($xml)->toContain('<T4A>')
        ->and($xml)->toContain('<T4ASlip>')
        ->and($xml)->toContain('<T4ASummary>')
        // Business contractor: corporation name, its business number, no SIN.
        ->and($xml)->toContain('<l1_nm>Acme Contracting</l1_nm>')
        ->and($xml)->toContain('<rcpnt_bn>111222333RP0001</rcpnt_bn>')
        // Individual: surname/given + 9-digit SIN.
        ->and($xml)->toContain('<snm>Freelance</snm>')
        ->and($xml)->toContain('<gvn_nm>Dana</gvn_nm>')
        ->and($xml)->toContain('<sin>046454286</sin>')
        // Box 048 fees for services + summary total.
        ->and($xml)->toContain('<fee_or_oth_srvc_amt>4000.00</fee_or_oth_srvc_amt>')
        ->and($xml)->toContain('<rpt_tot_fee_srvc_amt>5200.00</rpt_tot_fee_srvc_amt>')
        ->and($xml)->toContain('<tx_yr>2025</tx_yr>')
        ->and($xml)->toContain('<slp_cnt>2</slp_cnt>')
        // The $200 contractor is below the threshold and must not appear.
        ->and($xml)->not->toContain('Tiny Gig');
});

it('downloads the T4A XML from the report page', function () {
    Contact::create(['display_name' => 'Acme Contracting', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '111222333RT0001']);
    postedChequeTo(Contact::query()->where('display_name', 'Acme Contracting')->firstOrFail(), 400000, '2025-04-10');

    Livewire\Livewire::test('pages::payroll.reports.t4a', ['company' => $this->company, 'year' => 2025])
        ->call('exportXml')
        ->assertFileDownloaded('t4a-2025.xml');
});

it('renders the T4A report page gated to payroll companies', function () {
    $this->get(route('payroll.reports.t4a', ['company' => $this->company, 'year' => 2025]))->assertOk()->assertSee('T4A');

    $this->company->update(['features_payroll' => false]);
    $this->get(route('payroll.reports.t4a', ['company' => $this->company]))->assertNotFound();
});

it('only offers the T4A vendor toggle for Canadian companies', function () {
    Livewire\Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->assertSeeHtml('vendor-track-t4a');
});

// --- T4 CRA XML -------------------------------------------------------------

/** One posted March 2025 pay run for Xavier Filer, an Alberta employee. */
function t4aAndXmlTestPostT4Run(): void
{
    $schedule = PayrollSchedule::factory()->create();
    $employee = Contact::create(['display_name' => 'Xavier Filer', 'first_name' => 'Xavier', 'last_name' => 'Filer', 'is_employee' => true]);
    $profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
    ]);
    $profile->setSin('046454286');
    $profile->save();

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $schedule->id,
        'period_start_date' => '2025-03-01',
        'period_end_date' => '2025-03-14',
        'pay_date' => '2025-03-20',
        'bank_account_id' => test()->bank->id,
        'lines' => [['contact_id' => $employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());
}

it('generates valid CRA T4 XML with slips and a summary', function () {
    t4aAndXmlTestPostT4Run();

    $slips = app(T4SlipCalculator::class)->slipsForYear($this->company, 2025);
    $summary = app(T4SlipCalculator::class)->summary($this->company, 2025);
    $xml = app(T4XmlGenerator::class)->generate($this->company, 2025, $slips, $summary);

    // Well-formed XML.
    $doc = new DOMDocument;
    expect($doc->loadXML($xml))->toBeTrue();

    // Structure + values.
    expect($xml)->toContain('<T619>')
        ->and($xml)->toContain('<T4Slip>')
        ->and($xml)->toContain('<T4Summary>')
        ->and($xml)->toContain('<snm>Filer</snm>')
        ->and($xml)->toContain('<gvn_nm>Xavier</gvn_nm>')
        ->and($xml)->toContain('<sin>046454286</sin>')
        ->and($xml)->toContain('<empt_incamt>'.number_format($slips[0]['box14'] / 100, 2, '.', '').'</empt_incamt>')
        ->and($xml)->toContain('<tx_yr>2025</tx_yr>')
        ->and($xml)->toContain('<slp_cnt>1</slp_cnt>');
});

it('downloads the T4 XML from the report page', function () {
    t4aAndXmlTestPostT4Run();

    $response = Livewire\Livewire::test('pages::payroll.reports.t4', ['company' => $this->company, 'year' => 2025])
        ->call('exportXml');

    $response->assertFileDownloaded('t4-2025.xml');
});

it('counts pay-now expenses to a T4A-tracked contractor in Box 048', function () {
    $contractor = Contact::create(['display_name' => 'Card Paid Contractor', 'is_vendor' => true, 'track_t4a' => true, 'tax_number' => '111222333']);

    postedChequeTo($contractor, 30000, '2025-04-10');

    $expense = Expense::create([
        'payment_account_id' => $this->bank->id,
        'expense_date' => '2025-05-02',
        'payee_contact_id' => $contractor->id,
        'payee_name' => $contractor->display_name,
        'amount_cents' => 45000,
        'status' => 'posted',
        'posted_at' => now(),
    ]);
    $expense->lines()->create(['account_id' => Account::query()->where('code', '6010')->value('id'), 'amount_cents' => 45000, 'line_order' => 0]);

    $rows = app(T4ASlipCalculator::class)->rows($this->company, CarbonImmutable::create(2025, 1, 1), CarbonImmutable::create(2025, 12, 31)->endOfDay());
    $row = collect($rows)->firstWhere('contact_id', $contractor->id);

    expect($row['box048_cents'])->toBe(75000)
        ->and($row['meets_threshold'])->toBeTrue();
});
