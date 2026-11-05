<?php

namespace App\Actions\Banking;

use App\Actions\Purchasing\SaveBillPayment;
use App\Actions\Purchasing\SaveExpense;
use App\Actions\Sales\SaveReceipt;
use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Services\Banking\Import\OpenBillMatcher;
use App\Services\Banking\Import\OpenInvoiceMatcher;
use App\Services\Banking\StatementLineCategoryGuard;
use App\Services\Banking\StatementLineCurrencyGuard;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\ExpensePoster;
use App\Services\Posting\ReceiptPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Posts one bank statement line split across several categories: an inflow
 * becomes a multi-line Deposit, an outflow a multi-line Expense, picked purely
 * by the line's sign. The split amounts must add up to the transaction's exact
 * total. Reuses the existing Save + Poster pipelines, then stamps the line so it
 * reads as Created and ties to its entry, exactly like a single-category "Add".
 *
 * A payee applies to the whole split: the expense's payee (one per expense —
 * expense lines carry no contact), or the default contact on each deposit line
 * (a line's own contact_id still wins). Split parts may carry purchase tax
 * codes; the part's amount is then the tax-inclusive gross, exactly as a
 * single-line Add. Each part's account must be the line's own organization's,
 * and not an AR / AP / Undeposited Funds control account
 * ({@see StatementLineCategoryGuard}).
 *
 * On a foreign-currency account the Deposit / Expense takes the account's
 * currency, so its posters book home cents at the transaction date's rate with
 * the foreign memo on every leg; a part in a third currency is refused
 * ({@see StatementLineCurrencyGuard}).
 */
final class SplitStatementLine
{
    public function __construct(
        private readonly SaveDeposit $saveDeposit,
        private readonly DepositPoster $depositPoster,
        private readonly SaveExpense $saveExpense,
        private readonly ExpensePoster $expensePoster,
        private readonly StatementLineCategoryGuard $categories,
        private readonly StatementLineCurrencyGuard $currencyGuard,
        private readonly SaveReceipt $saveReceipt,
        private readonly ReceiptPoster $receiptPoster,
        private readonly OpenInvoiceMatcher $invoices,
        private readonly SaveBillPayment $saveBillPayment,
        private readonly BillPaymentPoster $billPaymentPoster,
        private readonly OpenBillMatcher $bills,
    ) {}

    /**
     * @param  array<int, array{account_id?: int|null, invoice_id?: int|null, bill_id?: int|null, contact_id?: int|null, amount_cents: int, description?: string|null, tax_code_id?: int|null, secondary_tax_code_id?: int|null}>  $splits
     */
    public function handle(BankStatementLine $line, array $splits, ?int $payeeContactId = null): void
    {
        if ($line->created_journal_entry_id !== null) {
            throw new PostingValidationException(__('This line has already been added — undo it before splitting.'));
        }

        $splits = array_values($splits);
        $total = array_sum(array_map(fn (array $s): int => (int) $s['amount_cents'], $splits));
        $target = abs((int) $line->amount_cents);

        if ($splits === [] || $total !== $target) {
            throw new PostingValidationException(__('Split amounts must add up to the transaction total.'));
        }

        if (array_filter($splits, fn (array $s): bool => (int) $s['amount_cents'] <= 0) !== []) {
            throw new PostingValidationException(__('Each split amount must be greater than zero.'));
        }

        $invoiceParts = array_filter($splits, fn (array $s): bool => ! empty($s['invoice_id']));
        $billParts = array_filter($splits, fn (array $s): bool => ! empty($s['bill_id']));
        $categoryParts = array_filter($splits, fn (array $s): bool => empty($s['invoice_id']) && empty($s['bill_id']));

        if ($invoiceParts !== [] && (int) $line->amount_cents < 0) {
            throw new PostingValidationException(__('An invoice can only be paid by money coming in.'));
        }

        if ($billParts !== [] && (int) $line->amount_cents >= 0) {
            throw new PostingValidationException(__('A bill can only be paid by money going out.'));
        }

        foreach ($categoryParts as $part) {
            if (empty($part['account_id'])) {
                throw new PostingValidationException(__('Choose a category, an invoice or a bill for every part.'));
            }
        }

        $categoryIds = array_values(array_map(fn (array $s): int => (int) $s['account_id'], $categoryParts));
        $this->categories->assertUsable($line, $categoryIds);

        DB::transaction(function () use ($line, $splits, $payeeContactId, $categoryIds, $invoiceParts, $billParts): void {
            $account = $line->account()->firstOrFail();
            $this->currencyGuard->assertCategoriesFit($account, $categoryIds);

            $billEntry = null;
            $date = CarbonImmutable::parse($line->txn_date)->toDateString();
            $memo = ($line->description !== null && $line->description !== '') ? $line->description : null;
            $payee = $payeeContactId !== null ? $this->resolvePayee($line, $payeeContactId) : null;
            $taxCodes = $this->resolveTaxCodes($line, $splits);

            if ((int) $line->amount_cents >= 0) {
                $receiptLines = $this->receiptsForInvoiceParts($line, $account, $invoiceParts, $date, $memo);

                $deposit = $this->saveDeposit->handle([
                    'bank_account_id' => $account->id,
                    'deposit_no' => null,
                    'deposit_date' => $date,
                    'memo' => $memo,
                    'lines' => array_merge(
                        $receiptLines,
                        array_map(fn (array $s): array => [
                            'account_id' => $s['account_id'],
                            'contact_id' => $s['contact_id'] ?? $payee?->id,
                            'amount_cents' => (int) $s['amount_cents'],
                            'description' => $s['description'] ?? $memo,
                        ], array_values(array_filter($splits, fn (array $s): bool => empty($s['invoice_id'])))),
                    ),
                ]);
                $entry = $this->depositPoster->post($deposit);
            } else {
                $billEntry = $this->payBillParts($line, $account, $payee, $billParts, $date, $memo);
                $categorySplits = array_values(array_filter($splits, fn (array $s): bool => empty($s['bill_id'])));

                if ($categorySplits === []) {
                    $entry = $billEntry; // only bills: the bill payment is the whole split
                    $billEntry = null;
                } else {
                    $expense = $this->saveExpense->handle([
                        'payment_account_id' => $account->id,
                        'expense_date' => $date,
                        'payee_contact_id' => $payee?->id,
                        'payee_name' => $payee->display_name ?? ($memo ?? __('Bank transaction')),
                        'memo' => $memo,
                        'lines' => array_map(fn (array $s): array => [
                            'account_id' => $s['account_id'],
                            'amount_cents' => (int) $s['amount_cents'], // the gross for this part
                            'description' => $s['description'] ?? $memo,
                            'tax_code_id' => $taxCodes[(int) ($s['tax_code_id'] ?? 0)] ?? null,
                            'secondary_tax_code_id' => $taxCodes[(int) ($s['secondary_tax_code_id'] ?? 0)] ?? null,
                            'amount_includes_tax' => isset($taxCodes[(int) ($s['tax_code_id'] ?? 0)]),
                        ], $categorySplits),
                    ]);
                    $entry = $this->expensePoster->post($expense);
                }
            }

            $bankLine = $entry->lines()->where('account_id', $account->id)->firstOrFail();

            $line->forceFill([
                'suggested_contact_id' => $payee->id ?? $line->suggested_contact_id,
                'created_journal_entry_id' => $entry->id,
                'matched_journal_line_id' => $bankLine->id,
                'match_status' => StatementLineMatchStatus::Created->value,
            ])->save();

            // The bill payment has its own bank leg: the line is matched to it too.
            if ($billEntry !== null) {
                $billLeg = $billEntry->lines()->where('account_id', $account->id)->firstOrFail();

                DB::table('bank_statement_line_entries')->insert([
                    'bank_statement_line_id' => $line->id,
                    'journal_entry_id' => $billEntry->id,
                    'journal_line_id' => $billLeg->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * One posted customer receipt (into Undeposited Funds) per invoice part;
     * returns the deposit lines that bank them.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return list<array{customer_receipt_id: int, amount_cents: int, description: ?string}>
     */
    private function receiptsForInvoiceParts(BankStatementLine $line, Account $bank, array $parts, string $date, ?string $memo): array
    {
        if ($parts === []) {
            return [];
        }

        if ($bank->currency_code && ! $bank->company->isHomeCurrency((string) $bank->currency_code)) {
            throw new PostingValidationException(__('Invoices can\'t be paid inside a split on a foreign-currency account — record the payment from the invoice instead.'));
        }

        $perInvoice = [];

        foreach ($parts as $part) {
            $id = (int) $part['invoice_id'];
            $perInvoice[$id] = ($perInvoice[$id] ?? 0) + (int) $part['amount_cents'];
        }

        $invoices = $this->invoices->assertPartsPayable($line, $perInvoice);

        $undeposited = Account::withoutGlobalScopes()
            ->where('company_id', $line->company_id)
            ->where('subtype', AccountSubtype::UndepositedFunds->value)
            ->where('is_system', true)
            ->first();

        if ($undeposited === null) {
            throw new PostingValidationException(__('The Undeposited Funds account is missing — it is needed to record an invoice payment in a split.'));
        }

        $lines = [];

        foreach ($parts as $part) {
            $invoice = $invoices->get((int) $part['invoice_id']);
            $cents = (int) $part['amount_cents'];

            $receipt = $this->saveReceipt->handle([
                'contact_id' => (int) $invoice->contact_id,
                'currency_code' => $bank->currency_code ?: $bank->company->currency_code,
                'receipt_date' => $date,
                'deposit_to_account_id' => $undeposited->id,
                'reference' => null,
                'amount_cents' => $cents,
                'memo' => $memo,
                'applications' => [['invoice_id' => (int) $invoice->id, 'amount_cents' => $cents]],
            ]);
            $this->receiptPoster->post($receipt);

            $lines[] = [
                'customer_receipt_id' => (int) $receipt->id,
                'amount_cents' => $cents,
                'description' => $memo,
            ];
        }

        return $lines;
    }

    /**
     * Fork: the bill parts of an outflow split. One bill payment from the bank
     * account settles all the parts' bills (one payee), as "Pay bills" does on a
     * whole line, but for the parts' sum only. It has its own bank leg, which
     * the statement line is matched to alongside the expense's.
     *
     * @param  array<int, array<string, mixed>>  $parts
     */
    private function payBillParts(BankStatementLine $line, Account $bank, ?Contact $payee, array $parts, string $date, ?string $memo): ?JournalEntry
    {
        if ($parts === []) {
            return null;
        }

        if ($payee === null) {
            throw new PostingValidationException(__('Pick the vendor on the row before paying a bill in a split.'));
        }

        $perBill = [];

        foreach ($parts as $part) {
            $id = (int) $part['bill_id'];
            $perBill[$id] = ($perBill[$id] ?? 0) + (int) $part['amount_cents'];
        }

        $allocations = [];

        foreach ($perBill as $billId => $cents) {
            $allocations[] = ['bill_id' => $billId, 'amount_cents' => $cents];
        }

        $bills = $this->bills->assertPayableSet($line, $payee, $allocations, false);
        $type = $bills->first()?->getAttribute('bill_type');

        $payment = $this->saveBillPayment->handle([
            'contact_id' => $payee->id,
            'payment_type' => ($type instanceof BillType ? $type : BillType::Vendor)->value,
            'currency_code' => $bank->currency_code ?: $bank->company->currency_code,
            'payment_date' => $date,
            'paid_from_account_id' => $bank->id,
            'reference' => null,
            'amount_cents' => array_sum($perBill),
            'memo' => $memo,
            'applications' => $allocations,
        ]);

        return $this->billPaymentPoster->post($payment);
    }

    private function resolvePayee(BankStatementLine $line, int $contactId): Contact
    {
        $contact = Contact::query()
            ->where('company_id', $line->company_id)
            ->whereKey($contactId)
            ->first();

        if ($contact === null) {
            throw new PostingValidationException(__('That contact no longer exists — choose another.'));
        }

        return $contact;
    }

    /**
     * Every tax code the parts reference, validated as active purchase codes of
     * this company, keyed by id. A part naming the same code twice is refused.
     *
     * @param  array<int, array<string, mixed>>  $splits
     * @return array<int, int> id => id
     */
    private function resolveTaxCodes(BankStatementLine $line, array $splits): array
    {
        $ids = [];

        foreach ($splits as $split) {
            $primary = isset($split['tax_code_id']) && $split['tax_code_id'] !== '' ? (int) $split['tax_code_id'] : null;
            $secondary = isset($split['secondary_tax_code_id']) && $split['secondary_tax_code_id'] !== '' ? (int) $split['secondary_tax_code_id'] : null;

            if ($primary !== null && $primary === $secondary) {
                throw new PostingValidationException(__('Choose two different tax codes.'));
            }

            foreach ([$primary, $secondary] as $id) {
                if ($id !== null) {
                    $ids[$id] = $id;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $found = TaxCode::query()
            ->where('company_id', $line->company_id)
            ->usableForPurchases()
            ->whereIn('id', array_values($ids))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (count($found) !== count($ids)) {
            throw new PostingValidationException(__('That tax code is no longer available for purchases — choose another.'));
        }

        return array_combine($found, $found);
    }
}
