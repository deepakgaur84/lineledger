<?php

namespace App\Services\Reporting;

use App\Enums\AccountSubtype;
use App\Enums\BillPaymentStatus;
use App\Enums\ChequeStatus;
use App\Enums\ExpenseStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Support\Tax\Form1099Threshold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Totals year-end disbursements to vendors flagged for 1099 reporting (US only).
 *
 * A vendor's reportable total is the sum of everything actually paid out to them in
 * the calendar year — posted bill payments, posted cheques coded directly to expense
 * (a cheque refunding a customer is excluded) and posted pay-now expenses. Amounts
 * are summed in home currency (home_amount_cents, falling back to the document
 * amount when null).
 *
 * Card and payment-app payments are left out. The IRS has the card company or app
 * (the payment settlement entity) report them on Form 1099-K, and they "are not
 * subject to reporting on Form 1099-MISC or Form 1099-NEC" (Instructions for Forms
 * 1099-MISC and 1099-NEC). A bill payment or expense counts as one when it was drawn
 * on a credit-card account, or made with a payment method marked as a card or payment
 * app (payment_methods.is_card: a debit card or PayPal paid from the bank account).
 * Cheques are always paper, so they always count.
 *
 * meets_threshold compares the year's total with that year's IRS reporting threshold
 * ({@see Form1099Threshold}): $600 for payments made before 2026, $2,000 for 2026.
 *
 * Single-category model: every flagged vendor is treated as 1099-NEC Box 1
 * (nonemployee compensation); the report does not split amounts across boxes.
 */
class Form1099Calculator
{
    /**
     * @return array<int, array{contact_id: int, name: string, tax_number: ?string, total_cents: int, meets_threshold: bool}>
     *
     * @throws InvalidArgumentException when the period spans calendar years, each of which has its own threshold
     */
    public function rows(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($start->year !== $end->year) {
            throw new InvalidArgumentException('A 1099 Summary covers one calendar year; the period must start and end in the same year.');
        }

        $threshold = Form1099Threshold::centsFor($start->year);

        $vendors = Contact::query()
            ->where('company_id', $company->id)
            ->where('is_vendor', true)
            ->where('track_1099', true)
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'tax_number']);

        if ($vendors->isEmpty()) {
            return [];
        }

        $ids = $vendors->pluck('id')->all();

        $byBillPayment = DB::table('bill_payments')
            ->whereIn('contact_id', $ids)
            ->where('status', BillPaymentStatus::Posted->value)
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->tap(fn (Builder $query) => $this->excludeCardPayments($query, $company, 'paid_from_account_id', 'payment_method_id'))
            ->groupBy('contact_id')
            ->selectRaw('contact_id, SUM(COALESCE(home_amount_cents, amount_cents)) AS total')
            ->pluck('total', 'contact_id');

        $byCheque = DB::table('cheques')
            ->whereIn('payee_contact_id', $ids)
            ->whereNull('credit_memo_id')
            ->whereNull('refunded_receipt_id')
            ->where('status', ChequeStatus::Posted->value)
            ->whereBetween('cheque_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('payee_contact_id')
            ->selectRaw('payee_contact_id, SUM(COALESCE(home_amount_cents, amount_cents)) AS total')
            ->pluck('total', 'payee_contact_id');

        // Pay-now expenses (EFT / debit / card, incl. lines recorded from a bank
        // import) are a third, disjoint disbursement path: never a bill payment,
        // never a cheque, so summing all three cannot double count.
        $byExpense = DB::table('expenses')
            ->where('company_id', $company->id)
            ->whereIn('payee_contact_id', $ids)
            ->where('status', ExpenseStatus::Posted->value)
            ->whereNull('deleted_at')
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->tap(fn (Builder $query) => $this->excludeCardPayments($query, $company, 'payment_account_id', 'payment_method_id'))
            ->groupBy('payee_contact_id')
            ->selectRaw('payee_contact_id, SUM(COALESCE(home_amount_cents, amount_cents)) AS total')
            ->pluck('total', 'payee_contact_id');

        $rows = [];

        foreach ($vendors as $vendor) {
            $total = (int) ($byBillPayment[$vendor->id] ?? 0)
                + (int) ($byCheque[$vendor->id] ?? 0)
                + (int) ($byExpense[$vendor->id] ?? 0);

            $rows[] = [
                'contact_id' => (int) $vendor->id,
                'name' => (string) $vendor->display_name,
                'tax_number' => $vendor->tax_number,
                'total_cents' => $total,
                'meets_threshold' => $total >= $threshold,
            ];
        }

        return $rows;
    }

    /**
     * Drop the payments reported on Form 1099-K instead: those drawn on a credit-card
     * account, and those made with a card or payment-app method. A payment with no
     * method is kept (NOT IN alone would drop it, since NULL never compares).
     */
    private function excludeCardPayments(Builder $query, Company $company, string $accountColumn, string $methodColumn): Builder
    {
        return $query
            ->whereNotIn($accountColumn, fn (Builder $accounts) => $accounts
                ->select('id')
                ->from('accounts')
                ->where('company_id', $company->id)
                ->where('subtype', AccountSubtype::CreditCard->value))
            ->where(fn (Builder $methods) => $methods
                ->whereNull($methodColumn)
                ->orWhereNotIn($methodColumn, fn (Builder $cards) => $cards
                    ->select('id')
                    ->from('payment_methods')
                    ->where('company_id', $company->id)
                    ->where('is_card', true)));
    }
}
