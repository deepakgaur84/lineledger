<?php

/*
 * RL-1 boxes, as Revenu Québec's "Guide to Filing the RL-1 Slip: Employment and
 * Other Income" (RL-1.G-V (2026-10), the edition for the 2026 slips) defines them:
 *
 *   5.3.1  Box A includes "the value of all taxable benefits granted to an employee".
 *   5.4    Box B.A is the base plus first additional QPP contribution withheld; box
 *          B.B is the second additional QPP contribution. B.B stays blank when no
 *          second additional contribution was withheld.
 *   5.5    Box C is "the total Employment Insurance premiums withheld", blank when
 *          none was withheld.
 *   3.3    Slips are filed "under the name and identification number shown on the
 *          Remittance of Source Deductions and Employer Contributions", the
 *          10-digit identification number with its RS file number (the RL-1
 *          summary, RLZ-1.S (2026-10), prints both), never the GST/HST number.
 *
 * Revenu Québec gives its RL-slip XML schema to registered partners only, so the
 * XML is checked against the boxes the guide documents, not against a schema.
 */

use App\Enums\CompanyRole;
use App\Enums\SlipType;
use App\Exceptions\Payroll\Rl1ExportBlockedException;
use App\Models\Company;
use App\Models\PayrollSchedule;
use App\Models\PayrollSlipFiling;
use App\Models\User;
use App\Services\Reporting\Rl1SlipCalculator;
use App\Services\Reporting\Rl1XmlGenerator;
use App\Services\Reporting\T4SlipCalculator;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create([
        'address_country' => 'CA',
        'address_region' => 'QC',
        'features_payroll' => true,
        // The GST/HST account: never the RL-1's employer identification.
        'tax_number' => '123456789RT0001',
        'payroll_rq_identification_number' => '1234567890',
        'payroll_rq_file_number' => 'RS0001',
    ]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('includes a taxable benefit in box A, as the T4 includes it in box 14', function () {
    // An employer-paid group term life premium: a taxable benefit federally and in
    // Quebec, pensionable, not insurable.
    $employee = payrollFilingQuebecEmployee($this->schedule, 5200000, [[
        'kind' => 'contribution', 'code' => 'group_life', 'name' => 'Group life (taxable)',
        'calc_type' => 'fixed', 'amount_cents' => 10000, 't4_box' => '40',
        'taxable_federal' => true, 'taxable_provincial' => true, 'cpp_qpp' => true,
        'ei_insurable_earnings' => false,
    ]]);
    payrollFilingPostRun($this->schedule, $employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $t4 = collect(app(T4SlipCalculator::class)->slipsForYear($this->company, 2026))->firstWhere('contact_id', $employee->id);
    $rl1 = collect(app(Rl1SlipCalculator::class)->slipsForYear($this->company, 2026))->firstWhere('contact_id', $employee->id);
    $summary = app(Rl1SlipCalculator::class)->summary($this->company, 2026);

    // $52,000 / 26 = $2,000 of salary plus the $100 benefit.
    expect($t4['box14'])->toBe(210000)
        ->and($rl1['boxA'])->toBe(210000)
        ->and($summary['boxA'])->toBe(210000);
});

it('reports the Employment Insurance premiums withheld in box C', function () {
    $employee = payrollFilingQuebecEmployee($this->schedule, 5200000);
    payrollFilingPostRun($this->schedule, $employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $t4 = collect(app(T4SlipCalculator::class)->slipsForYear($this->company, 2026))->firstWhere('contact_id', $employee->id);
    $rl1 = collect(app(Rl1SlipCalculator::class)->slipsForYear($this->company, 2026))->firstWhere('contact_id', $employee->id);

    expect($t4['box18'])->toBeGreaterThan(0)
        ->and($rl1['boxC'])->toBe($t4['box18'])
        ->and(app(Rl1SlipCalculator::class)->summary($this->company, 2026)['boxC'])->toBe($t4['box18']);
});

it('splits the QPP contribution into box B.A (base and first additional) and box B.B (second additional)', function () {
    // One $90,000 cheque crosses the 2026 QPP maximum pensionable earnings ($74,600),
    // so the second additional contribution (QPP2) is withheld.
    $employee = payrollFilingQuebecEmployee($this->schedule, 234000000);
    payrollFilingPostRun($this->schedule, $employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $t4 = collect(app(T4SlipCalculator::class)->slipsForYear($this->company, 2026))->firstWhere('contact_id', $employee->id);
    $rl1 = collect(app(Rl1SlipCalculator::class)->slipsForYear($this->company, 2026))->firstWhere('contact_id', $employee->id);
    $summary = app(Rl1SlipCalculator::class)->summary($this->company, 2026);

    expect($t4['box17a'])->toBeGreaterThan(0)
        ->and($rl1['boxBA'])->toBe($t4['box17'])
        ->and($rl1['boxBB'])->toBe($t4['box17a'])
        ->and($rl1)->not->toHaveKey('boxB')
        // RL-1 summary lines 21 to 24: the employer matches each part separately.
        ->and($summary['boxBA'])->toBe($t4['box17'])
        ->and($summary['boxBB'])->toBe($t4['box17a'])
        ->and($summary['employer_qpp'])->toBe($t4['box17'])
        ->and($summary['employer_qpp2'])->toBe($t4['box17a']);
});

it('files the RL-1 XML under the Revenu Québec identification and file numbers from Payroll settings', function () {
    $employee = payrollFilingQuebecEmployee($this->schedule, 234000000);
    payrollFilingPostRun($this->schedule, $employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $calculator = app(Rl1SlipCalculator::class);
    $slips = $calculator->slipsForYear($this->company, 2026);
    $summary = $calculator->summary($this->company, 2026);
    $xml = app(Rl1XmlGenerator::class)->generate($this->company, 2026, $slips, $summary);
    $x = payrollFilingXpath($xml);
    $money = fn (int $cents): string => number_format($cents / 100, 2, '.', '');
    $r = '/Transmission/RELEVES1/Releve1';
    $s = '/Transmission/RELEVES1/Sommaire1';

    expect($x->evaluate("string($r/NoIdentificationQc)"))->toBe('1234567890')
        ->and($x->evaluate("string($r/NoDossier)"))->toBe('RS0001')
        ->and($x->evaluate("string($s/NoIdentificationQc)"))->toBe('1234567890')
        ->and($x->evaluate("string($s/NoDossier)"))->toBe('RS0001')
        ->and($xml)->not->toContain('123456789RT0001')
        ->and($x->evaluate("string($r/Montants/CaseA)"))->toBe($money($slips[0]['boxA']))
        ->and($x->evaluate("string($r/Montants/CaseBA)"))->toBe($money($slips[0]['boxBA']))
        ->and($x->evaluate("string($r/Montants/CaseBB)"))->toBe($money($slips[0]['boxBB']))
        ->and($x->evaluate("string($r/Montants/CaseC)"))->toBe($money($slips[0]['boxC']))
        ->and($x->query("$r/Montants/CaseB")->length)->toBe(0)
        ->and($x->evaluate("string($s/MontantsTotaux/TotalCaseBA)"))->toBe($money($summary['boxBA']))
        ->and($x->evaluate("string($s/MontantsTotaux/TotalCaseBB)"))->toBe($money($summary['boxBB']))
        ->and($x->evaluate("string($s/MontantsTotaux/TotalCaseC)"))->toBe($money($summary['boxC']))
        ->and($x->evaluate("string($s/MontantsTotaux/CotisationEmployeurRRQ)"))->toBe($money($summary['employer_qpp']))
        ->and($x->evaluate("string($s/MontantsTotaux/CotisationEmployeurRRQSupplementaire)"))->toBe($money($summary['employer_qpp2']));
});

it('leaves box B.B blank on a slip with no second additional QPP contribution', function () {
    $employee = payrollFilingQuebecEmployee($this->schedule, 5200000);
    payrollFilingPostRun($this->schedule, $employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $calculator = app(Rl1SlipCalculator::class);
    $slips = $calculator->slipsForYear($this->company, 2026);
    $x = payrollFilingXpath(app(Rl1XmlGenerator::class)->generate($this->company, 2026, $slips, $calculator->summary($this->company, 2026)));

    expect($slips[0]['boxBB'])->toBe(0)
        ->and($slips[0]['boxBA'])->toBeGreaterThan(0)
        ->and($x->query('/Transmission/RELEVES1/Releve1/Montants/CaseBB')->length)->toBe(0)
        ->and($x->query('/Transmission/RELEVES1/Releve1/Montants/CaseBA')->length)->toBe(1);
});

it('refuses the RL-1 XML until the Revenu Québec identification and file numbers are set', function () {
    $employee = payrollFilingQuebecEmployee($this->schedule, 5200000);
    payrollFilingPostRun($this->schedule, $employee, '2026-03-01', '2026-03-14', '2026-03-20');
    $this->company->update(['payroll_rq_identification_number' => null, 'payroll_rq_file_number' => null]);

    $calculator = app(Rl1SlipCalculator::class);

    expect(fn () => app(Rl1XmlGenerator::class)->generate($this->company->fresh(), 2026, $calculator->slipsForYear($this->company, 2026), $calculator->summary($this->company, 2026)))
        ->toThrow(Rl1ExportBlockedException::class, 'Settings → Payroll');

    Livewire::test('pages::payroll.reports.rl1', ['company' => $this->company->fresh(), 'year' => 2026])
        ->call('exportXml')
        ->assertDispatched('toast-show')
        ->assertNoFileDownloaded();
});

it('prints boxes B.A, B.B and C on the RL-1 slip and the RL-1 summary', function () {
    $employee = payrollFilingQuebecEmployee($this->schedule, 234000000);
    payrollFilingPostRun($this->schedule, $employee, '2026-03-01', '2026-03-14', '2026-03-20');

    $calculator = app(Rl1SlipCalculator::class);
    $slips = $calculator->slipsForYear($this->company, 2026);
    $summary = $calculator->summary($this->company, 2026);
    $fmt = fn (int $cents): string => number_format($cents / 100, 2);

    $slipHtml = view('pdf.reports.rl1-slip', ['company' => $this->company, 'slip' => $slips[0], 'year' => 2026])->render();
    $summaryHtml = view('pdf.reports.rl1-summary', ['company' => $this->company, 'summary' => $summary, 'slips' => $slips, 'year' => 2026])->render();

    expect($slipHtml)->toContain('Case B.A')
        ->toContain('Case B.B')
        ->toContain('Case C')
        ->toContain($fmt($slips[0]['boxBA']))
        ->toContain($fmt($slips[0]['boxBB']))
        ->toContain($fmt($slips[0]['boxC']))
        // Official paper slips still need a Revenu Québec authorization number.
        ->toContain('NOT FOR FILING')
        ->and($summaryHtml)->toContain($fmt($summary['boxBA']))
        ->toContain($fmt($summary['boxBB']))
        ->toContain($fmt($summary['boxC']))
        ->toContain('1234567890')
        ->toContain('RS0001');
});

it('exports boxes B.A, B.B and C in the slips CSV', function () {
    $employee = payrollFilingQuebecEmployee($this->schedule, 234000000);
    payrollFilingPostRun($this->schedule, $employee, '2026-03-01', '2026-03-14', '2026-03-20');
    $slip = app(Rl1SlipCalculator::class)->slipsForYear($this->company, 2026)[0];

    $csv = Livewire::test('pages::payroll.reports.rl1', ['company' => $this->company, 'year' => 2026])
        ->call('exportCsv')
        ->assertFileDownloaded('rl1-slips-2026.csv')
        ->effects['download']['content'];

    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", base64_decode($csv)))));
    $header = array_map(fn (string $h): string => preg_replace('/^\xEF\xBB\xBF/', '', $h), $rows[0]);
    $row = array_combine($header, $rows[1]);

    expect($header)->toBe(['Employee', 'SIN', 'Box A', 'Box B.A', 'Box B.B', 'Box C', 'Box E', 'Box G', 'Box H', 'Box I'])
        ->and($row['Box B.A'])->toBe(number_format($slip['boxBA'] / 100, 2, '.', ''))
        ->and($row['Box B.B'])->toBe(number_format($slip['boxBB'] / 100, 2, '.', ''))
        ->and($row['Box C'])->toBe(number_format($slip['boxC'] / 100, 2, '.', ''));
});

it('still shows an RL-1 filing finalized before boxes B.A, B.B and C, and says to refinalize it', function () {
    $employee = payrollFilingQuebecEmployee($this->schedule, 5200000);
    $legacy = [
        'contact_id' => $employee->id, 'name' => 'Québec Employé', 'sin_last4' => '4286',
        'boxA' => 200000, 'boxB' => 12345, 'boxE' => 20000, 'boxG' => 200000, 'boxH' => 988, 'boxI' => 200000,
    ];
    $filing = PayrollSlipFiling::create([
        'company_id' => $this->company->id,
        'slip_type' => SlipType::Rl1->value,
        'year' => 2025,
        'finalized_at' => now(),
        'finalized_by_user_id' => $this->user->id,
        'summary' => [
            'slip_count' => 1, 'boxA' => 200000, 'boxB' => 12345, 'boxE' => 20000, 'boxG' => 200000, 'boxH' => 988, 'boxI' => 200000,
            'employer_qpp' => 12345, 'employer_qpip' => 1383, 'qhsf' => 3000,
            'wsdrf_applicable' => false, 'wsdrf_payroll_cents' => 200000, 'wsdrf_training_cents' => 0, 'wsdrf_levy_cents' => 0,
        ],
    ]);
    $filing->lines()->create(['company_id' => $this->company->id, 'contact_id' => $employee->id, 'data' => $legacy]);

    Livewire::test('pages::payroll.reports.rl1', ['company' => $this->company, 'year' => 2025])
        ->assertSee('123.45')
        ->assertSeeHtml('data-test="rl1-legacy-boxes-note"');

    // As issued: the slip keeps its single box B.
    $html = view('pdf.reports.rl1-slip', ['company' => $this->company, 'slip' => $legacy, 'year' => 2025])->render();

    expect($html)->toContain('Case B ')
        ->toContain('123.45')
        ->not->toContain('Case B.B');

    $summaryHtml = view('pdf.reports.rl1-summary', ['company' => $this->company, 'summary' => $filing->summary, 'slips' => [$legacy], 'year' => 2025])->render();

    expect($summaryHtml)->toContain('B RRQ')->not->toContain('B.A RRQ');

    // Its XML would file slips with no box C, so the export waits for a refinalized filing.
    Livewire::test('pages::payroll.reports.rl1', ['company' => $this->company, 'year' => 2025])
        ->call('exportXml')
        ->assertDispatched('toast-show')
        ->assertNoFileDownloaded();
});

it('saves the Revenu Québec identification and file numbers in Payroll settings', function () {
    Livewire::test('pages::settings.payroll', ['company' => $this->company])
        ->set('f_rq_identification_number', '123 456 7890')
        ->set('f_rq_file_number', '0002')
        ->call('save')
        ->assertHasNoErrors();

    $this->company->refresh();

    expect($this->company->payroll_rq_identification_number)->toBe('1234567890')
        ->and($this->company->payroll_rq_file_number)->toBe('RS0002');

    Livewire::test('pages::settings.payroll', ['company' => $this->company])
        ->assertSet('f_rq_identification_number', '1234567890')
        ->assertSet('f_rq_file_number', 'RS0002')
        ->set('f_rq_identification_number', '123456789RT0001')
        ->set('f_rq_file_number', 'TQ0001')
        ->call('save')
        ->assertHasErrors(['f_rq_identification_number', 'f_rq_file_number']);
});
