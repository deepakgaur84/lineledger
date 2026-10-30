<?php

namespace App\Services\Payroll\Calculators;

use App\Services\Payroll\Data\IncomeTaxResult;
use App\Support\Payroll\Constants\PayrollConstantSet;
use App\Support\Payroll\RoundingPolicy;

/**
 * Federal + provincial income tax withheld for one pay period, following CRA's
 * T4127 Option 1 (general formula). Computes the annualized taxable income,
 * applies the bracketed rates less the personal / CPP-EI / Canada-employment
 * credits, layers on provincial surtax and health premium, then divides back to
 * the pay period.
 *
 * The bonus/retro "bonus method" is supported via $annualLumpCents: the engine
 * calls this calculator twice (with and without the lump) and withholds the
 * annual-tax DELTA from the bonus — see PayrollDeductionEngine.
 *
 * v1 notes: the provincial low-income tax reduction (factor S) is applied for
 * British Columbia (see taxReduction()); Ontario's factor S — which sits after
 * the surtax and uses a dependant amount (Y) not yet modelled — is still
 * omitted and slightly over-withholds (a safe direction).
 *
 * Personal credits (K1, K1P, and the Quebec line 10 credit) come from the TD1
 * total claim the way T4127 Chapter 2 reads it: a $0 claim is claim code 0 (no
 * credit), the basic amount follows the BPA formula, and any other amount,
 * including a partial claim below the basic amount, is used as entered. See
 * federalTotalClaim(), provincialTotalClaim() and quebecPersonalAmount().
 */
