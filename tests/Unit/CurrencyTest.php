<?php

use App\Support\Currency;

it('converts foreign cents to home cents at a rate', function () {
    expect(Currency::toHomeCents(100_000, '1.35'))->toBe(135_000)
        ->and(Currency::toHomeCents(100_000, '1.40'))->toBe(140_000)
        ->and(Currency::toHomeCents(0, '1.35'))->toBe(0);
});

it('rounds half up without float drift', function () {
    // 12345 * 1.234 = 15233.73 -> 15234
    expect(Currency::toHomeCents(12_345, '1.234'))->toBe(15_234)
        // 100 * 1.005 = 100.5 -> 101 (half rounds up)
        ->and(Currency::toHomeCents(100, '1.005'))->toBe(101);
});

it('converts negative amounts symmetrically', function () {
    expect(Currency::toHomeCents(-100_000, '1.35'))->toBe(-135_000)
        // -100.5 rounds away from zero to -101 under half-up magnitude
        ->and(Currency::toHomeCents(-100, '1.005'))->toBe(-101);
});

it('treats a rate of 1 as identity', function () {
    expect(Currency::toHomeCents(987_654, '1'))->toBe(987_654);
});

it('offers only two-decimal currencies as selectable', function () {
    $selectable = Currency::selectable();

    expect($selectable)->toHaveKey('USD')
        ->and($selectable)->toHaveKey('CAD')
        ->and($selectable)->not->toHaveKey('JPY');
});

it('selects only known two-decimal currencies', function () {
    expect(Currency::isSelectable('CAD'))->toBeTrue()
        ->and(Currency::isSelectable('usd'))->toBeTrue()
        ->and(Currency::isSelectable('EUR'))->toBeTrue()
        // Zero- and three-decimal: Money can't hold their amounts.
        ->and(Currency::isSelectable('JPY'))->toBeFalse()
        // Unknown: its decimal places aren't known.
        ->and(Currency::isSelectable('XYZ'))->toBeFalse();

    foreach (array_keys(Currency::selectable()) as $code) {
        expect(Currency::isSelectable($code))->toBeTrue();
    }
});

it('converts home cents back to foreign cents at a rate, rounding half up', function () {
    expect(Currency::toForeignCents(135_000, '1.35'))->toBe(100_000)
        // 13,500.00 / 1.3487 = 10,009.639...
        ->and(Currency::toForeignCents(1_350_000, '1.3487'))->toBe(1_000_964)
        // 1.5 rounds away from zero both ways.
        ->and(Currency::toForeignCents(3, '2'))->toBe(2)
        ->and(Currency::toForeignCents(-3, '2'))->toBe(-2)
        ->and(Currency::toForeignCents(0, '1.35'))->toBe(0);
});

it('works out the rate two amounts imply, to eight decimals', function () {
    expect(Currency::impliedRate(1_351_234, 1_000_000))->toBe('1.351234')
        ->and(Currency::impliedRate(135_000, 100_000))->toBe('1.35')
        // 2 / 3 = 0.666666666… rounds up in the eighth place.
        ->and(Currency::impliedRate(2, 3))->toBe('0.66666667')
        ->and(Currency::impliedRate(100, 0))->toBeNull()
        ->and(Currency::impliedRate(-100, 74))->toBeNull();
});

it('normalizes a rate to the plain form it is stored and shown in', function () {
    expect(Currency::normalizeRate('1.35000000'))->toBe('1.35')
        ->and(Currency::normalizeRate(' 1.40 '))->toBe('1.4')
        ->and(Currency::normalizeRate('+2'))->toBe('2')
        ->and(Currency::normalizeRate('.5'))->toBe('0.5')
        ->and(Currency::normalizeRate(1.35))->toBe('1.35')
        ->and(Currency::normalizeRate('0012.50'))->toBe('12.5');

    foreach (['', '0', '0.00', '-1.35', '1e3', '1.123456789', '12345678901', 'abc', null] as $invalid) {
        expect(Currency::normalizeRate($invalid))->toBeNull();
    }
});
