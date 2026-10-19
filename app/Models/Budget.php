<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A QuickBooks-style account-level budget: twelve monthly target amounts per
 * income/expense account for a single fiscal year, optionally scoped to one
 * reporting dimension (class or location). Budgets never touch the GL — they
 * are compared against posted activity in the budget-vs-actual reports.
 */
#[Fillable([
    'company_id', 'name', 'fiscal_year', 'class_id', 'location_id', 'notes',
])]
class Budget extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('line_order');
    }

    /**
     * @return BelongsTo<Classification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'class_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The first day of the Nth fiscal month (1-based). Month 1 is the first
     * month of the company's fiscal year, so a July fiscal-year start makes
     * month 1 = July of {@see $fiscal_year}.
     */
    public function monthStart(int $index): CarbonImmutable
    {
        $startMonth = (int) ($this->company->fiscal_year_start_month ?? 1);

        return CarbonImmutable::create($this->fiscal_year, $startMonth, 1)
            ->addMonths($index - 1);
    }

    /**
     * Sum of budgeted cents for every account, restricted to the fiscal months
     * whose first day falls within the given period. Returns an account_id =>
     * cents map for cheap lookup while building a report. Report presets align
     * to month boundaries, so whole-month inclusion matches the actual ranges.
     *
     * @return array<int, int>
     */
    public function budgetedCentsByAccount(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $months = [];

        for ($index = 1; $index <= 12; $index++) {
            $monthStart = $this->monthStart($index);

            if ($monthStart->gte($start) && $monthStart->lte($end)) {
                $months[] = $index;
            }
        }

        $totals = [];

        foreach ($this->lines as $line) {
            $sum = 0;

            foreach ($months as $index) {
                $sum += (int) $line->{"month_{$index}_cents"};
            }

            $totals[(int) $line->account_id] = $sum;
        }

        return $totals;
    }

    /**
     * @return array<string, string>
     */
    /** First day of the budget's first fiscal month. */
    public function periodStart(): CarbonImmutable
    {
        return $this->monthStart(1);
    }

    /** Last day of the budget's last (twelfth) fiscal month. */
    public function periodEnd(): CarbonImmutable
    {
        return $this->monthStart(12)->endOfMonth();
    }

    /**
     * The twelve months the budget covers, spelled out — e.g. "Apr 2027 – Mar 2028".
     * The stored fiscal year is the calendar year the fiscal year *begins* in, so for a
     * non-January fiscal year the bare number alone ("2027") can be misread.
     */
    public function periodLabel(): string
    {
        $start = $this->periodStart();
        $end = $this->periodEnd();

        return $start->year === $end->year
            ? $start->format('M').' – '.$end->format('M Y')
            : $start->format('M Y').' – '.$end->format('M Y');
    }

    /** Label for budget pickers: "Name (2026)", or "Name (Apr 2027 – Mar 2028)" when the year does not start in January. */
    public function pickerLabel(): string
    {
        return (int) ($this->company->fiscal_year_start_month ?? 1) === 1
            ? $this->name.' ('.$this->fiscal_year.')'
            : $this->name.' ('.$this->periodLabel().')';
    }

    /** Whether any of the budget's months starts inside the given range. */
    public function overlapsRange(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->periodStart()->lte($end) && $this->periodEnd()->gte($start);
    }

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
        ];
    }
}
