<?php

namespace App\Support\Banking;

use App\Enums\TaxReturnPaymentDirection;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Expense;
use App\Models\PayrollCheque;
use App\Models\PayrollRemittance;
use App\Models\SalesReceipt;
use App\Models\TaxReturnPayment;
use App\Models\Transfer;

/**
 * Composes the memo written on a document's bank-side journal line — the text the
 * bank register shows for that row.
 *
 * The label alone ("Deposit", "Payment", "Cheque 214") only says which subledger
 * the row came from; the operator's own memo is what identifies it. So the two are
 * joined — "Deposit: August 2026 Interac Deposits" — with the memo flattened to a
 * single line and clipped, because a register row is one line and every document
 * memo is a free-form `text` column.
 *
 * The label rules live here rather than in each poster so the backfill command can
 * recognise an old bare label and rewrite it without duplicating them.
 */
class BankLineMemo
{
    /** Longest detail kept; anything past this is clipped with an ellipsis. */
    public const MAX_DETAIL = 200;

    public static function compose(string $label, ?string $detail): string
    {
        $detail = self::flatten($detail);

        return $detail === '' ? $label : $label.': '.$detail;
    }

    /**
     * The bank-leg memo for a source document — label plus its operator memo.
     * Null for a document type that doesn't write a bank leg.
     */
    public static function forSource(object $source): ?string
    {
        $label = self::labelFor($source);

        return $label === null ? null : self::compose($label, self::detailFor($source));
    }

    /**
     * The bare label a bank leg carried before the operator memo was appended.
     */
    public static function labelFor(object $source): ?string
    {
        return match (true) {
            $source instanceof Deposit => 'Deposit',
            $source instanceof Cheque => 'Cheque '.$source->cheque_no,
            $source instanceof Expense => $source->reference ? 'Expense '.$source->reference : 'Expense',
            $source instanceof Transfer => 'Transfer '.$source->transfer_no,
            // A negative customer receipt is money refunded to the customer.
            $source instanceof CustomerReceipt => (int) $source->amount_cents < 0 ? 'Refund' : 'Deposit',
            $source instanceof SalesReceipt => 'Deposit',
            $source instanceof BillPayment => 'Payment',
            $source instanceof TaxReturnPayment => $source->direction === TaxReturnPaymentDirection::Outgoing
                ? 'Tax payment'
                : 'Tax refund',
            $source instanceof PayrollRemittance => 'Remittance payment',
            $source instanceof PayrollCheque => 'Payroll cheque '.$source->cheque_no,
            default => null,
        };
    }

    /**
     * The operator-entered memo on the document, whatever that column is called.
     * A payroll cheque has none — its detail is the payee, already on the entry.
     */
    private static function detailFor(object $source): ?string
    {
        return match (true) {
            $source instanceof TaxReturnPayment, $source instanceof PayrollRemittance => $source->notes,
            $source instanceof PayrollCheque => null,
            default => $source->memo ?? null,
        };
    }

    private static function flatten(?string $detail): string
    {
        $detail = trim((string) preg_replace('/\s+/u', ' ', (string) $detail));

        return mb_strlen($detail) > self::MAX_DETAIL
            ? rtrim(mb_substr($detail, 0, self::MAX_DETAIL)).'…'
            : $detail;
    }
}
