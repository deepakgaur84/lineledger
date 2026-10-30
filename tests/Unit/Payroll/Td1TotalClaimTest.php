<?php

/*
 * TD1 total claims below the basic personal amount (audit D6Payroll-10).
 *
 * T4127 122nd edition (Jan 2026), Chapter 2: "To determine Total federal Claim
 * (TC), use the total claim amount on Form TD1" and "Claim Code 0 — This code
 * represents no claim amount allowed"; Tables 8.9–8.21 give claim code 0 →
 * TC/TCP 0.00 and K1/K1P 0.00. An employee with a second job enters "0" on
 * line 13 of the TD1 (T4001, Form TD1), and a newcomer can claim a prorated
 * amount below the basic one. The calculator used to floor every claim below
 * the basic amount up to the basic amount, so both were under-withheld.
 *
 * Quebec: Revenu Québec TP-1015.TI-V (2026-01), p.7 — "You must use deduction
 * code 'O' ... for an individual who entered '0' on line 10 of form
 * TP-1015.3-V"; p.11 credits "Amount entered on line 10" × 14%.
 */

use App\Services\Payroll\Calculators\CppCalculator;
use App\Services\Payroll\Calculators\EiCalculator;
use App\Services\Payroll\Calculators\IncomeTaxCalculator;
use App\Services\Payroll\Calculators\QpipCalculator;
use App\Services\Payroll\Data\EarningsBreakdown;
use App\Services\Payroll\Data\EmployeePayrollContext;
use App\Services\Payroll\Data\PayrollDeductionResult;
use App\Services\Payroll\Data\YtdTotals;
use App\Services\Payroll\PayrollDeductionEngine;
use App\Support\Payroll\Constants\PayrollConstantsRepository;
use Carbon\CarbonImmutable;

function td1claim_compute(int $federalClaimCents, int $provincialClaimCents, string $province = 'ON', string $payDate = '2026-03-13', int $grossCents = 150000): PayrollDeductionResult
{
    $engine = new PayrollDeductionEngine(
        new PayrollConstantsRepository,
        new CppCalculator,
        new EiCalculator,
        new IncomeTaxCalculator,
        new QpipCalculator,
    );

    return $engine->compute(
        new EmployeePayrollContext(
            province: $province,
            payPeriodsPerYear: 26,
            payDate: CarbonImmutable::parse($payDate),
            federalClaimCents: $federalClaimCents,
            provincialClaimCents: $provincialClaimCents,
        ),
        new EarningsBreakdown($grossCents, $grossCents, $grossCents, $grossCents),
        YtdTotals::none(),
    );
}

/**
 * T4127 122nd edition, Option 1, for an Ontario employee paid $1,500.00
 * biweekly (P = 26) with no F, F2, U1, HD or F1, worked independently of the
 * engine with T4127's own rounding rules (Chapter 1): the per-period CPP basic
 * exemption drops its third decimal, CPP/EI/tax round half up to the cent,
 * and rate ratios are never rounded. Returns the per-pay federal (T1 / P) and
 * Ontario (T2 / P) tax in cents.
 *
 * @return array{federal: int, ontario: int}
 */
