<?php

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Services\Assets\DepreciationSchedule;
use Tests\TestCase;

// Same opt-in as DepreciationScheduleTest: the Asset date casts resolve through
// the Date facade, which needs the application container. No database is
// touched — assets stay unsaved.
uses(TestCase::class);

function wdvAsset(array $attributes = []): Asset
{
    return new Asset(array_merge([
        'cost_cents' => 1000000,
        'salvage_value_cents' => 0,
        'in_service_date' => '2026-01-15',
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
    ], $attributes));
}

/**
 * Each 12-month block's total, in order.
 *
 * @param  list<array{amount_cents: int}>  $rows
 * @return list<int>
 */
function yearlyTotals(array $rows): array
{
    return array_map('array_sum', array_chunk(array_column($rows, 'amount_cents'), 12));
}

it('charges the annual rate on the opening book value, spread evenly across each 12-month block', function () {
    $rows = DepreciationSchedule::for(wdvAsset());

    // Year 1: 20% of 10,000.00 = 2,000.00 → 16,666 × 11 plus a 16,674 closing month.
    expect($rows[0]['amount_cents'])->toBe(16666)
        ->and($rows[10]['amount_cents'])->toBe(16666)
        ->and($rows[11]['amount_cents'])->toBe(16674)
        ->and($rows[11]['cumulative_cents'])->toBe(200000)
        // Year 2: 20% of the 8,000.00 left at the start of it.
        ->and($rows[12]['amount_cents'])->toBe(13333)
        ->and($rows[23]['cumulative_cents'])->toBe(360000)
        ->and($rows[0]['period']->toDateString())->toBe('2026-01-01')
        ->and($rows[12]['period']->toDateString())->toBe('2027-01-01');
});

it('rounds each year\'s charge half-up', function () {
    $rows = DepreciationSchedule::for(wdvAsset([
        'cost_cents' => 4500000,
        'salvage_value_cents' => 450000,
        'depreciation_rate' => 25,
        'useful_life_months' => 60,
    ]));

    // Year 3 is 25% of 25,312.50 = 6,328.125 → 632,812.5¢, which rounds up.
    // Year 5 is the tail and takes what is left above salvage.
    expect(yearlyTotals($rows))->toBe([1125000, 843750, 632813, 474609, 973828])
        ->and($rows)->toHaveCount(60);
});

// ─── With a useful life: its final year is the tail ──────────────────────────

it('takes the whole remaining balance in the final year of the useful life', function () {
    $rows = DepreciationSchedule::for(wdvAsset(['useful_life_months' => 60]));

    // Four normal years (20% of the opening balance each), then year 5 overrides
    // the normal 81,920 charge and takes the 409,600 that is left.
    expect(yearlyTotals($rows))->toBe([200000, 160000, 128000, 102400, 409600])
        ->and($rows)->toHaveCount(60)
        ->and($rows[48]['amount_cents'])->toBe(34133)
        ->and($rows[59]['amount_cents'])->toBe(34137)
        ->and($rows[59]['cumulative_cents'])->toBe(1000000);
});

it('ends the tail exactly when the useful life ends, even mid-year', function () {
    $rows = DepreciationSchedule::for(wdvAsset(['useful_life_months' => 30]));

    // Months 25–30 are the tail: the 640,000 left after two years, over 6 months.
    expect($rows)->toHaveCount(30)
        ->and(yearlyTotals($rows))->toBe([200000, 160000, 640000])
        ->and(array_slice(array_column($rows, 'amount_cents'), 24))->toBe([106666, 106666, 106666, 106666, 106666, 106670])
        ->and($rows[29]['period']->toDateString())->toBe('2028-06-01');
});

it('writes the whole base off over a useful life of a year or less', function () {
    $twelve = DepreciationSchedule::for(wdvAsset(['useful_life_months' => 12]));
    $six = DepreciationSchedule::for(wdvAsset(['useful_life_months' => 6]));

    expect($twelve)->toHaveCount(12)
        ->and($twelve[0]['amount_cents'])->toBe(83333)
        ->and($twelve[11]['amount_cents'])->toBe(83337)
        ->and(array_column($six, 'amount_cents'))->toBe([166666, 166666, 166666, 166666, 166666, 166670]);
});

it('never runs past the useful life and still stops at salvage if that comes first', function () {
    $rows = DepreciationSchedule::for(wdvAsset([
        'salvage_value_cents' => 700000,
        'depreciation_rate' => 50,
        'useful_life_months' => 60,
    ]));

    // 50% of 10,000.00 would overshoot the 3,000.00 above salvage, so the first
    // year is capped at exactly that and the asset is done, four years early.
    expect($rows)->toHaveCount(12)
        ->and(array_unique(array_column($rows, 'amount_cents')))->toBe([25000])
        ->and(array_sum(array_column($rows, 'amount_cents')))->toBe(300000);
});

