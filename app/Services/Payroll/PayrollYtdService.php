<?php

namespace App\Services\Payroll;

use App\Enums\PayRunStatus;
use App\Exceptions\Payroll\AnnualMaximumExceededException;
use App\Exceptions\Payroll\StalePayRunCalculationException;
use App\Models\EmployeePayrollProfile;
use App\Models\PayRun;
use App\Models\PayRunLine;
use App\Services\Payroll\Data\YtdTotals;
use App\Services\Posting\PayRunPoster;
use App\Support\Money;
use App\Support\Payroll\Constants\PayrollConstantsRepository;
use App\Support\Payroll\RoundingPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Derives an employee's year-to-date totals for a pay run, keyed on the pay date
 * (the CRA tax year). The annual CPP, CPP2, EI, QPP, QPP2 and QPIP maximums are
 * enforced against these totals, so they must count every run that takes room
 * under a maximum, in one defined, deterministic order: pay date, then run id.
 *
 * For a run R, the year-to-date is the employee's mid-year opening balances plus
 * their lines on every OTHER run of the same calendar year that is:
 *  - posted or paid, whatever its pay date: posted pay can no longer change, so
 *    it always uses up annual room, even when R is a back-dated correction run;
 *  - calculated but not yet posted, when it comes before R in that order (an
 *    earlier pay date, or the same pay date and a lower id). Two runs on the
 *    same pay date (a regular run and a bonus run) therefore share the room
 *    instead of each taking all of it.
 * Draft runs (amounts not current) and void runs (reversed) never count.
 *
 * Counting unposted calculations means a calculation can go stale: an earlier
 * run is recalculated, edited back to draft, posted or voided after R was
 * calculated. Each line stores a fingerprint of the totals it was calculated
 * against, and {@see PayRunPoster} calls {@see assertPostable()}, which refuses
 * to post R while a calculated run it counted is still unposted (post that one
 * first, or save it back as a draft), refuses a stale run until it is
 * recalculated, and refuses any line (an adjusted amount included) that would
 * take posted deductions past an annual maximum. Runs therefore post in the
 * same order their year-to-date was worked out in.
 */
class PayrollYtdService
{
    public function __construct(private PayrollConstantsRepository $constants) {}

    /**
     * The totals a calculation for $payRunId must use. Without a run id, every
     * calculated run on or before the pay date counts (as if the new run sorts
     * last), which can only leave less room, never more.
     */
    public function priorYtd(int $contactId, CarbonInterface $payDate, ?EmployeePayrollProfile $profile = null, ?int $payRunId = null): YtdTotals
    {
        return $this->totals($contactId, $payDate, $profile, $payRunId, includeCalculated: true);
    }

    /**
     * What has actually been deducted this year: opening balances plus every
     * posted or paid run except $exceptPayRunId.
     */
    public function postedYtd(int $contactId, CarbonInterface $payDate, ?EmployeePayrollProfile $profile = null, ?int $exceptPayRunId = null): YtdTotals
    {
        return $this->totals($contactId, $payDate, $profile, $exceptPayRunId, includeCalculated: false);
    }

    /**
     * Refuse to post a run while a calculated run it counts is unposted, when it
     * was calculated against year-to-date totals that have since changed, or
     * when its amounts would take an employee's posted deductions past an
     * annual maximum.
     *
     * @throws StalePayRunCalculationException
     * @throws AnnualMaximumExceededException
     */
    public function assertPostable(PayRun $payRun): void
    {
        $payRun->loadMissing('lines.profile', 'lines.contact');
        $payDate = CarbonImmutable::parse($payRun->pay_date);

        foreach ($payRun->lines as $line) {
            $earlier = $this->earliestUnpostedRunCountedBy((int) $line->contact_id, $payDate, (int) $payRun->id);

            if ($earlier !== null) {
                throw StalePayRunCalculationException::earlierRunNotPosted(
                    (string) $earlier->run_no,
                    CarbonImmutable::parse($earlier->pay_date)->toDateString(),
                    (string) $line->contact?->display_name,
                );
            }

            $current = $this->priorYtd((int) $line->contact_id, $payDate, $line->profile, (int) $payRun->id);
            $stamped = $line->ytd_basis_hash;

            if (! is_string($stamped) || ! hash_equals($stamped, $current->fingerprint())) {
                throw StalePayRunCalculationException::forEmployee((string) $line->contact?->display_name);
            }
        }

        foreach ($payRun->lines as $line) {
            $this->assertWithinAnnualMaximums($line, $payDate);
        }
    }