class IncomeTaxCalculator
{
    public function compute(
        PayrollConstantSet $c,
        int $taxableIncomePeriodCents,
        int $payPeriodsPerYear,
        int $deductionsPerPeriodCents,   // F: RRSP/union dues etc. that reduce income
        int $enhancedCppPerPeriodCents,  // deductible enhanced CPP (1% + CPP2)
        int $annualDeductionsCents,      // F1/F2/HD authorized annual deductions
        int $federalClaimCents,          // TD1 federal total claim amount
        int $provincialClaimCents,       // TD1 provincial total claim amount
        int $baseCppPerPeriodCents,      // base CPP/QPP (4.95% / 5.40%) for the credit
        int $eiPerPeriodCents,           // EI premium for the credit
        int $qpipEmployeePerPeriodCents = 0, // employee QPIP for the Quebec credit (0 for ROC)
        bool $incomeTaxExempt = false,       // status-Indian / treaty exempt employment
        int $annualLumpCents = 0,            // bonus-method lump added to A AFTER annualization (B + YTD bonuses)
    ): IncomeTaxResult {
        $p = $payPeriodsPerYear;
        $isQuebec = $c->isQuebec();

        // A — annualized taxable income (enhanced CPP/QPP and F are deductible).
        // A bonus-method lump is one-time income: it joins A without being
        // multiplied by the number of periods.
        $a = $p * ($taxableIncomePeriodCents - $deductionsPerPeriodCents - $enhancedCppPerPeriodCents) - $annualDeductionsCents + $annualLumpCents;
        $a = max(0, $a);

        // Credit bases shared by the federal and provincial/Quebec credits
        // (annualized, capped). QPIP is included only for Quebec (0 elsewhere).
        $annualBaseCpp = min($p * $baseCppPerPeriodCents, $this->baseCppAnnualMaxCents($c));
        $annualEi = min($p * $eiPerPeriodCents, $c->eiMaxPremiumCents());
        $annualQpip = min($p * $qpipEmployeePerPeriodCents, RoundingPolicy::centsTimesRate($c->qpipMaxInsurableCents(), $c->qpipEmployeeRate()));
        $cppEiCreditBase = $annualBaseCpp + $annualEi + $annualQpip;

        // --- Federal ---
        $t3 = $this->bracketTax($a, $c->federalBrackets());
        $tc = $this->federalTotalClaim($c, $federalClaimCents, $this->incomeTestedFederalBpa($c, $a));
        $k1 = RoundingPolicy::centsTimesRate($tc, $c->federalLowestRate());
        $k2 = RoundingPolicy::centsTimesRate($cppEiCreditBase, $c->federalLowestRate());
        $k4 = RoundingPolicy::centsTimesRate(min($a, $c->canadaEmploymentMaxCents()), $c->federalLowestRate());
        $federalAnnual = max(0, $t3 - $k1 - $k2 - $k4);

        // Quebec abatement: a Quebec resident's federal tax is reduced by 16.5%.
        if ($isQuebec) {
            $federalAnnual -= RoundingPolicy::centsTimesRate($federalAnnual, $c->quebecAbatementRate());
        }

        // --- Provincial / Quebec ---
        if ($isQuebec) {
            // Quebec "deduction for workers" reduces income before the bracket tax.
            $workerDeduction = min(
                RoundingPolicy::centsTimesRate($a, $c->quebecWorkerDeductionRate()),
                $c->quebecWorkerDeductionMaxCents(),
            );
            $aQc = max(0, $a - $workerDeduction);
            $t4Basic = $this->bracketTax($aQc, $c->provincialBrackets());
            // Quebec non-refundable credits at the lowest Quebec rate (14%): ONLY
            // the employee's personal amount (line 10 of TP-1015.3-V, see
            // quebecPersonalAmount()). Unlike the federal formula (K2), Quebec's
            // source-deduction formula does NOT credit QPP/QPIP/EI premiums — the
            // enhanced QPP (1% + QPP2) is already deducted from income above, and
            // base QPP, QPIP and EI do not appear in the credit at all (Revenu
            // Québec TP-1015.TI-V, "Québec income tax" worked example, pp.10–11).
            $quebecPersonalAmount = $this->quebecPersonalAmount($c, $provincialClaimCents);
            $kQc = RoundingPolicy::centsTimesRate($quebecPersonalAmount, $c->provincialLowestRate());
            $provincialAnnual = max(0, $t4Basic - $kQc);
        } else {
            $t4Basic = $this->bracketTax($a, $c->provincialBrackets());
            // K1P — provincial personal credit, from TCP (provincialTotalClaim()).
            $tcp = $this->provincialTotalClaim($c, $provincialClaimCents, $c->provincialBpaForIncome($a));
            $k1p = RoundingPolicy::centsTimesRate($tcp, $c->provincialLowestRate());
            $k2p = RoundingPolicy::centsTimesRate($cppEiCreditBase, $c->provincialLowestRate());
            // Factor S — provincial low-income tax reduction (BC). Subtracted with
            // the credits here, which is correct for provinces with no surtax.
            $s = $this->taxReduction($c, $a, $t4Basic);
            $provincialBasic = max(0, $t4Basic - $k1p - $k2p - $s);
            $surtax = $this->surtax($provincialBasic, $c->provincialSurtax());
            $healthPremium = $this->healthPremium($a, $c->provincialHealthPremium());
            $provincialAnnual = $provincialBasic + $surtax + $healthPremium;
        }

        // Income-tax-exempt employment (status-Indian / treaty): withhold no income
        // tax at all — federal, provincial, and Quebec. CPP/EI/QPP/QPIP are not
        // income tax and are unaffected (handled by their own calculators).
        if ($incomeTaxExempt) {
            $federalAnnual = 0;
            $provincialAnnual = 0;
        }

        // Per-period (the additional tax the employee requested is added after).
        $federalPeriod = $p > 0 ? RoundingPolicy::roundBcToCents(bcdiv((string) $federalAnnual, (string) $p, 8)) : 0;
        $provincialPeriod = $p > 0 ? RoundingPolicy::roundBcToCents(bcdiv((string) $provincialAnnual, (string) $p, 8)) : 0;
        $provincialPeriod = max(0, $provincialPeriod);

        return new IncomeTaxResult(
            federalTaxCents: max(0, $federalPeriod),
            provincialTaxCents: $isQuebec ? 0 : $provincialPeriod,
            annualizedIncomeCents: $a,
            federalAnnualTaxCents: $federalAnnual,
            provincialAnnualTaxCents: $isQuebec ? 0 : $provincialAnnual,
            quebecTaxCents: $isQuebec ? $provincialPeriod : 0,
            quebecAnnualTaxCents: $isQuebec ? $provincialAnnual : 0,
        );
    }