function td1claim_t4127OntarioBiweekly1500(string $tc, string $tcp): array
{
    bcscale(12);
    $p = '26';
    $i = '1500.00';

    // Chapter 6: basic exemption 3,500 / 26 = 134.615… → 134.61 (third digit
    // dropped); C = 0.0595 × (1,500.00 − 134.61) = 81.240705 → 81.24.
    $exemption = bcdiv('3500', $p, 2);
    $c = td1claim_round(bcmul('0.0595', bcsub($i, $exemption)));
    // Chapter 7: EI = 0.0163 × 1,500.00 = 24.45.
    $ei = td1claim_round(bcmul('0.0163', $i));

    // Step 1: F5A = C × (0.0100 / 0.0595) = 13.653781… (no bonus, so F5A = F5);
    // A = P × (I − F5A) = 26 × 1,486.346218… = 38,645.001680…
    $f5a = bcdiv(bcmul($c, '0.0100'), '0.0595');
    $a = bcmul($p, bcsub($i, $f5a));

    // CPP/EI credit bases: P × C × (0.0495 / 0.0595) = 1,757.241680… (under the
    // $3,519.45 maximum) and P × EI = 635.70 (under $1,123.07).
    $baseCpp = bcdiv(bcmul(bcmul($p, $c), '0.0495'), '0.0595');
    $annualEi = bcmul($p, $ei);

    // Step 2: T3 = (R × A) − K − K1 − K2 − K3 − K4 with R = 0.14, K = 0 (A is
    // under $58,523). K1 = 0.14 × TC. K2 = 0.14 × 1,757.241680… + 0.14 × 635.70
    // = 335.011835… K4 = the lesser of 0.14 × $39,000 gross and 0.14 × CEA
    // $1,501 = 210.14. Step 3: T1 = T3 (no LCF).
    $k1 = bcmul('0.14', $tc);
    $k2 = bcadd(bcmul('0.14', $baseCpp), bcmul('0.14', $annualEi));
    $k4 = bcmul('0.14', '1501');
    $t1 = td1claim_floorZero(bcsub(bcsub(bcsub(bcmul('0.14', $a), $k1), $k2), $k4));

    // Step 4: T4 = (V × A) − KP − K1P − K2P with V = 0.0505, KP = 0 (A is under
    // $53,891). K1P = 0.0505 × TCP; K2P = 0.0505 × 1,757.241680… + 0.0505 ×
    // 635.70 = 120.843554…
    $k1p = bcmul('0.0505', $tcp);
    $k2p = bcadd(bcmul('0.0505', $baseCpp), bcmul('0.0505', $annualEi));
    $t4 = td1claim_floorZero(bcsub(bcsub(bcmul('0.0505', $a), $k1p), $k2p));

    // Step 5: T2 = T4 + V1 + V2 − S. V1 = 0 (T4 is under $5,818). V2 (Ontario
    // Health Premium, $36,000 < A ≤ $48,000) = the lesser of $450 and $300 +
    // 0.06 × (A − $36,000) = $458.70, so $450. S = the lesser of T4 + V1 and
    // 2 × ($300 + Y) − (T4 + V1), here negative, so S = 0.
    $v1 = '0';
    $v2 = bccomp(bcadd('300', bcmul('0.06', bcsub($a, '36000'))), '450') > 0
        ? '450'
        : bcadd('300', bcmul('0.06', bcsub($a, '36000')));
    $s = td1claim_floorZero(bcsub(bcmul('2', '300'), bcadd($t4, $v1)));
    $s = bccomp($s, bcadd($t4, $v1)) > 0 ? bcadd($t4, $v1) : $s;
    $t2 = td1claim_floorZero(bcsub(bcadd(bcadd($t4, $v1), $v2), $s));

    return [
        'federal' => (int) bcmul(td1claim_round(bcdiv($t1, $p)), '100', 0),
        'ontario' => (int) bcmul(td1claim_round(bcdiv($t2, $p)), '100', 0),
    ];
}

/** Round a positive dollar figure half up to the cent (T4127 Chapter 1). */
function td1claim_round(string $dollars): string
{
    return bcdiv(bcadd(bcmul($dollars, '100'), '0.5', 0), '100', 2);
}

function td1claim_floorZero(string $value): string
{
    return bccomp($value, '0') < 0 ? '0' : $value;
}

it('works the T4127 example for a $0 claim, a basic claim and an $8,000 partial claim', function () {
    // Claim code 0 (TC = TCP = 0): T3 = 5,410.300235 − 2,303.28·0 − 335.011835
    // − 210.14 = 4,865.148400 → 187.12 a pay; T4 = 1,830.729030, T2 = 2,280.729030
    // → 87.72 a pay.
    expect(td1claim_t4127OntarioBiweekly1500('0', '0'))->toBe(['federal' => 18712, 'ontario' => 8772]);

    // Claim code 1 (TC = 16,452, TCP = 12,989): K1 = 2,303.28 → T3 =
    // 2,561.868400 → 98.53; K1P = 655.9445 → T2 = 1,624.784530 → 62.49.
    expect(td1claim_t4127OntarioBiweekly1500('16452', '12989'))->toBe(['federal' => 9853, 'ontario' => 6249]);

    // Partial federal claim of $8,000 (TC = 8,000): K1 = 1,120.00 → T3 =
    // 3,745.148400 → 144.04.
    expect(td1claim_t4127OntarioBiweekly1500('8000', '12989'))->toBe(['federal' => 14404, 'ontario' => 6249]);
});

it('matches the worked T4127 example to the cent in the engine', function () {
    $zero = td1claim_compute(0, 0);
    $basic = td1claim_compute(1645200, 1298900);
    $partial = td1claim_compute(800000, 1298900);

    expect([$zero->federalTaxCents, $zero->provincialTaxCents])->toBe([18712, 8772])
        ->and([$basic->federalTaxCents, $basic->provincialTaxCents])->toBe([9853, 6249])
        ->and([$partial->federalTaxCents, $partial->provincialTaxCents])->toBe([14404, 6249]);
});

it('withholds federal tax with no personal credit (K1 = 0) for a $0 TD1 claim', function () {
    $zero = td1claim_compute(0, 1298900);
    $basic = td1claim_compute(1645200, 1298900);

    // K1 for the basic claim is 0.14 × $16,452 = $2,303.28 a year, $88.59 a pay.
    expect($zero->federalTaxCents - $basic->federalTaxCents)->toBe(8859)
        ->and($zero->provincialTaxCents)->toBe($basic->provincialTaxCents);
});

it('withholds Ontario tax with no personal credit (K1P = 0) for a $0 TD1ON claim', function () {
    $zero = td1claim_compute(1645200, 0);
    $basic = td1claim_compute(1645200, 1298900);

    // K1P for the basic claim is 0.0505 × $12,989 = $655.94 a year, $25.23 a pay.
    expect($zero->provincialTaxCents - $basic->provincialTaxCents)->toBe(2523)
        ->and($zero->federalTaxCents)->toBe($basic->federalTaxCents);
});

