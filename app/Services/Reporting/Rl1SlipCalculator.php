<?php

namespace App\Services\Reporting;

use App\Enums\PayRunStatus;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates a calendar year of POSTED Quebec pay-run lines into Relevé 1 (RL-1)
 * slips, one per Quebec employee, mapping the persisted effective amounts to the
 * Revenu Québec RL-1 boxes. Reads stored actuals only — never re-runs the engine.
 *
 * Box map (Revenu Québec, RL-1.G-V (2026-10), "How to complete the RL-1 slip"):
 *   A    Employment income          → gross plus the taxable benefits paid in kind
 *                                       (bases-only earnings), as T4 box 14 (5.3.1)
 *   B.A  QPP contribution           → qpp_employee: base + first additional (5.4)
 *   B.B  Additional QPP contribution → qpp2_employee: second additional (5.4)
 *   C    EI premium                 → ei_employee (5.5)
 *   E    Quebec income tax withheld → quebec_tax (5.7)
 *   G    QPP pensionable salary     → cpp_pensionable (the QPP base; reused column) (5.9)
 *   H    QPIP premium               → qpip_employee (5.10)
 *   I    QPIP eligible salary       → qpip_insurable (5.11)
 * Deductions are the effective (override-or-computed) amounts.
 *
 * The RL-1 Summary adds the employer QPP contribution in two parts, as its lines
 * 22 (base + first additional) and 24 (second additional), the employer QPIP
 * premium and health services fund contribution, and the WSDRF (1% workforce
 * skills training) reconciliation when the company is subject to it: 1% of
 * Quebec payroll less any recorded eligible training (not yet tracked, so 0),
 * payable to Revenu Québec.
 */
class Rl1SlipCalculator
{
    private const POSTED = [PayRunStatus::Posted->value, PayRunStatus::Paid->value];

    /** WSDRF rate: 1% of Quebec payroll, in basis points. */
    private const WSDRF_RATE_BP = 100;

