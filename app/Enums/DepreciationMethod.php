<?php

namespace App\Enums;

/**
 * How an asset's book depreciation is calculated (assets.depreciation_method).
 *
 * The three methods are deliberately independent rather than one formula with
 * different settings. In particular, Immediate is NOT "declining balance at
 * 100%": in a monthly schedule a 100% annual rate spreads the whole base over
 * the first twelve months (or, with a nominal rate/12 convention, never finishes
 * in a year). Only an unusual "effective monthly" convention would collapse it
 * into month one. Immediate also needs no useful life, rate or materiality
 * limit, which is a different question to ask the user.
 */
enum DepreciationMethod: string
{
    case StraightLine = 'straight_line';
    case DecliningBalance = 'declining_balance';
    case Immediate = 'immediate';

    /** The lowest annual rate a declining-balance asset accepts, in percent. Nothing below is stored. */
    public const MIN_RATE = 1;

    /** The highest annual rate a declining-balance asset accepts, in percent. */
    public const MAX_RATE = 100;

    public function label(): string
    {
        return match ($this) {
            self::StraightLine => 'Straight-line',
            self::DecliningBalance => 'Written-down value (declining balance)',
            self::Immediate => '100% on purchase (write off in full)',
        };
    }

    /**
     * Whether the method uses a useful life (months). Straight-line needs one;
     * for declining balance it is optional and, when given, makes the final
     * year of that life the tail that takes whatever balance is left.
     */
    public function usesUsefulLife(): bool
    {
        return $this !== self::Immediate;
    }

    /**
     * Whether the method needs an annual percentage rate.
     */
    public function usesRate(): bool
    {
        return $this === self::DecliningBalance;
    }

    /**
     * Whether the method uses a materiality limit — declining balance's way of
     * ending an asset that has no useful life to end it.
     */
    public function usesMateriality(): bool
    {
        return $this === self::DecliningBalance;
    }

    /**
     * Lenient parse for spreadsheet/CSV input — "WDV", "straight line",
     * "Reducing balance", "100%" and the enum's own values all resolve. Returns
     * null for anything unrecognised so the caller can report it.
     */
    public static function fromLoose(string $value): ?self
    {
        $key = (string) preg_replace('/[^a-z0-9%]/', '', mb_strtolower($value));

        return match ($key) {
            'straightline', 'sl' => self::StraightLine,
            'wdv', 'writtendownvalue', 'decliningbalance', 'reducingbalance', 'diminishingvalue', 'dv', 'db' => self::DecliningBalance,
            'immediate', 'immediately', 'fullonpurchase', 'writeoff', 'writeofffully', 'instantwriteoff', '100%', '100' => self::Immediate,
            default => null,
        };
    }
}
