<?php

namespace App\Services\Banking\Import;

use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * Fork: the receivable twin of {@see OpenBillMatcher}. Finds the open
 * invoice(s) an imported deposit appears to settle: posted or partial invoices
 * of the customer whose remaining balance equals the line amount, in the bank
 * account's currency. When no single invoice matches, an exact-sum set of the
 * customer's open invoices is offered ({@see allocationFor()}).
 *
 * Suggest-only: the receipt is recorded in RecordStatementLine, which
 * re-validates through {@see assertReceivableSet()} because an invoice may have
 * been paid elsewhere since the offer was made.
 */
final class OpenInvoiceMatcher
{
    /** Subset search is only attempted when the customer has this many open invoices or fewer. */
    public const MAX_SUBSET_INVOICES = 10;

    /**
     * Invoices that exactly match one deposit, optionally for a given customer.
     *
     * @return Collection<int, Invoice>
     */
    public function candidates(BankStatementLine $line, int $contactId): Collection
    {
        return $this->forLines(collect([$line]), [$line->id => $contactId])[$line->id] ?? collect();
    }

    /**
     * Exactly one candidate → that invoice; zero or several → null.
     */
    public function forLine(BankStatementLine $line, int $contactId): ?Invoice
    {
        $candidates = $this->candidates($line, $contactId);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Candidates for many deposits in one query. Only lines with a customer are
     * considered; lines are keyed by id in the result, which holds only lines
     * with at least one candidate.
     *
     * @param  Collection<int, BankStatementLine>  $lines
     * @param  array<int, int|null>  $contactIdByLine  line id => customer id
     * @return array<int, Collection<int, Invoice>>
     */
    public function forLines(Collection $lines, array $contactIdByLine): array
    {
        $inflows = $lines->filter(fn (BankStatementLine $line): bool => $line->isInflow()
            && ($contactIdByLine[$line->id] ?? null) !== null)->values();

        if ($inflows->isEmpty()) {
            return [];
        }

        $companyId = (int) $inflows->first()->company_id;
        $contactIds = $inflows->map(fn (BankStatementLine $line): int => (int) $contactIdByLine[$line->id])->unique()->values();

        $invoices = Invoice::query()
            ->where('company_id', $companyId)
            ->openWithBalance()
            ->whereIn('contact_id', $contactIds)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            return [];
        }

        $company = Company::query()->findOrFail($companyId);
        $accounts = Account::withoutGlobalScopes()
            ->whereIn('id', $inflows->pluck('account_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $result = [];

        foreach ($inflows as $line) {
            $account = $accounts->get($line->account_id);

            if (! $account instanceof Account) {
                continue;
            }

            $contactId = (int) $contactIdByLine[$line->id];
            $amount = abs((int) $line->amount_cents);

            $matches = $invoices
                ->filter(fn (Invoice $invoice): bool => (int) $invoice->contact_id === $contactId
                    && $invoice->balanceCents() === $amount
                    && $this->currencyCompatible($company, $account, $invoice))
                ->values();

            if ($matches->isNotEmpty()) {
                $result[$line->id] = $matches;
            }
        }

        return $result;
    }

    /**
     * Every open, currency-compatible invoice of the customer, oldest due first.
     *
     * @return Collection<int, Invoice>
     */
    public function openInvoicesFor(BankStatementLine $line, Contact $contact): Collection
    {
        /** @var Account $account */
        $account = $line->account()->firstOrFail();
        $company = $account->company;

        return Invoice::query()
            ->where('company_id', $line->company_id)
            ->where('contact_id', $contact->id)
            ->openWithBalance()
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice): bool => $this->currencyCompatible($company, $account, $invoice))
            ->values();
    }