    /**
     * Tax for an annual income using a bracket table [[up_to_cents|null, rate]].
     * The CRA constant K is derived from the bracket edges (K_b = Σ edge_i ×
     * (rate_{i+1} − rate_i)) so it can never drift from the rates.
     *
     * @param  array<int, array{0: int|null, 1: string}>  $brackets
     */
    private function bracketTax(int $aCents, array $brackets): int
    {
        $k = '0';

        foreach ($brackets as $i => [$upTo, $rate]) {
            $isTop = $upTo === null;

            if ($isTop || $aCents <= $upTo) {
                $tax = bcsub(bcmul((string) $aCents, $rate, 8), $k, 8);

                return max(0, RoundingPolicy::roundBcToCents($tax));
            }

            // Accumulate K at this edge before moving to the next bracket.
            $nextRate = $brackets[$i + 1][1];
            $k = bcadd($k, bcmul((string) $upTo, bcsub($nextRate, $rate, 10), 10), 10);
        }

        return 0;
    }

    private function incomeTestedFederalBpa(PayrollConstantSet $c, int $aCents): int
    {
        $low = $c->federalBpaPhaseoutLowCents();
        $high = $c->federalBpaPhaseoutHighCents();
        $max = $c->federalBpaMaxCents();
        $min = $c->federalBpaMinCents();

        if ($aCents <= $low) {
            return $max;
        }

        if ($aCents >= $high) {
            return $min;
        }

        // Linear interpolation between max and min across the phase-out range.
        $fraction = bcdiv((string) ($aCents - $low), (string) ($high - $low), 10);
        $reduction = RoundingPolicy::roundBcToCents(bcmul((string) ($max - $min), $fraction, 10));

        return $max - $reduction;
    }

    /**
     * Factor TC, the federal personal amounts claimed, from the TD1 total claim
     * (line 13). T4127 122nd edition (Jan 2026):
     *  - Chapter 2: "To determine Total federal Claim (TC), use the total claim
     *    amount on Form TD1." A partial claim below the basic amount (a newcomer
     *    or emigrant prorating the BPA) is used as entered.
     *  - Chapter 2, "Claim Code 0 — This code represents no claim amount
     *    allowed", and Table 8.9: claim code 0 → TC 0.00, K1 0.00. A $0 total
     *    claim is claim code 0: the TD1 tells an employee with more than one
     *    employer at the same time to "enter '0' on line 13" (T4001, Form TD1).
     *  - The basic amount alone (claim code 1, this year's or an earlier
     *    year's, see PayrollConstantSet::isFederalBasicClaim()) is the BPAF
     *    formula, income-tested ("If the employee is only claiming the BPAF,
     *    use claim code 1"; "If no Form TD1 is on file ... use BPAF").
     *  - Above the basic amount, the basic part follows the BPAF formula and
     *    the rest (the employee's other credits) is added in full.
     */
    private function federalTotalClaim(PayrollConstantSet $c, int $claimCents, int $incomeTestedBpaCents): int
    {
        if ($claimCents <= 0) {
            return 0;
        }

        if ($c->isFederalBasicClaim($claimCents)) {
            return $incomeTestedBpaCents;
        }

        if ($claimCents > $c->federalBpaMaxCents()) {
            return $incomeTestedBpaCents + ($claimCents - $c->federalBpaMaxCents());
        }

        return $claimCents;
    }

    /**
     * Factor TCP, the provincial or territorial personal amounts claimed, from
     * the provincial TD1 total claim: the same rules as federalTotalClaim().
     * T4127 122nd edition, Chapter 2: "To determine provincial or territorial
     * total claim (TCP), use the total claim amount on the appropriate
     * provincial or territorial Form TD1"; Tables 8.10–8.21: claim code 0 →
     * TCP 0.00, K1P 0.00. The basic amount alone is the province's basic
     * personal amount (income-tested where the province defines a phase-out,
     * e.g. Yukon's BPAYT).
     */
    private function provincialTotalClaim(PayrollConstantSet $c, int $claimCents, int $basicForIncomeCents): int
    {
        if ($claimCents <= 0) {
            return 0;
        }

        if ($c->isProvincialBasicClaim($claimCents)) {
            return $basicForIncomeCents;
        }

        if ($claimCents > $c->provincialBpaCents()) {
            return $basicForIncomeCents + ($claimCents - $c->provincialBpaCents());
        }

        return $claimCents;
    }