    /**
     * @return array<int, array{
     *   contact_id: int, name: string, sin_last4: ?string,
     *   boxA: int, boxBA: int, boxBB: int, boxC: int, boxE: int, boxG: int, boxH: int, boxI: int
     * }>
     */
    public function slipsForYear(Company $company, int $year): array
    {
        $lineTotals = DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.company_id', $company->id)
            ->where('prl.province_of_employment', 'QC')
            ->whereIn('pr.status', self::POSTED)
            ->whereYear('pr.pay_date', $year)
            ->groupBy('prl.contact_id')
            ->selectRaw('
                prl.contact_id AS contact_id,
                COALESCE(SUM(prl.gross_cents), 0) AS box_a,
                COALESCE(SUM(COALESCE(prl.qpp_employee_override_cents, prl.qpp_employee_computed_cents)), 0) AS box_ba,
                COALESCE(SUM(COALESCE(prl.qpp2_employee_override_cents, prl.qpp2_employee_computed_cents)), 0) AS box_bb,
                COALESCE(SUM(COALESCE(prl.ei_employee_override_cents, prl.ei_employee_computed_cents)), 0) AS box_c,
                COALESCE(SUM(COALESCE(prl.quebec_tax_override_cents, prl.quebec_tax_computed_cents)), 0) AS box_e,
                COALESCE(SUM(prl.cpp_pensionable_cents), 0) AS box_g,
                COALESCE(SUM(COALESCE(prl.qpip_employee_override_cents, prl.qpip_employee_computed_cents)), 0) AS box_h,
                COALESCE(SUM(prl.qpip_insurable_cents), 0) AS box_i
            ')
            ->get()
            ->keyBy('contact_id');

        if ($lineTotals->isEmpty()) {
            return [];
        }

        $contactIds = $lineTotals->keys()->all();
        $benefits = $this->taxableBenefitsByContact($company, $year, $contactIds);

        $contacts = DB::table('contacts')
            ->leftJoin('employee_payroll_profiles as epp', 'epp.contact_id', '=', 'contacts.id')
            ->whereIn('contacts.id', $contactIds)
            ->get(['contacts.id', 'contacts.display_name', 'epp.sin_last4'])
            ->keyBy('id');

        $slips = [];

        foreach ($lineTotals as $contactId => $row) {
            $contact = $contacts->get($contactId);

            $slips[] = [
                'contact_id' => (int) $contactId,
                'name' => (string) ($contact->display_name ?? ''),
                'sin_last4' => $contact->sin_last4 ?? null,
                'boxA' => (int) $row->box_a + ($benefits[(int) $contactId] ?? 0),
                'boxBA' => (int) $row->box_ba,
                'boxBB' => (int) $row->box_bb,
                'boxC' => (int) $row->box_c,
                'boxE' => (int) $row->box_e,
                'boxG' => (int) $row->box_g,
                'boxH' => (int) $row->box_h,
                'boxI' => (int) $row->box_i,
            ];
        }

        usort($slips, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $slips;
    }

    /**
     * Year totals across all RL-1 slips, plus the employer QPP/QPIP/QHSF totals
     * and the WSDRF reconciliation, for the RL-1 Summary.
     *
     * @return array{
     *   slip_count: int, boxA: int, boxBA: int, boxBB: int, boxC: int, boxE: int, boxG: int, boxH: int, boxI: int,
     *   employer_qpp: int, employer_qpp2: int, employer_qpip: int, qhsf: int,
     *   wsdrf_applicable: bool, wsdrf_payroll_cents: int, wsdrf_training_cents: int, wsdrf_levy_cents: int
     * }
     */
    public function summary(Company $company, int $year): array
    {
        $slips = $this->slipsForYear($company, $year);

        $employer = DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.company_id', $company->id)
            ->where('prl.province_of_employment', 'QC')
            ->whereIn('pr.status', self::POSTED)
            ->whereYear('pr.pay_date', $year)
            ->selectRaw('
                COALESCE(SUM(COALESCE(prl.qpp_employer_override_cents, prl.qpp_employer_computed_cents)), 0) AS er_qpp,
                COALESCE(SUM(COALESCE(prl.qpp2_employer_override_cents, prl.qpp2_employer_computed_cents)), 0) AS er_qpp2,
                COALESCE(SUM(COALESCE(prl.qpip_employer_override_cents, prl.qpip_employer_computed_cents)), 0) AS er_qpip,
                COALESCE(SUM(prl.qhsf_employer_computed_cents), 0) AS qhsf
            ')
            ->first();

        $payroll = array_sum(array_column($slips, 'boxA'));
        $applicable = (bool) $company->wsdrf_applicable;
        // Eligible training spend is not yet tracked, so the reconciliation shows the full 1% due.
        $training = 0;
        $levy = $applicable ? max(0, (int) round($payroll * self::WSDRF_RATE_BP / 10000) - $training) : 0;

        return [
            'slip_count' => count($slips),
            'boxA' => $payroll,
            'boxBA' => array_sum(array_column($slips, 'boxBA')),
            'boxBB' => array_sum(array_column($slips, 'boxBB')),
            'boxC' => array_sum(array_column($slips, 'boxC')),
            'boxE' => array_sum(array_column($slips, 'boxE')),
            'boxG' => array_sum(array_column($slips, 'boxG')),
            'boxH' => array_sum(array_column($slips, 'boxH')),
            'boxI' => array_sum(array_column($slips, 'boxI')),
            'employer_qpp' => (int) ($employer->er_qpp ?? 0),
            'employer_qpp2' => (int) ($employer->er_qpp2 ?? 0),
            'employer_qpip' => (int) ($employer->er_qpip ?? 0),
            'qhsf' => (int) ($employer->qhsf ?? 0),
            'wsdrf_applicable' => $applicable,
            'wsdrf_payroll_cents' => $payroll,
            'wsdrf_training_cents' => $training,
            'wsdrf_levy_cents' => $levy,
        ];
    }

    /**
     * Taxable benefits granted in kind per Quebec employee: the bases-only
     * earnings that feed the source-deduction bases but are excluded from
     * gross_cents. They are employment income, so box A adds them back, as
     * {@see T4SlipCalculator} does for box 14. Boxes G and I need nothing added:
     * the pensionable and QPIP-insurable columns already follow each benefit's
     * own flags.
     *
     * @param  array<int, int>  $contactIds
     * @return array<int, int>
     */
    private function taxableBenefitsByContact(Company $company, int $year, array $contactIds): array
    {
        return DB::table('pay_run_line_earnings as e')
            ->join('pay_run_lines as prl', 'prl.id', '=', 'e.pay_run_line_id')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('e.company_id', $company->id)
            ->where('prl.province_of_employment', 'QC')
            ->whereIn('pr.status', self::POSTED)
            ->whereYear('pr.pay_date', $year)
            ->where('e.add_to_bases_only', true)
            ->where('e.is_taxable', true)
            ->whereIn('prl.contact_id', $contactIds)
            ->groupBy('prl.contact_id')
            ->selectRaw('prl.contact_id AS contact_id, COALESCE(SUM(e.amount_cents), 0) AS amount')
            ->pluck('amount', 'contact_id')
            ->map(fn ($a) => (int) $a)
            ->all();
    }
}