    /**
     * An exact-sum set of the customer's open invoices for this deposit, paid in
     * full: the oldest-due invoices that fit first, else the single subset that
     * adds up. Null when there is no such set, when it is ambiguous, or when the
     * customer has more open invoices than we will search.
     *
     * @return list<array{invoice_id: int, amount_cents: int}>|null
     */
    public function allocationFor(BankStatementLine $line, int $contactId): ?array
    {
        if (! $line->isInflow()) {
            return null;
        }

        $contact = Contact::withoutGlobalScopes()
            ->where('company_id', $line->company_id)
            ->find($contactId);

        if ($contact === null) {
            return null;
        }

        $invoices = $this->openInvoicesFor($line, $contact);
        $count = $invoices->count();

        if ($count < 2 || $count > self::MAX_SUBSET_INVOICES) {
            return null;
        }

        $target = abs((int) $line->amount_cents);
        $balances = $invoices->map(fn (Invoice $invoice): int => $invoice->balanceCents())->all();
        $ids = $invoices->map(fn (Invoice $invoice): int => (int) $invoice->id)->all();

        // Oldest due first: take every invoice that still fits.
        $sum = 0;
        $picked = [];
        foreach ($balances as $i => $balance) {
            if ($sum + $balance <= $target) {
                $sum += $balance;
                $picked[] = $i;
            }
        }

        if ($sum !== $target) {
            // Otherwise the unique subset that adds up exactly, if there is one.
            $picked = null;
            for ($mask = 1; $mask < (1 << $count); $mask++) {
                $sum = 0;
                for ($i = 0; $i < $count; $i++) {
                    if ($mask & (1 << $i)) {
                        $sum += $balances[$i];
                    }
                }

                if ($sum !== $target) {
                    continue;
                }

                if ($picked !== null) {
                    return null; // ambiguous
                }

                $picked = [];
                for ($i = 0; $i < $count; $i++) {
                    if ($mask & (1 << $i)) {
                        $picked[] = $i;
                    }
                }
            }
        }

        if ($picked === null || count($picked) < 2) {
            return null;
        }

        return array_map(fn (int $i): array => ['invoice_id' => $ids[$i], 'amount_cents' => $balances[$i]], $picked);
    }

    /**
     * Re-check, at post time and under row locks, that this deposit can settle
     * this set of invoices with these amounts. Returns the invoices keyed by id.
     *
     * @param  list<array{invoice_id: int, amount_cents: int}>  $allocations
     * @return Collection<int, Invoice>
     *
     * @throws PostingValidationException
     */
    public function assertReceivableSet(BankStatementLine $line, Contact $contact, array $allocations): Collection
    {
        if (! $line->isInflow()) {
            throw new PostingValidationException(__('An invoice can only be paid by money coming in.'));
        }

        if ($allocations === []) {
            throw new PostingValidationException(__('Choose at least one invoice to pay.'));
        }

        $amountByInvoice = [];

        foreach ($allocations as $allocation) {
            $invoiceId = (int) $allocation['invoice_id'];
            $amount = (int) $allocation['amount_cents'];

            if ($invoiceId <= 0 || $amount <= 0) {
                throw new PostingValidationException(__('Apply a positive amount to each invoice.'));
            }

            if (isset($amountByInvoice[$invoiceId])) {
                throw new PostingValidationException(__('Each invoice can only be paid once in the same payment.'));
            }

            $amountByInvoice[$invoiceId] = $amount;
        }

        $invoices = Invoice::query()
            ->where('company_id', $line->company_id)
            ->whereIn('id', array_keys($amountByInvoice))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var Account $account */
        $account = $line->account()->firstOrFail();
        $company = $account->company;

        foreach ($amountByInvoice as $invoiceId => $amount) {
            /** @var Invoice|null $invoice */
            $invoice = $invoices->get($invoiceId);

            if ($invoice === null) {
                throw new PostingValidationException(__('Invoice #:id no longer exists — reopen Receive payment on this line.', ['id' => $invoiceId]));
            }

            $no = $invoice->invoice_no ?: '#'.$invoice->id;

            if ((int) $invoice->contact_id !== (int) $contact->id) {
                throw new PostingValidationException(__('Invoice :no belongs to a different customer.', ['no' => $no]));
            }

            if (! $invoice->isOpen()) {
                throw new PostingValidationException(__('Invoice :no is no longer open.', ['no' => $no]));
            }

            if (! $this->currencyCompatible($company, $account, $invoice)) {
                throw new PostingValidationException(__('Invoice :no and the bank account use different currencies — record the payment from the invoice instead.', ['no' => $no]));
            }

            if ($amount > $invoice->balanceCents()) {
                throw new PostingValidationException(__("Invoice :no's remaining balance changed since it was offered — reopen Receive payment on this line.", ['no' => $no]));
            }
        }

        if (array_sum($amountByInvoice) !== abs((int) $line->amount_cents)) {
            throw new PostingValidationException(__('The amounts applied must add up to the transaction total.'));
        }

        return $invoices;
    }

    private function currencyCompatible(Company $company, Account $account, Invoice $invoice): bool
    {
        $home = mb_strtoupper((string) $company->currency_code);
        $accountCode = mb_strtoupper((string) ($account->currency_code ?: $home));
        $invoiceCode = mb_strtoupper((string) ($invoice->getAttribute('currency_code') ?: $home));

        return $accountCode === $invoiceCode;
    }
}
