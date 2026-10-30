<?php

namespace App\Support\Accounting;

use App\Actions\Accounting\SaveJournalEntry;
use App\Support\Currency;

/**
 * Works out the home and foreign amounts of manual journal lines.
 *
 * A line on a foreign-currency account (a foreign bank or credit card, or a
 * per-currency receivable or payable control account) has to record what it
 * moved in that currency. Without it the period-end revaluation sees a home
 * balance with no foreign units behind it and books the lot away as an
 * unrealized loss, and the receivable/payable reconcilers, which compare a
 * foreign contact's documents against the foreign GL, never see the line. So a
 * line on such an account always leaves here with the account's currency, a
 * rate and a foreign amount, and any two of home amount, foreign amount and
 * rate give the third — per side (debit and credit), rates being home units
 * per 1 foreign unit:
 *
 *   home + foreign + rate  must agree, to within {@see tolerance()}
 *   home + foreign         the rate is the one the two imply
 *   home (+ rate)          foreign = home ÷ rate
 *   foreign (+ rate)       home = foreign × rate, rounded half-up
 *
 * A missing rate is the one in force on the entry date, from the caller's
 * resolver (manual rate, then fetched rate — {@see SaveJournalEntry}).
 *
 * Home amounts worked out from foreign ones are rounded to the cent line by
 * line, so an entry that balances exactly before that rounding can miss by a
 * cent or two after it. Then the difference is spread over those lines a cent
 * at a time, largest first — the ±1¢ rounding plug the document posters apply.
 * Any other imbalance is left for the poster to refuse.
 *
 * Lines on home-currency accounts pass through as they came: nothing is worked
 * out for them, and only a complete memo already on the line — the kind a
 * foreign document puts on every leg — is kept. Pure: no queries. Shared by {@see SaveJournalEntry}, which
 * writes the result, and the journal form, which previews it, so the two
 * cannot disagree. Error keys use the action's field names
 * (lines.N.fx_rate, lines.N.foreign_debit_cents, …).
 *
 * @phpstan-type ResolvedLine array{debit_cents: int, credit_cents: int, currency_code: ?string, fx_rate: ?string, foreign_debit_cents: int, foreign_credit_cents: int, derived: bool}
 */
final class ForeignJournalLines
{
    /**
     * @param  list<array<string, mixed>>  $lines  each line as the action takes it, plus `currency`: the
     *                                             account's foreign currency, or null for a home-currency account
     * @param  callable(string): ?string  $rateFor  the rate in force on the entry date for a currency, or null when none is on file
     * @return array{lines: list<ResolvedLine>, errors: array<string, string>}
     */
    public static function resolve(array $lines, callable $rateFor): array
    {
        $resolved = [];
        $errors = [];

        foreach ($lines as $i => $line) {
            [$resolved[$i], $error] = self::resolveLine($line, $rateFor);

            if ($error !== null) {
                $errors["lines.{$i}.{$error[0]}"] = $error[1];
            }
        }

        if ($errors === []) {
            self::plugRounding($resolved);
        }

        return ['lines' => $resolved, 'errors' => $errors];
    }

