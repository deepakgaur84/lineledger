<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\AssetStatus;
use App\Enums\DepreciationMethod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property ?CarbonInterface $in_service_date
 * @property ?CarbonInterface $disposed_at
 */
#[Fillable([
    'company_id', 'asset_no', 'name', 'description', 'asset_category_id',
    'asset_account_id', 'accumulated_depreciation_account_id', 'depreciation_expense_account_id',
    'serial_number', 'location',
    'acquired_date', 'in_service_date', 'cost_cents', 'salvage_value_cents',
    'useful_life_months', 'depreciation_method', 'depreciation_rate', 'materiality_limit_cents', 'auto_depreciate', 'status', 'disposed_at', 'disposal_notes',
    'source_type', 'source_id', 'notes', 'is_active',
])]
class Asset extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /** The materiality limit a declining-balance asset defaults to, as a percentage of its cost. */
    public const DEFAULT_MATERIALITY_PERCENT = 5;

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id')->withoutGlobalScopes();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /**
     * @return HasMany<AssetDepreciationEntry, $this>
     */
    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(AssetDepreciationEntry::class)->orderBy('period');
    }

    public function scopeInService(Builder $query): Builder
    {
        return $query->where('status', AssetStatus::InService->value);
    }

    public function scopeDisposed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AssetStatus::Disposed->value,
            AssetStatus::Sold->value,
            AssetStatus::Lost->value,
        ]);
    }

    public function netCostCents(): int
    {
        return (int) $this->cost_cents - (int) $this->salvage_value_cents;
    }

    /**
     * The asset's depreciation method. Falls back to straight-line when the
     * attribute is absent — a freshly built (unsaved) model has no database
     * default applied yet, and this is the behaviour every asset had before
     * methods existed.
     */
    public function depreciationMethod(): DepreciationMethod
    {
        $method = $this->getAttribute('depreciation_method');

        return $method instanceof DepreciationMethod ? $method : DepreciationMethod::StraightLine;
    }

    /**
     * Whether the parameters the chosen method needs are present: a useful
     * life of at least one month for straight-line, an annual rate inside the
     * accepted window (1–100%) for declining balance, nothing for an
     * immediate write-off. A rate outside the window is never depreciated on.
     */
    public function hasDepreciationConfig(): bool
    {
        return match ($this->depreciationMethod()) {
            DepreciationMethod::StraightLine => (int) $this->useful_life_months >= 1,
            DepreciationMethod::DecliningBalance => (float) $this->depreciation_rate >= DepreciationMethod::MIN_RATE
                && (float) $this->depreciation_rate <= DepreciationMethod::MAX_RATE,
            DepreciationMethod::Immediate => true,
        };
    }

    /**
     * The materiality limit that ends a declining-balance asset which has no
     * useful life: once the balance left after a year's normal charge would be
     * at or below it, that year writes the whole balance off. The stored amount
     * when one was set, otherwise 5% of cost rounded half-up.
     */
    public function materialityLimitCents(): int
    {
        if ($this->materiality_limit_cents !== null) {
            return (int) $this->materiality_limit_cents;
        }

        return self::defaultMaterialityCents((int) $this->cost_cents);
    }

    /**
     * The default materiality limit for a given cost: 5% of it, rounded half-up
     * in integer arithmetic. Shared with the asset form so the amount it
     * pre-fills is exactly the amount the schedule would use.
     */
    public static function defaultMaterialityCents(int $costCents): int
    {
        return intdiv($costCents * self::DEFAULT_MATERIALITY_PERCENT * 2 + 100, 200);
    }

    /**
     * Whether a book-depreciation schedule can be computed at all: the method's
     * parameters, an in-service date to start from, and a positive depreciable
     * base. This is the single eligibility test the schedule calculator
     * applies before producing any rows.
     */
    public function hasDepreciationSchedule(): bool
    {
        return $this->in_service_date !== null
            && $this->netCostCents() > 0
            && $this->hasDepreciationConfig();
    }

    /**
     * Whether the asset's configuration supports automatic monthly book
     * depreciation: opted in, both depreciation accounts, and a computable
     * schedule for its method. Liveness (is_active) is filtered by the
     * generator's query — a disposed asset can still back-fill months before
     * its disposal month.
     */
    public function isAutoDepreciable(): bool
    {
        return (bool) $this->auto_depreciate
            && $this->accumulated_depreciation_account_id !== null
            && $this->depreciation_expense_account_id !== null
            && $this->hasDepreciationSchedule();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acquired_date' => 'date:Y-m-d',
            'in_service_date' => 'date:Y-m-d',
            'disposed_at' => 'date:Y-m-d',
            'status' => AssetStatus::class,
            'cost_cents' => 'integer',
            'salvage_value_cents' => 'integer',
            'useful_life_months' => 'integer',
            'depreciation_method' => DepreciationMethod::class,
            'depreciation_rate' => 'decimal:3',
            'materiality_limit_cents' => 'integer',
            'auto_depreciate' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