it('ignores the materiality limit when there is a useful life', function () {
    $with = DepreciationSchedule::for(wdvAsset(['useful_life_months' => 36, 'materiality_limit_cents' => 100000]));
    $without = DepreciationSchedule::for(wdvAsset(['useful_life_months' => 36]));

    expect(array_column($with, 'amount_cents'))->toBe(array_column($without, 'amount_cents'))
        ->and(yearlyTotals($with))->toBe([200000, 160000, 640000]);
});

// ─── With no useful life: the materiality limit is the tail ──────────────────

it('with no useful life, closes out once the balance left would be within the default 5% of cost', function () {
    $rows = DepreciationSchedule::for(wdvAsset());

    // The materiality limit is 5% of 10,000.00 = 500.00. Year 13 charges 13,744 and
    // leaves 54,975 — still over 50,000 — so year 14 would leave 43,980, within the
    // limit, and instead takes the whole 54,975.
    expect(yearlyTotals($rows))->toBe([200000, 160000, 128000, 102400, 81920, 65536, 52429, 41943, 33554, 26844, 21475, 17180, 13744, 54975])
        ->and($rows)->toHaveCount(168)
        ->and($rows[167]['cumulative_cents'])->toBe(1000000);
});

it('uses a custom materiality limit', function () {
    $rows = DepreciationSchedule::for(wdvAsset(['materiality_limit_cents' => 100000]));

    expect(yearlyTotals($rows))->toBe([200000, 160000, 128000, 102400, 81920, 65536, 52429, 41943, 33554, 26844, 107374])
        ->and($rows)->toHaveCount(132);
});

it('with a materiality limit of zero, only closes out when a year\'s charge rounds to nothing', function () {
    $rows = DepreciationSchedule::for(wdvAsset(['materiality_limit_cents' => 0]));
    $years = yearlyTotals($rows);

    expect($rows)->toHaveCount(708)
        ->and(end($years))->toBe(2)
        ->and(array_sum(array_column($rows, 'amount_cents')))->toBe(1000000);
});

it('defaults the materiality limit to 5% of cost, rounded half-up, unless one is set', function () {
    expect(wdvAsset(['cost_cents' => 1000000])->materialityLimitCents())->toBe(50000)
        ->and(wdvAsset(['cost_cents' => 10])->materialityLimitCents())->toBe(1)
        ->and(wdvAsset(['cost_cents' => 30])->materialityLimitCents())->toBe(2)
        ->and(wdvAsset(['cost_cents' => 29])->materialityLimitCents())->toBe(1)
        ->and(wdvAsset(['materiality_limit_cents' => 12345])->materialityLimitCents())->toBe(12345)
        ->and(wdvAsset(['materiality_limit_cents' => 0])->materialityLimitCents())->toBe(0);
});

// ─── The rate window ────────────────────────────────────────────────────────

it('does not treat a 100% rate as a one-month write-off', function () {
    $rows = DepreciationSchedule::for(wdvAsset(['depreciation_rate' => 100]));

    // This is why "100% on purchase" is its own method: a 100% annual rate on a
    // monthly schedule spreads the base over the first twelve months.
    expect($rows)->toHaveCount(12)
        ->and($rows[0]['amount_cents'])->toBe(83333)
        ->and($rows[11]['amount_cents'])->toBe(83337)
        ->and(array_sum(array_column($rows, 'amount_cents')))->toBe(1000000);
});

it('accepts nothing below 1% or above 100%', function () {
    foreach ([0, 0.5, 0.99, 100.5, 150] as $rate) {
        expect(DepreciationSchedule::for(wdvAsset(['depreciation_rate' => $rate])))->toBe([], "a rate of {$rate}% must give no schedule");
    }

    foreach ([1, 100] as $rate) {
        expect(DepreciationSchedule::for(wdvAsset(['depreciation_rate' => $rate])))->not->toBe([], "a rate of {$rate}% must be accepted");
    }
});

it('closes a 1% asset out at the 100-year ceiling rather than running forever', function () {
    $rows = DepreciationSchedule::for(wdvAsset(['depreciation_rate' => 1]));

    expect($rows)->toHaveCount(1200)
        ->and(array_sum(array_column($rows, 'amount_cents')))->toBe(1000000);
});

// ─── Invariants across the board ────────────────────────────────────────────

