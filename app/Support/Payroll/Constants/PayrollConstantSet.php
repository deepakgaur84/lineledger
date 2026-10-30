<?php

namespace App\Support\Payroll\Constants;

/**
 * The resolved federal + provincial payroll constants for a single (pay date,
 * province), bundled so the calculators consume one immutable object. All money
 * accessors return integer cents; rate accessors return decimal strings for
 * bcmath.
 */
final readonly class PayrollConstantSet
{
    /**
     * @param  array<string, mixed>  $federal
     * @param  array<string, mixed>  $provincial
     */
    public function __construct(
        public string $payDate,
        public string $province,
        private array $federal,
        private array $provincial,
    ) {}

    // --- Quebec ------------------------------------------------------------

    /**
     * Whether this is Quebec, which uses QPP/QPIP and Revenu Québec tax. When
     * true, the CPP/EI accessors below return the Quebec (QPP / reduced-EI)
     * values from the provincial 'quebec' bag, so CppCalculator/EiCalculator are
     * reused unchanged.
     */
    public function isQuebec(): bool
    {
        return $this->province === 'QC';
    }

    /**
     * The Quebec-only constants bag, or null for the rest of Canada.
     *
     * @return array<string, mixed>|null
     */
    private function quebec(): ?array
    {
        return $this->provincial['quebec'] ?? null;
    }

    // --- CPP / QPP ---------------------------------------------------------

    public function cppRate(): string
    {
        return $this->quebec()['qpp']['rate'] ?? $this->federal['cpp']['rate'];
    }

    public function cppBaseRate(): string
    {
        return $this->quebec()['qpp']['base_rate'] ?? $this->federal['cpp']['base_rate'];
    }

    public function cppFirstAdditionalRate(): string
    {
        return $this->quebec()['qpp']['first_additional_rate'] ?? $this->federal['cpp']['first_additional_rate'];
    }

    public function cppBasicExemptionCents(): int
    {
        return $this->quebec()['qpp']['basic_exemption_cents'] ?? $this->federal['cpp']['basic_exemption_cents'];
    }

    public function ympeCents(): int
    {
        return $this->quebec()['qpp']['max_pensionable_cents'] ?? $this->federal['cpp']['max_pensionable_cents'];
    }

    public function cppMaxContributionCents(): int
    {
        return $this->quebec()['qpp']['max_contribution_cents'] ?? $this->federal['cpp']['max_contribution_cents'];
    }

    public function cpp2Rate(): string
    {
        return $this->quebec()['qpp2']['rate'] ?? $this->federal['cpp2']['rate'];
    }

    public function cpp2LowerCents(): int
    {
        return $this->quebec()['qpp2']['lower_cents'] ?? $this->federal['cpp2']['lower_cents'];
    }

    public function yampeCents(): int
    {
        return $this->quebec()['qpp2']['upper_cents'] ?? $this->federal['cpp2']['upper_cents'];
    }

    public function cpp2MaxContributionCents(): int
    {
        return $this->quebec()['qpp2']['max_contribution_cents'] ?? $this->federal['cpp2']['max_contribution_cents'];
    }

    // --- EI ----------------------------------------------------------------

    public function eiRate(): string
    {
        return $this->quebec()['ei']['rate'] ?? $this->federal['ei']['rate'];
    }

    public function eiMaxInsurableCents(): int
    {
        return $this->federal['ei']['max_insurable_cents'];
    }

    public function eiMaxPremiumCents(): int
    {
        return $this->quebec()['ei']['max_premium_cents'] ?? $this->federal['ei']['max_premium_cents'];
    }

    public function eiEmployerFactor(): string
    {
        return $this->federal['ei']['employer_factor'];
    }

    // --- QPIP + Quebec tax extras (null/zero for the rest of Canada) --------

    public function quebecAbatementRate(): string
    {
        return $this->quebec()['abatement_rate'] ?? '0';
    }

    public function quebecWorkerDeductionRate(): string
    {
        return $this->quebec()['worker_deduction_rate'] ?? '0';
    }

    public function quebecWorkerDeductionMaxCents(): int
    {
        return $this->quebec()['worker_deduction_max_cents'] ?? 0;
    }

    public function qpipEmployeeRate(): string
    {
        return $this->quebec()['qpip']['employee_rate'] ?? '0';
    }

    public function qpipEmployerRate(): string
    {
        return $this->quebec()['qpip']['employer_rate'] ?? '0';
    }

    public function qpipMaxInsurableCents(): int
    {
        return $this->quebec()['qpip']['max_insurable_cents'] ?? 0;
    }

    // --- Federal tax -------------------------------------------------------

    /**
     * @return array<int, array{0: int|null, 1: string}>
     */
    public function federalBrackets(): array
    {
        return $this->federal['tax']['brackets'];
    }

    public function federalLowestRate(): string
    {
        return $this->federal['tax']['lowest_rate'];
    }

    public function federalBpaMaxCents(): int
    {
        return $this->federal['tax']['bpa_max_cents'];
    }

    public function federalBpaMinCents(): int
    {
        return $this->federal['tax']['bpa_min_cents'];
    }

    public function federalBpaPhaseoutLowCents(): int
    {
        return $this->federal['tax']['bpa_phaseout_low_cents'];
    }

    public function federalBpaPhaseoutHighCents(): int
    {
        return $this->federal['tax']['bpa_phaseout_high_cents'];
    }

    public function canadaEmploymentMaxCents(): int
    {
        return $this->federal['tax']['canada_employment_max_cents'];
    }

    /**
     * Whether a federal TD1 total claim is the basic personal amount alone
     * (claim code 1): the BPA of this table or of any earlier loaded one.
     *
     * T4127 (122nd ed., Jan 2026), Chapter 2, Option 2: "Once the claim code has
     * been established, the same code can be used every year. For example, if an
     * employee was at claim code 1 in 2025, then that employee will remain at
     * claim code 1 in 2026." A form claiming last year's basic amount is
     * therefore a basic claim this year too, not a partial one.
     */
    public function isFederalBasicClaim(int $claimCents): bool
    {
        return in_array($claimCents, FederalConstants::basicPersonalAmountsOnOrBefore($this->payDate), true);
    }

    // --- Provincial tax ----------------------------------------------------

    /**
     * @return array<int, array{0: int|null, 1: string}>
     */
    public function provincialBrackets(): array
    {
        return $this->provincial['brackets'];
    }

    public function provincialLowestRate(): string
    {
        return $this->provincial['lowest_rate'];
    }

    public function provincialBpaCents(): int
    {
        return $this->provincial['bpa_cents'];
    }

    /**
     * Whether a provincial TD1 (or Quebec TP-1015.3-V line 10) claim is the
     * basic personal amount alone: the province's basic amount in this table or
     * in any earlier loaded one, including a prorated mid-year amount. The
     * same claim-code rule as {@see isFederalBasicClaim()}, plus:
     *  - T4127 (123rd ed., Jul 2026), p.4, Newfoundland and Labrador: "If an
     *    employee has submitted a TD1NL prior to July 1, 2026, with the lower
     *    basic personal amount, employers do not need a new TD1NL to change it
     *    to the prorated amount."
     *  - Revenu Québec TP-1015.TI-V (2026-01), p.7: "If the individual did not
     *    give you a copy of form TP-1015.3-V for the year, you must withhold
     *    income tax ... using the deduction code for the previous year", and
     *    p.11 note 2: a line 10 amount from another year is adjusted for the
     *    current year's indexation.
     */
    public function isProvincialBasicClaim(int $claimCents): bool
    {
        return in_array($claimCents, ProvincialConstants::basicPersonalAmountsOnOrBefore($this->province, $this->payDate), true);
    }

    /**
     * The provincial basic personal amount for a given annualized income. Most
     * provinces use a flat amount; provinces that income-test their BPA (e.g.
     * Yukon, which mirrors the federal phase-out) supply max/min/phase-out keys.
     */
    public function provincialBpaForIncome(int $aCents): int
    {
        $max = $this->provincial['bpa_max_cents'] ?? null;
        $min = $this->provincial['bpa_min_cents'] ?? null;
        $low = $this->provincial['bpa_phaseout_low_cents'] ?? null;
        $high = $this->provincial['bpa_phaseout_high_cents'] ?? null;

        if ($max === null || $min === null || $low === null || $high === null) {
            return $this->provincialBpaCents();
        }

        if ($aCents <= $low) {
            return $max;
        }

        if ($aCents >= $high) {
            return $min;
        }

        $fraction = bcdiv((string) ($aCents - $low), (string) ($high - $low), 10);
        $reduction = (int) bcadd(bcmul((string) ($max - $min), $fraction, 10), '0.5', 0);

        return $max - $reduction;
    }

    /**
     * @return array<int, array{0: int, 1: string}>|null
     */
    public function provincialSurtax(): ?array
    {
        return $this->provincial['surtax'] ?? null;
    }

    /**
     * @return array<int, array{0: int|null, 1: int, 2: string, 3: int}>|null
     */
    public function provincialHealthPremium(): ?array
    {
        return $this->provincial['health_premium'] ?? null;
    }

    /**
     * The provincial low-income tax reduction (T4127 factor S) parameters, or
     * null when the province/period defines none. Applies to BC and Ontario;
     * see IncomeTaxCalculator for how S is computed and subtracted.
     *
     * @return array{base_cents: int, threshold_cents: int, rate: string, ceiling_cents: int}|null
     */
    public function provincialTaxReduction(): ?array
    {
        return $this->provincial['tax_reduction'] ?? null;
    }
}
