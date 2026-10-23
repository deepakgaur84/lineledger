<?php

namespace App\Services\Banking\Feeds\Akahu;

use App\Services\Banking\Import\DTO\ParsedTransaction;
use Carbon\CarbonImmutable;

/**
 * Turns one Akahu transaction into the importer's normalized transaction.
 *
 * Signs agree without conversion: Akahu amounts are from the account's point of
 * view (money in positive, money out negative), which is also the importer's
 * book-delta for an asset account (debit positive). A credit-card purchase is
 * negative on both sides too.
 */
final class AkahuTransactionMapper
{
    /**
     * @param  array<string, mixed>  $transaction
     */
    public static function toParsed(array $transaction, string $timezone): ?ParsedTransaction
    {
        $id = $transaction['_id'] ?? null;
        $date = $transaction['date'] ?? null;
        $amount = $transaction['amount'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($date) || ! is_numeric($amount)) {
            return null;
        }

        $balance = $transaction['balance'] ?? null;

        return new ParsedTransaction(
            // Akahu dates are midnight in the bank's local day, stored as UTC.
            date: CarbonImmutable::parse($date)->setTimezone($timezone)->startOfDay(),
            amountCents: (int) round(((float) $amount) * 100),
            description: self::description($transaction),
            externalId: $id,
            balanceCents: is_numeric($balance) ? (int) round(((float) $balance) * 100) : null,
            raw: $transaction,
        );
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private static function description(array $transaction): string
    {
        $description = trim((string) ($transaction['description'] ?? ''));

        if ($description !== '') {
            return $description;
        }

        $merchant = $transaction['merchant']['name'] ?? null;

        return is_string($merchant) ? $merchant : '';
    }
}