it('always totals the depreciable base exactly, never goes negative, and never outlives a useful life', function () {
    foreach ([5000, 99999, 1000000, 123456789] as $cost) {
        foreach ([0, intdiv($cost, 10)] as $salvage) {
            foreach ([1, 8.5, 20, 33.333, 100] as $rate) {
                foreach ([null, 12, 30, 60] as $life) {
                    $rows = DepreciationSchedule::for(wdvAsset([
                        'cost_cents' => $cost,
                        'salvage_value_cents' => $salvage,
                        'depreciation_rate' => $rate,
                        'useful_life_months' => $life,
                    ]));
                    $amounts = array_column($rows, 'amount_cents');

                    expect(array_sum($amounts))->toBe($cost - $salvage)
                        ->and(min($amounts))->toBeGreaterThanOrEqual(0)
                        ->and(count($rows))->toBeLessThanOrEqual(1200);

                    if ($life !== null) {
                        expect(count($rows))->toBeLessThanOrEqual($life);
                    } else {
                        expect(count($rows) % 12)->toBe(0);
                    }
                }
            }
        }
    }
});

// ─── Immediate ──────────────────────────────────────────────────────────────

it('writes the whole depreciable base off in the in-service month for an immediate asset', function () {
    $rows = DepreciationSchedule::for(wdvAsset([
        'depreciation_method' => 'immediate',
        'depreciation_rate' => null,
        'cost_cents' => 1200000,
        'salvage_value_cents' => 200000,
    ]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['period']->toDateString())->toBe('2026-01-01')
        ->and($rows[0]['amount_cents'])->toBe(1000000)
        ->and($rows[0]['cumulative_cents'])->toBe(1000000);
});

it('needs no useful life, rate or materiality limit for an immediate asset, and ignores them if given', function () {
    $bare = DepreciationSchedule::for(wdvAsset(['depreciation_method' => 'immediate', 'depreciation_rate' => null]));
    $noisy = DepreciationSchedule::for(wdvAsset([
        'depreciation_method' => 'immediate',
        'useful_life_months' => 36,
        'depreciation_rate' => 20,
        'materiality_limit_cents' => 5000,
    ]));

    expect($bare)->toHaveCount(1)
        ->and($noisy)->toHaveCount(1)
        ->and($bare[0]['amount_cents'])->toBe($noisy[0]['amount_cents']);
});

// ─── Eligibility and defaults ───────────────────────────────────────────────

it('is empty when the method\'s parameters are missing or there is nothing to depreciate', function () {
    expect(DepreciationSchedule::for(wdvAsset(['depreciation_rate' => null])))->toBe([])
        ->and(DepreciationSchedule::for(wdvAsset(['in_service_date' => null])))->toBe([])
        ->and(DepreciationSchedule::for(wdvAsset(['salvage_value_cents' => 1000000])))->toBe([])
        ->and(DepreciationSchedule::for(wdvAsset(['depreciation_method' => 'immediate', 'depreciation_rate' => null, 'salvage_value_cents' => 1000000])))->toBe([])
        ->and(DepreciationSchedule::for(wdvAsset(['depreciation_method' => 'immediate', 'depreciation_rate' => null, 'in_service_date' => null])))->toBe([]);
});

it('treats an asset with no method as straight-line', function () {
    $asset = new Asset([
        'cost_cents' => 100000,
        'salvage_value_cents' => 0,
        'useful_life_months' => 36,
        'in_service_date' => '2026-01-15',
    ]);

    expect($asset->depreciationMethod())->toBe(DepreciationMethod::StraightLine)
        ->and(DepreciationSchedule::for($asset))->toHaveCount(36);
});

it('ignores a stray rate on a straight-line asset', function () {
    $asset = new Asset([
        'cost_cents' => 100000,
        'useful_life_months' => 36,
        'in_service_date' => '2026-01-15',
        'depreciation_method' => 'straight_line',
        'depreciation_rate' => 20,
    ]);

    expect(DepreciationSchedule::for($asset))->toHaveCount(36)
        ->and(DepreciationSchedule::for($asset)[0]['amount_cents'])->toBe(2777);
});

it('parses the usual spreadsheet spellings of each method', function () {
    expect(DepreciationMethod::fromLoose('WDV'))->toBe(DepreciationMethod::DecliningBalance)
        ->and(DepreciationMethod::fromLoose('Reducing balance'))->toBe(DepreciationMethod::DecliningBalance)
        ->and(DepreciationMethod::fromLoose('declining_balance'))->toBe(DepreciationMethod::DecliningBalance)
        ->and(DepreciationMethod::fromLoose('Straight line'))->toBe(DepreciationMethod::StraightLine)
        ->and(DepreciationMethod::fromLoose('straight-line'))->toBe(DepreciationMethod::StraightLine)
        ->and(DepreciationMethod::fromLoose('100%'))->toBe(DepreciationMethod::Immediate)
        ->and(DepreciationMethod::fromLoose('Immediate'))->toBe(DepreciationMethod::Immediate)
        ->and(DepreciationMethod::fromLoose('nonsense'))->toBeNull();
});