    /**
     * The Quebec personal amount credited at 14%: line 10 of form TP-1015.3-V.
     *  - Revenu Québec TP-1015.TI-V (2026-01), p.7: "You must use deduction
     *    code 'O' to withhold income tax from the remuneration of an individual
     *    who entered '0' on line 10 of form TP-1015.3-V", so a $0 claim earns no
     *    credit (TP-1015.G-V (2026-01), s.3.4.1: an individual with more than
     *    one employer at the same time "can enter '0' on line 10").
     *  - TP-1015.TI-V p.11 (the formula method): the credit is the "Amount
     *    entered on line 10 of form TP-1015.3-V" × 14%, so any other amount,
     *    partial or above the basic amount, is used as entered.
     *  - The basic amount alone, from this year's form or an earlier one, is
     *    this year's basic amount: p.11 note 2 adjusts a line 10 amount from
     *    another year for indexation (see isProvincialBasicClaim()).
     */
    private function quebecPersonalAmount(PayrollConstantSet $c, int $claimCents): int
    {
        if ($claimCents <= 0) {
            return 0;
        }

        if ($c->isProvincialBasicClaim($claimCents)) {
            return $c->provincialBpaCents();
        }

        return $claimCents;
    }

    /**
     * @param  array<int, array{0: int, 1: string}>|null  $brackets  [threshold_tax_cents, marginal_rate]
     */
    private function surtax(int $basicTaxCents, ?array $brackets): int
    {
        if ($brackets === null) {
            return 0;
        }

        $total = 0;

        foreach ($brackets as [$threshold, $rate]) {
            if ($basicTaxCents > $threshold) {
                $total += RoundingPolicy::centsTimesRate($basicTaxCents - $threshold, $rate);
            }
        }

        return $total;
    }

    /**
     * Factor S — the provincial low-income tax reduction (T4127). The reduction
     * is the base amount, phased out linearly above a threshold and gone above a
     * ceiling, but never more than the basic provincial tax T4 it offsets.
     * Returns 0 when the province/period defines no reduction.
     *
     * NOTE: the caller subtracts this alongside K1P/K2P, which is correct for
     * British Columbia (no surtax). Ontario applies its reduction after the
     * surtax and uses a dependant amount (Y) not yet modelled — when Ontario is
     * given a 'tax_reduction', handle it in the surtax path, not here.
     */
    private function taxReduction(PayrollConstantSet $c, int $aCents, int $basicTaxCents): int
    {
        $r = $c->provincialTaxReduction();

        if ($r === null || $aCents > $r['ceiling_cents']) {
            return 0;
        }

        $amount = $r['base_cents'];

        if ($aCents > $r['threshold_cents']) {
            $amount -= RoundingPolicy::centsTimesRate($aCents - $r['threshold_cents'], $r['rate']);
        }

        return min($basicTaxCents, max(0, $amount));
    }

    /**
     * @param  array<int, array{0: int|null, 1: int, 2: string, 3: int}>|null  $bands  [income_upper, base, rate, cap]
     */
    private function healthPremium(int $aCents, ?array $bands): int
    {
        if ($bands === null) {
            return 0;
        }

        $lower = 0;

        foreach ($bands as [$upper, $base, $rate, $cap]) {
            if ($upper === null || $aCents <= $upper) {
                $premium = $base + RoundingPolicy::centsTimesRate($aCents - $lower, $rate);

                return min($premium, $cap);
            }

            $lower = $upper;
        }

        return 0;
    }

    private function baseCppAnnualMaxCents(PayrollConstantSet $c): int
    {
        return RoundingPolicy::centsTimesRate($c->ympeCents() - $c->cppBasicExemptionCents(), $c->cppBaseRate());
    }
}