    /**
     * Each employee deduction capped by an annual maximum (T4127 Chapters 6 and
     * 7; Revenu Québec TP-1015.G for QPP and QPIP) must leave the year's posted
     * total, opening balances included, at or under that maximum. A line with
     * nothing to deduct always passes, so an employee whose earlier pay already
     * went over (before this check existed) can still be paid.
     */
    private function assertWithinAnnualMaximums(PayRunLine $line, CarbonImmutable $payDate): void
    {
        $c = $this->constants->resolve((string) $line->province_of_employment, $payDate);
        $posted = $this->postedYtd((int) $line->contact_id, $payDate, $line->profile, (int) $line->pay_run_id);

        // [label, this line's effective amount, posted year-to-date, maximum].
        // For Quebec the constant set returns the QPP, QPP2 and Quebec EI
        // maximums from the same accessors.
        $limits = $c->isQuebec()
            ? [
                [__('QPP contribution'), $line->qppEmployeeCents(), $posted->qppEmployeeCents, $c->cppMaxContributionCents()],
                [__('QPP2 contribution'), $line->qpp2EmployeeCents(), $posted->qpp2EmployeeCents, $c->cpp2MaxContributionCents()],
                [__('EI premium'), $line->eiEmployeeCents(), $posted->eiEmployeeCents, $c->eiMaxPremiumCents()],
                [__('QPIP premium'), $line->qpipEmployeeCents(), $posted->qpipEmployeeCents, RoundingPolicy::centsTimesRate($c->qpipMaxInsurableCents(), $c->qpipEmployeeRate())],
            ]
            : [
                [__('CPP contribution'), $line->cppEmployeeCents(), $posted->cppEmployeeCents, $c->cppMaxContributionCents()],
                [__('CPP2 contribution'), $line->cpp2EmployeeCents(), $posted->cpp2EmployeeCents, $c->cpp2MaxContributionCents()],
                [__('EI premium'), $line->eiEmployeeCents(), $posted->eiEmployeeCents, $c->eiMaxPremiumCents()],
            ];

        foreach ($limits as [$label, $amount, $postedYtd, $maximum]) {
            if ($amount > 0 && $postedYtd + $amount > $maximum) {
                throw AnnualMaximumExceededException::forEmployee(
                    (string) $line->contact?->display_name,
                    $label,
                    Money::fromCents($maximum)->format(),
                    Money::fromCents(max(0, $maximum - $postedYtd))->format(),
                );
            }
        }
    }

    /**
     * The first calculated, unposted run that the year-to-date of run $payRunId
     * counts for this employee (same year, earlier pay date or same date and a
     * lower id), or null when there is none.
     */
    private function earliestUnpostedRunCountedBy(int $contactId, CarbonInterface $payDate, int $payRunId): ?PayRun
    {
        $date = $payDate->toDateString();

        return PayRun::query()
            ->where('status', PayRunStatus::Calculated->value)
            ->whereKeyNot($payRunId)
            ->whereYear('pay_date', $payDate->year)
            ->where(fn ($earlier) => $earlier
                ->whereDate('pay_date', '<', $date)
                ->orWhere(fn ($sameDay) => $sameDay->whereDate('pay_date', '=', $date)->where('id', '<', $payRunId)))
            ->whereHas('lines', fn ($lines) => $lines->where('contact_id', $contactId))
            ->orderBy('pay_date')
            ->orderBy('id')
            ->first();
    }