it('uses a partial provincial claim below the basic amount as entered', function () {
    $partial = td1claim_compute(1645200, 600000);
    $basic = td1claim_compute(1645200, 1298900);

    // TCP = $6,000: K1P drops by 0.0505 × ($12,989 − $6,000) = $352.94 a year.
    expect($partial->provincialTaxCents - $basic->provincialTaxCents)->toBe(1358);
});

it('treats a basic claim from an earlier year as this year\'s basic amount (claim code 1 carries over)', function () {
    // 2025 TD1 forms claiming the 2025 basic amounts ($16,129 federal, $12,747
    // Ontario) used for a 2026 pay: T4127 Option 2 keeps the employee at claim
    // code 1, so TC and TCP are the 2026 amounts, not partial claims.
    $lastYear = td1claim_compute(1612900, 1274700);
    $thisYear = td1claim_compute(1645200, 1298900);

    expect($lastYear->federalTaxCents)->toBe($thisYear->federalTaxCents)
        ->and($lastYear->provincialTaxCents)->toBe($thisYear->provincialTaxCents);
});

it('moves a TD1NL filed with the January 2026 basic amount to the prorated July amount', function () {
    // T4127 123rd edition, p.4: a TD1NL filed before July 1, 2026 with the lower
    // basic amount ($11,188) needs no new form to get the prorated $15,000.
    $january = td1claim_compute(1645200, 1118800, 'NL', '2026-07-15');
    $prorated = td1claim_compute(1645200, 1500000, 'NL', '2026-07-15');

    expect($january->provincialTaxCents)->toBe($prorated->provincialTaxCents);
});

it('still adds a claim above the basic amount on top of the basic amount', function () {
    $basic = td1claim_compute(1645200, 1298900);
    $extra = td1claim_compute(1645200 + 500000, 1298900 + 500000);

    // An extra $5,000 of credits: 0.14 × $5,000 = $700 and 0.0505 × $5,000 =
    // $252.50 a year less tax.
    expect($basic->federalTaxCents - $extra->federalTaxCents)->toBe(2692)
        ->and($basic->provincialTaxCents - $extra->provincialTaxCents)->toBe(971);
});

it('keeps income-testing a basic claim for a high earner', function () {
    // $12,000 a pay annualizes far above $258,482, where the BPAF formula gives
    // the minimum $14,829. The basic claim follows the formula; a partial claim
    // of exactly $14,829 is used as entered, so both credit the same amount.
    $basic = td1claim_compute(1645200, 1298900, 'ON', '2026-03-13', 1200000);
    $minimum = td1claim_compute(1482900, 1298900, 'ON', '2026-03-13', 1200000);

    expect($basic->federalTaxCents)->toBe($minimum->federalTaxCents);
});

it('gives no Quebec personal credit for a TP-1015.3-V line 10 of $0 (deduction code O)', function () {
    $zero = td1claim_compute(1645200, 0, 'QC', '2026-03-13', 250000);
    $basic = td1claim_compute(1645200, 1895200, 'QC', '2026-03-13', 250000);

    // The basic credit is 0.14 × $18,952 = $2,653.28 a year: $102.05 a pay.
    expect($zero->quebecTaxCents - $basic->quebecTaxCents)->toBeGreaterThanOrEqual(10204)
        ->toBeLessThanOrEqual(10205)
        ->and($zero->federalTaxCents)->toBe($basic->federalTaxCents);
});

it('uses a partial Quebec line 10 amount as entered', function () {
    $partial = td1claim_compute(1645200, 1000000, 'QC', '2026-03-13', 250000);
    $basic = td1claim_compute(1645200, 1895200, 'QC', '2026-03-13', 250000);

    // 0.14 × ($18,952 − $10,000) = $1,253.28 a year: $48.20 a pay.
    expect($partial->quebecTaxCents - $basic->quebecTaxCents)->toBeGreaterThanOrEqual(4820)
        ->toBeLessThanOrEqual(4821);
});

it('treats last year\'s Quebec basic amount as this year\'s (indexed line 10)', function () {
    $lastYear = td1claim_compute(1645200, 1857100, 'QC', '2026-03-13', 250000);
    $thisYear = td1claim_compute(1645200, 1895200, 'QC', '2026-03-13', 250000);

    expect($lastYear->quebecTaxCents)->toBe($thisYear->quebecTaxCents);
});

it('gives no federal credit to a Quebec employee with a $0 federal TD1 claim', function () {
    $zero = td1claim_compute(0, 1895200, 'QC', '2026-03-13', 250000);
    $basic = td1claim_compute(1645200, 1895200, 'QC', '2026-03-13', 250000);

    // K1 = 0.14 × $16,452 = $2,303.28 of basic federal tax, abated by 16.5%:
    // $1,923.24 a year, $73.97 a pay.
    expect($zero->federalTaxCents - $basic->federalTaxCents)->toBeGreaterThanOrEqual(7396)
        ->toBeLessThanOrEqual(7397);
});
