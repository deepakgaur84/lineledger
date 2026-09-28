<?php

namespace App\Services\Assets;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use Carbon\CarbonImmutable;

/**
 * Pure monthly book-depreciation math for one asset, dispatched on its
 * {@see DepreciationMethod}. Month 1 is the calendar month containing the
 * in-service date (full-month convention, no day proration) for every method.
 * Whatever the method, the schedule always totals the depreciable base
 * (cost − salvage) exactly — rounding remainders are absorbed by a closing
 * month — and never goes below the salvage value.
 *
 *  - Straight-line: the base split evenly over the useful life.
 *  - Declining balance (written-down value): each 12-month block charges the
 *    annual rate on the book value at the START of that block, spread evenly
 *    across its months, so year-end book values match the standard annual
 *    reducing-balance calculation exactly. How it ends depends on whether a
 *    useful life was given:
 *      · With a useful life, the final year of that life is the tail: it takes
 *        whatever balance is left above salvage (an override of the normal
 *        charge), spread over the months of life that remain in it, so the asset
 *        ends exactly when its life does.
 *      · Without one, the asset's materiality limit ends it: as soon as the
 *        balance left after a year's normal charge would be at or below the
 *        limit, that year writes the whole balance off.
 *    The rate must be 1–100%. A hard 100-year ceiling closes out anything still
 *    remaining.
 *  - Immediate: the whole base in the in-service month (a single row).
 *
 * Knows nothing about locks, disposal, or what has already been generated —
 * that filtering belongs to {@see DepreciationGenerator}.
 */
final class DepreciationSchedule
{
    /** A declining-balance schedule is closed out after this many years at most. */
    private const MAX_YEARS = 100;

    /**
     * The full month-by-month schedule, or an empty list when the math is
     * ineligible (no in-service date, the method's parameters missing, or
     * net ≤ 0).
     *
     * @return list<array{period: CarbonImmutable, amount_cents: int, cumulative_cents: int}>
     */
    public static function for(Asset $asset): array
    {
        if (! $asset->hasDepreciationSchedule()) {
            return [];
        }

        $start = CarbonImmutable::parse($asset->in_service_date->toDateString())->startOfMonth();

        return match ($asset->depreciationMethod()) {
            DepreciationMethod::StraightLine => self::straightLine($asset, $start),
            DepreciationMethod::DecliningBalance => self::decliningBalance($asset, $start),
            DepreciationMethod::Immediate => self::immediate($asset, $start),
        };
    }

    /**
     * The depreciable base (cost − salvage) split with intdiv: months 1..n−1
     * take the integer base and the final month absorbs the rounding remainder.
     *
     * @return list<array{period: CarbonImmutable, amount_cents: int, cumulative_cents: int}>
     */
    private static function straightLine(Asset $asset, CarbonImmutable $start): array
    {
        $net = $asset->netCostCents();
        $life = (int) $asset->useful_life_months;
        $base = intdiv($net, $life);

        $rows = [];
        $cumulative = 0;

        for ($month = 1; $month <= $life; $month++) {
            $amount = $month === $life ? $net - $base * ($life - 1) : $base;
            $cumulative += $amount;

            $rows[] = [
                'period' => $start->addMonths($month - 1),
                'amount_cents' => $amount,
                'cumulative_cents' => $cumulative,
            ];
        }

        return $rows;
    }

    /**
     * Annual-step written-down value. Each block of 12 months (counted from the
     * in-service month) charges the annual rate × the book value at the start of
     * the block — rounded half-up, in integer arithmetic so it is exact and
     * identical on every platform — capped at what is left above salvage, and
     * spread evenly, the block's last month absorbing the remainder. The block
     * that ends the asset takes everything left; see the class docblock.
     *
     * The rate is held as thousandths of a percent (20% = 20000), so
     * book × rate ÷ 100 becomes book × milli ÷ 100 000.
     *
     * @return list<array{period: CarbonImmutable, amount_cents: int, cumulative_cents: int}>
     */
    private static function decliningBalance(Asset $asset, CarbonImmutable $start): array
    {
        $milli = (int) round(((float) $asset->depreciation_rate) * 1000);
        $salvage = (int) $asset->salvage_value_cents;
        $bookValue = (int) $asset->cost_cents;

        $life = (int) $asset->useful_life_months;
        $hasLife = $life >= 1;

        // The 12-month block in which the useful life ends (−1 when there is none).
        $tailYear = $hasLife ? intdiv($life + 11, 12) - 1 : -1;

        // Materiality only ends an asset that has no life to end it.
        $materiality = $hasLife ? 0 : $asset->materialityLimitCents();

        $rows = [];
        $cumulative = 0;
        $month = 0;

        for ($year = 0; $year < self::MAX_YEARS && $bookValue > $salvage; $year++) {
            $remaining = $bookValue - $salvage;
            $months = 12;

            $charge = intdiv($bookValue * $milli * 2 + 100_000, 200_000);

            if ($hasLife) {
                $closesOut = $year === $tailYear
                    || $charge >= $remaining
                    || $year === self::MAX_YEARS - 1;

                // The tail runs only to the end of the useful life, which need
                // not fall on a 12-month boundary.
                if ($year === $tailYear) {
                    $months = $life - 12 * $year;
                }
            } else {
                $closesOut = $charge >= $remaining
                    || $remaining - $charge <= $materiality
                    || $charge < 1
                    || $year === self::MAX_YEARS - 1;
            }

            if ($closesOut) {
                $charge = $remaining;
            }

            $base = intdiv($charge, $months);

            for ($i = 1; $i <= $months; $i++) {
                $amount = $i === $months ? $charge - $base * ($months - 1) : $base;
                $cumulative += $amount;

                $rows[] = [
                    'period' => $start->addMonths($month++),
                    'amount_cents' => $amount,
                    'cumulative_cents' => $cumulative,
                ];
            }

            $bookValue -= $charge;
        }

        return $rows;
    }

    /**
     * The whole depreciable base in the in-service month.
     *
     * @return list<array{period: CarbonImmutable, amount_cents: int, cumulative_cents: int}>
     */
    private static function immediate(Asset $asset, CarbonImmutable $start): array
    {
        $net = $asset->netCostCents();

        return [[
            'period' => $start,
            'amount_cents' => $net,
            'cumulative_cents' => $net,
        ]];
    }
}