    /**
     * How far, in home cents, a home amount sent with its foreign amount and
     * rate may sit from that foreign amount converted at that rate: a cent of
     * rounding either way, or — at a rate above 2, where one foreign cent is
     * worth several home cents — half a foreign cent's worth, because a foreign
     * amount worked out from a home one can only land that close.
     */
    public static function tolerance(string $rate): int
    {
        return max(1, (int) bcadd(bcdiv($rate, '2', 8), '0.5', 0));
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  callable(string): ?string  $rateFor
     * @return array{0: ResolvedLine, 1: array{0: string, 1: string}|null}
     */
    private static function resolveLine(array $line, callable $rateFor): array
    {
        $currency = self::code($line['currency'] ?? null);
        $debit = (int) ($line['debit_cents'] ?? 0);
        $credit = (int) ($line['credit_cents'] ?? 0);
        $foreignDebit = (int) ($line['foreign_debit_cents'] ?? 0);
        $foreignCredit = (int) ($line['foreign_credit_cents'] ?? 0);
        $rateInput = $line['fx_rate'] ?? null;
        $rateGiven = $rateInput !== null && $rateInput !== '';

        $asGiven = self::line($debit, $credit);

        if ($currency === null) {
            return self::resolveHomeLine($line, $debit, $credit, $foreignDebit, $foreignCredit, $rateGiven ? $rateInput : null);
        }

        $given = self::code($line['currency_code'] ?? null);

        if ($given !== null && $given !== $currency) {
            return [$asGiven, ['currency_code', __('This account is in :currency.', ['currency' => $currency])]];
        }

        $rate = null;

        if ($rateGiven) {
            $rate = Currency::normalizeRate($rateInput);

            if ($rate === null) {
                return [$asGiven, ['fx_rate', __('Enter an exchange rate above zero, with at most 8 decimal places.')]];
            }
        }

        $homeGiven = $debit !== 0 || $credit !== 0;
        $foreignGiven = $foreignDebit !== 0 || $foreignCredit !== 0;

        if (! $homeGiven && ! $foreignGiven) {
            // An empty line; the caller drops it.
            return [self::line(0, 0, $currency, $rate), null];
        }

        if ($homeGiven && $foreignGiven) {
            if (($debit !== 0) !== ($foreignDebit !== 0) || ($credit !== 0) !== ($foreignCredit !== 0)) {
                $field = ($debit !== 0) !== ($foreignDebit !== 0) ? 'foreign_debit_cents' : 'foreign_credit_cents';

                return [$asGiven, [$field, __('Put the home and foreign amounts on the same side of the line.')]];
            }

            $rate ??= Currency::impliedRate($debit + $credit, $foreignDebit + $foreignCredit);

            if ($rate === null) {
                return [$asGiven, ['fx_rate', __('The home and foreign amounts don’t give a usable exchange rate.')]];
            }

            foreach ([[$debit, $foreignDebit, 'foreign_debit_cents'], [$credit, $foreignCredit, 'foreign_credit_cents']] as [$home, $foreign, $field]) {
                if (abs($home - Currency::toHomeCents($foreign, $rate)) > self::tolerance($rate)) {
                    return [$asGiven, [$field, __('The home amount doesn’t match the foreign amount at this exchange rate.')]];
                }
            }

            return [self::line($debit, $credit, $currency, $rate, $foreignDebit, $foreignCredit), null];
        }

        if ($rate === null) {
            $rate = Currency::normalizeRate($rateFor($currency));

            if ($rate === null) {
                return [$asGiven, ['fx_rate', __('No :currency exchange rate is on file for this date. Enter the rate on the line.', ['currency' => $currency])]];
            }
        }

        if ($homeGiven) {
            return [self::line(
                $debit,
                $credit,
                $currency,
                $rate,
                Currency::toForeignCents($debit, $rate),
                Currency::toForeignCents($credit, $rate),
            ), null];
        }

        return [self::line(
            Currency::toHomeCents($foreignDebit, $rate),
            Currency::toHomeCents($foreignCredit, $rate),
            $currency,
            $rate,
            $foreignDebit,
            $foreignCredit,
            derived: true,
        ), null];
    }

    /**
     * A line on a home-currency account. It moves no foreign units, so nothing
     * is worked out for it. But it may carry the memo a foreign document puts
     * on every leg — what the leg stood for in the document's currency — and a
     * voided document's reversal copies it: a complete one (currency, rate and
     * a foreign amount on the line's own side) is kept exactly as given, even
     * where the document's rounding plug moved the home amount a few cents off
     * the conversion; nothing reads it as a balance. Anything partial is
     * refused rather than dropped silently.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: ResolvedLine, 1: array{0: string, 1: string}|null}
     */
    private static function resolveHomeLine(array $line, int $debit, int $credit, int $foreignDebit, int $foreignCredit, mixed $rateInput): array
    {
        $asGiven = self::line($debit, $credit);
        $currency = self::code($line['currency_code'] ?? null);

        if ($currency === null && $rateInput === null && $foreignDebit === 0 && $foreignCredit === 0) {
            return [$asGiven, null];
        }

        $rate = $rateInput !== null ? Currency::normalizeRate($rateInput) : null;
        $complete = $currency !== null && $rate !== null
            && ($foreignDebit !== 0 || $foreignCredit !== 0)
            && ($debit !== 0) === ($foreignDebit !== 0)
            && ($credit !== 0) === ($foreignCredit !== 0);

        if (! $complete) {
            $field = match (true) {
                $foreignDebit !== 0 => 'foreign_debit_cents',
                $foreignCredit !== 0 => 'foreign_credit_cents',
                $rateInput !== null => 'fx_rate',
                default => 'currency_code',
            };

            return [$asGiven, [$field, __('This account is in the home currency, so the line takes no foreign amount or exchange rate.')]];
        }

        return [self::line($debit, $credit, $currency, $rate, $foreignDebit, $foreignCredit), null];
    }

    /**
     * When the lines balance exactly before their home amounts were rounded to
     * the cent but not after, move the difference onto the lines whose home
     * amount was worked out here, one cent each, largest first, so the entry
     * balances in home cents. Each such line then still sits within a cent of
     * its own conversion.
     *
     * @param  list<ResolvedLine>  $lines
     */
    private static function plugRounding(array &$lines): void
    {
        $derived = array_keys(array_filter($lines, fn (array $line): bool => $line['derived']));

        if ($derived === []) {
            return;
        }

        $rounded = 0;
        $exact = '0';

        foreach ($lines as $line) {
            $signed = $line['debit_cents'] - $line['credit_cents'];
            $rounded += $signed;
            $exact = bcadd($exact, $line['derived']
                ? bcmul((string) ($line['foreign_debit_cents'] - $line['foreign_credit_cents']), (string) $line['fx_rate'], 8)
                : (string) $signed, 8);
        }

        if ($rounded === 0 || bccomp($exact, '0', 8) !== 0) {
            return;
        }

        usort($derived, fn (int $a, int $b): int => (self::size($lines[$b]) <=> self::size($lines[$a])) ?: ($a <=> $b));

        // Each cent moves the entry's signed (debit − credit) total toward zero.
        $step = $rounded > 0 ? -1 : 1;
        $remaining = abs($rounded);

        foreach ($derived as $i) {
            if ($remaining === 0) {
                break;
            }

            // Read-modify-write the whole line so PHPStan keeps its shape.
            $line = $lines[$i];
            $side = abs($line['debit_cents']) >= abs($line['credit_cents']) ? 'debit_cents' : 'credit_cents';
            $before = $line[$side];
            $after = $before + ($side === 'debit_cents' ? $step : -$step);

            // Never zero a line or flip it to the other sign.
            if ($after === 0 || ($after < 0) !== ($before < 0)) {
                continue;
            }

            $line[$side] = $after;
            $lines[$i] = $line;
            $remaining--;
        }
    }

    /**
     * @param  ResolvedLine  $line
     */
    private static function size(array $line): int
    {
        return abs($line['debit_cents'] - $line['credit_cents']);
    }

    /**
     * @return ResolvedLine
     */
    private static function line(
        int $debit,
        int $credit,
        ?string $currency = null,
        ?string $rate = null,
        int $foreignDebit = 0,
        int $foreignCredit = 0,
        bool $derived = false,
    ): array {
        return [
            'debit_cents' => $debit,
            'credit_cents' => $credit,
            'currency_code' => $currency,
            'fx_rate' => $currency === null ? null : $rate,
            'foreign_debit_cents' => $currency === null ? 0 : $foreignDebit,
            'foreign_credit_cents' => $currency === null ? 0 : $foreignCredit,
            'derived' => $derived,
        ];
    }

    private static function code(mixed $code): ?string
    {
        return is_string($code) && trim($code) !== '' ? mb_strtoupper(trim($code)) : null;
    }
}
