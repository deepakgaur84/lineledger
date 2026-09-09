<?php

namespace App\Support\OpeningBalances;

/**
 * Normalises the document number an operator types into the Opening Balances
 * workspace before it reaches a document column.
 *
 * The `invoice_no` / `bill_no` / `credit_memo_no` / `vendor_credit_no` columns
 * are `string(40)` with a per-company unique index, so a stray blank or an
 * over-long paste has to be dealt with here rather than at the database.
 */
final class OpeningDocumentNumber
{
    /** Matches the `string(40)` document-number columns. */
    public const MAX_LENGTH = 40;

    /** Trim and collapse inner whitespace; an empty result means "generate one". */
    public static function normalize(?string $number): ?string
    {
        $number = trim(preg_replace('/\s+/u', ' ', (string) $number) ?? '');

        return $number === '' ? null : $number;
    }

    public static function isTooLong(?string $number): bool
    {
        return mb_strlen((string) self::normalize($number)) > self::MAX_LENGTH;
    }
}
