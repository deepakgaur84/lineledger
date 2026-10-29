<?php

namespace App\Services\Reporting;

use App\Enums\PayFrequency;
use App\Enums\PayRunStatus;
use App\Enums\RoeReason;
use App\Models\Company;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Record of Employment (ROE) for an employee who leaves, from their
 * POSTED pay runs: the worksheet the ROE page shows and the data the ROE Web
 * payroll extract ({@see RoeXmlGenerator}) carries. Block numbers follow
 * Service Canada's "How to complete the record of employment (ROE) form".
 *
 *   6    Pay period type          from the employee's pay schedule
 *   12   Final pay period ending  the pay period holding the last day for which paid
 *   15A  Total insurable hours    the last 53 weekly, 27 biweekly, 25 semi-monthly
 *                                 or 13 monthly pay periods
 *   15B  Total insurable earnings the last 27, 14, 13 or 7 of them
 *   15C  Insurable earnings by pay period, final period first, over the same
 *                                 periods as 15A
 *
 * Insurable earnings are ALL of them, not only those under the EI maximum
 * insurable earnings (ROE guide, Block 15B step 3), so they come from
 * pay_run_lines.roe_insurable_earnings_cents, never the capped
 * ei_insurable_cents behind the premium and T4 box 24. A line calculated before
 * that column existed adds up its insurable earning rows instead.
 *
 * Block 15C lists consecutive pay periods: one with nothing paid is entered as
 * 0.00. The list runs back to the period holding the first day worked, but not
 * past the first pay period posted here, since earlier pay is unknown rather
 * than nil.
 */
class RoeCalculator
{
    private const POSTED = [PayRunStatus::Posted->value, PayRunStatus::Paid->value];

    /**
     * Most recent consecutive pay periods for Blocks 15A and 15C (electronic ROE).
     */
    private const PERIODS_15A_15C = [
        'weekly' => 53,
        'biweekly' => 27,
        'semi_monthly' => 25,
        'monthly' => 13,
    ];

    /**
     * Most recent consecutive pay periods totalled in Block 15B.
     */
    private const PERIODS_15B = [
        'weekly' => 27,
        'biweekly' => 14,
        'semi_monthly' => 13,
        'monthly' => 7,
    ];

    /**
     * @return array{
     *   employee: string, sin_last4: ?string, first_day: ?string, last_day: ?string,
     *   final_period_end: ?string, pay_period_type: string, reason: string, reason_label: string,
     *   total_insurable_hours: string, total_insurable_earnings_cents: int, block_15b_periods: int,
     *   periods: array<int, array{period_end: string, insurable_hours: string, insurable_earnings_cents: int}>
     * }
     */
    public function build(Company $company, Contact $employee, RoeReason $reason, string $lastDay): array
    {
        $employee->loadMissing('payrollProfile.payrollSchedule');
        $profile = $employee->payrollProfile;

        $scheduleFrequency = $profile?->payrollSchedule?->frequency;
        $frequency = $scheduleFrequency instanceof PayFrequency ? $scheduleFrequency : PayFrequency::Biweekly;

        $lines = $this->postedLines($company, $employee, (bool) $profile?->ei_exempt);
        $periods = $this->payPeriods($lines, $frequency, $profile?->hire_date, $lastDay);

        $totalHours = round(array_sum(array_map(fn (array $p): float => (float) $p['insurable_hours'], $periods)), 2);
        $block15b = array_slice($periods, 0, self::PERIODS_15B[$frequency->value]);

        return [
            'employee' => (string) $employee->display_name,
            'sin_last4' => $profile?->sin_last4,
            'first_day' => $profile?->hire_date?->toDateString(),
            'last_day' => $lastDay,
            'final_period_end' => $periods[0]['period_end'] ?? null,
            'pay_period_type' => $this->payPeriodType($frequency, $periods[0]['period_end'] ?? null),
            'reason' => $reason->value,
            'reason_label' => $reason->label(),
            'total_insurable_hours' => (string) $totalHours,
            'total_insurable_earnings_cents' => array_sum(array_column($block15b, 'insurable_earnings_cents')),
            'block_15b_periods' => count($block15b),
            'periods' => $periods,
        ];
    }