    private function totals(int $contactId, CarbonInterface $payDate, ?EmployeePayrollProfile $profile, ?int $payRunId, bool $includeCalculated): YtdTotals
    {
        $lines = PayRunLine::query()
            ->join('pay_runs', 'pay_runs.id', '=', 'pay_run_lines.pay_run_id')
            ->where('pay_run_lines.contact_id', $contactId);

        $this->countingRuns($lines, 'pay_runs', $payDate, $payRunId, $includeCalculated);

        $row = $lines
            ->selectRaw('
                COALESCE(SUM(pay_run_lines.cpp_pensionable_cents), 0) AS pensionable,
                COALESCE(SUM(pay_run_lines.ei_insurable_cents), 0) AS insurable,
                COALESCE(SUM(COALESCE(pay_run_lines.cpp_employee_override_cents, pay_run_lines.cpp_employee_computed_cents)), 0) AS cpp_ee,
                COALESCE(SUM(COALESCE(pay_run_lines.cpp2_employee_override_cents, pay_run_lines.cpp2_employee_computed_cents)), 0) AS cpp2_ee,
                COALESCE(SUM(COALESCE(pay_run_lines.ei_employee_override_cents, pay_run_lines.ei_employee_computed_cents)), 0) AS ei_ee,
                COALESCE(SUM(COALESCE(pay_run_lines.qpp_employee_override_cents, pay_run_lines.qpp_employee_computed_cents)), 0) AS qpp_ee,
                COALESCE(SUM(COALESCE(pay_run_lines.qpp2_employee_override_cents, pay_run_lines.qpp2_employee_computed_cents)), 0) AS qpp2_ee,
                COALESCE(SUM(COALESCE(pay_run_lines.qpip_employee_override_cents, pay_run_lines.qpip_employee_computed_cents)), 0) AS qpip_ee,
                COALESCE(SUM(pay_run_lines.qpip_insurable_cents), 0) AS qpip_insurable
            ')
            ->first();

        // Bonus-method income already paid this year (from the earning
        // snapshots of the same counted runs): positions a later bonus's
        // annual-tax delta in the correct bracket.
        $bonus = DB::table('pay_run_line_earnings as e')
            ->join('pay_run_lines as prl', 'prl.id', '=', 'e.pay_run_line_id')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.contact_id', $contactId)
            ->where('e.is_bonus_method', true);

        $this->countingRuns($bonus, 'pr', $payDate, $payRunId, $includeCalculated);

        $ytdBonus = (int) $bonus->sum('e.amount_cents');

        // Mid-year opening balances seeded at onboarding count toward the caps,
        // but only for the tax year they belong to — a prior-year opening must not
        // inflate this year's accumulators.
        $openPensionable = $openInsurable = $openCpp = $openCpp2 = $openEi = 0;
        $openQpp = $openQpp2 = $openQpip = $openQpipInsurable = 0;

        if ($profile !== null
            && $profile->opening_balances_as_of !== null
            && $profile->opening_balances_as_of->year === $payDate->year) {
            $openPensionable = (int) $profile->opening_pensionable_cents;
            $openInsurable = (int) $profile->opening_insurable_cents;
            $openCpp = (int) $profile->opening_cpp_employee_cents;
            $openCpp2 = (int) $profile->opening_cpp2_employee_cents;
            $openEi = (int) $profile->opening_ei_employee_cents;
            $openQpp = (int) $profile->opening_qpp_employee_cents;
            $openQpp2 = (int) $profile->opening_qpp2_employee_cents;
            $openQpip = (int) $profile->opening_qpip_employee_cents;
            $openQpipInsurable = (int) $profile->opening_qpip_insurable_cents;
        }

        return new YtdTotals(
            pensionableCents: (int) ($row->pensionable ?? 0) + $openPensionable,
            insurableCents: (int) ($row->insurable ?? 0) + $openInsurable,
            cppEmployeeCents: (int) ($row->cpp_ee ?? 0) + $openCpp,
            cpp2EmployeeCents: (int) ($row->cpp2_ee ?? 0) + $openCpp2,
            eiEmployeeCents: (int) ($row->ei_ee ?? 0) + $openEi,
            qppEmployeeCents: (int) ($row->qpp_ee ?? 0) + $openQpp,
            qpp2EmployeeCents: (int) ($row->qpp2_ee ?? 0) + $openQpp2,
            qpipEmployeeCents: (int) ($row->qpip_ee ?? 0) + $openQpip,
            qpipInsurableCents: (int) ($row->qpip_insurable ?? 0) + $openQpipInsurable,
            bonusTaxableCents: $ytdBonus,
        );
    }

    /**
     * Limit a query joined to pay_runs (as $runs) to the runs that count toward
     * the year-to-date of run $payRunId paid on $payDate (see the class note).
     *
     * @param  EloquentBuilder<PayRunLine>|QueryBuilder  $query
     */
    private function countingRuns(EloquentBuilder|QueryBuilder $query, string $runs, CarbonInterface $payDate, ?int $payRunId, bool $includeCalculated): void
    {
        $date = $payDate->toDateString();

        $query->whereYear("{$runs}.pay_date", $payDate->year);

        if ($payRunId !== null) {
            $query->where("{$runs}.id", '!=', $payRunId);
        }

        $query->where(function ($counted) use ($runs, $date, $payRunId, $includeCalculated): void {
            $counted->whereIn("{$runs}.status", [PayRunStatus::Posted->value, PayRunStatus::Paid->value]);

            if (! $includeCalculated) {
                return;
            }

            $counted->orWhere(function ($calculated) use ($runs, $date, $payRunId): void {
                $calculated->where("{$runs}.status", PayRunStatus::Calculated->value)
                    ->where(function ($earlier) use ($runs, $date, $payRunId): void {
                        $earlier->whereDate("{$runs}.pay_date", '<', $date)
                            ->orWhere(function ($sameDay) use ($runs, $date, $payRunId): void {
                                $sameDay->whereDate("{$runs}.pay_date", '=', $date);

                                if ($payRunId !== null) {
                                    $sameDay->where("{$runs}.id", '<', $payRunId);
                                }
                            });
                    });
            });
        });
    }
}