    /**
     * The employee's posted pay-run lines, newest pay period first, each with its
     * period end, insurable hours and all its insurable earnings.
     *
     * @return Collection<int, array{period_end: string, hours: float, earnings: int}>
     */
    private function postedLines(Company $company, Contact $employee, bool $eiExempt): Collection
    {
        $rows = DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.company_id', $company->id)
            ->where('prl.contact_id', $employee->id)
            ->whereIn('pr.status', self::POSTED)
            ->orderByDesc('pr.period_end_date')
            ->orderByDesc('pr.id')
            ->get(['prl.id', 'pr.period_end_date', 'prl.insurable_hours', 'prl.roe_insurable_earnings_cents']);

        // Lines calculated before roe_insurable_earnings_cents existed: the sum of
        // their insurable earning rows is the same uncapped base the EI calculator
        // received.
        $legacyIds = $rows->whereNull('roe_insurable_earnings_cents')->pluck('id')->all();
        $legacy = $legacyIds === [] ? collect() : DB::table('pay_run_line_earnings')
            ->whereIn('pay_run_line_id', $legacyIds)
            ->where('is_insurable', true)
            ->groupBy('pay_run_line_id')
            ->selectRaw('pay_run_line_id, COALESCE(SUM(amount_cents), 0) AS amount')
            ->pluck('amount', 'pay_run_line_id');

        return $rows->map(function (object $row) use ($legacy, $eiExempt): array {
            $earnings = $row->roe_insurable_earnings_cents !== null
                ? (int) $row->roe_insurable_earnings_cents
                : ($eiExempt ? 0 : max(0, (int) ($legacy[$row->id] ?? 0)));

            return [
                'period_end' => mb_substr((string) $row->period_end_date, 0, 10),
                'hours' => (float) $row->insurable_hours,
                'earnings' => $earnings,
            ];
        })->values();
    }

    /**
     * Consecutive pay periods for Blocks 15A and 15C, final period first.
     *
     * @param  Collection<int, array{period_end: string, hours: float, earnings: int}>  $lines
     * @return array<int, array{period_end: string, insurable_hours: string, insurable_earnings_cents: int}>
     */
    private function payPeriods(Collection $lines, PayFrequency $frequency, ?CarbonInterface $firstDay, string $lastDay): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        $earliest = CarbonImmutable::parse($lines->last()['period_end']);
        $end = CarbonImmutable::parse($lines->first()['period_end']);

        // Block 12 is the pay period that holds the last day for which paid. Pay
        // posted for a later period (a final vacation payout, say) goes in pay
        // period 1 with it.
        $last = $this->date($lastDay);

        if ($last !== null && $last->gte($frequency->periodStartFor($earliest))) {
            while ($frequency->periodStartFor($end)->gt($last)) {
                $end = $frequency->periodStartFor($end)->subDay();
            }
        }

        // Back to the period holding the first day worked, but never before the
        // first period posted here.
        $floor = $firstDay !== null && CarbonImmutable::parse($firstDay)->gt($earliest)
            ? CarbonImmutable::parse($firstDay)
            : $earliest;

        $periods = [];
        $max = self::PERIODS_15A_15C[$frequency->value];

        while (count($periods) < $max && $end->gte($floor)) {
            $start = $frequency->periodStartFor($end);
            $from = $start->toDateString();
            $to = $end->toDateString();
            $isFinal = $periods === [];

            $in = $lines->filter(fn (array $line): bool => ($line['period_end'] >= $from && $line['period_end'] <= $to)
                || ($isFinal && $line['period_end'] > $to));

            $periods[] = [
                'period_end' => $to,
                'insurable_hours' => (string) round($in->sum('hours'), 2),
                'insurable_earnings_cents' => (int) $in->sum('earnings'),
            ];

            $end = $start->subDay();
        }

        return $periods;
    }

    /**
     * Block 6: B biweekly, W weekly; semi-monthly is S when periods end on the
     * 15th and the last day of the month, E otherwise; monthly is M when they end
     * on the last day of the month, O otherwise.
     */
    private function payPeriodType(PayFrequency $frequency, ?string $finalPeriodEnd): string
    {
        $end = $finalPeriodEnd !== null ? CarbonImmutable::parse($finalPeriodEnd) : null;
        $monthEnd = $end === null || $end->isSameDay($end->endOfMonth());

        return match ($frequency) {
            PayFrequency::Weekly => 'W',
            PayFrequency::Biweekly => 'B',
            PayFrequency::SemiMonthly => $monthEnd || $end->day === 15 ? 'S' : 'E',
            PayFrequency::Monthly => $monthEnd ? 'M' : 'O',
        };
    }

    /**
     * The last day for which paid as a date, or null when the page has none yet.
     */
    private function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }
}
