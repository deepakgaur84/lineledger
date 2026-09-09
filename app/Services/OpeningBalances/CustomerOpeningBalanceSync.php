<?php

namespace App\Services\OpeningBalances;

use App\Actions\Sales\PostCustomerOpeningBalance;
use App\Enums\CreditMemoStatus;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\OpeningBalanceState;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\InvoicePoster;
use App\Support\OpeningBalances\OpeningDocumentNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Keeps a customer's opening AR detail in step with the number typed on the
 * workspace grid. A positive net balance is one opening-balance invoice
 * (DR AR / CR OBE, via the existing PostCustomerOpeningBalance action); a
 * negative one is an opening credit memo (DR OBE / CR AR). Edits repost the
 * existing document in place; sign flips and zeroes void through the posters,
 * so the audit trail and the contact's cached AR balance stay right.
 *
 * Amounts are in the CONTACT's currency — the posters lock the FX rate and
 * route foreign customers to the matching per-currency AR control account.
 *
 * The document number is the operator's to choose: pass one to {@see set()} (or
 * call {@see renameDocument()} afterwards) to carry the reference the balance
 * had in the previous system across. Leave it null and one is generated.
 */
class CustomerOpeningBalanceSync
{
    public function __construct(
        protected PostCustomerOpeningBalance $postOpeningInvoice,
        protected InvoicePoster $invoicePoster,
        protected CreditMemoPoster $creditMemoPoster,
        protected DocumentNumberGenerator $numbers,
        protected OpeningBalanceAccountResolver $openingBalanceAccounts,
    ) {}

    /**
     * The customer's live opening documents and their net signed total (in the
     * contact's currency; positive = owed to us).
     *
     * @return array{net: int, invoices: Collection<int, Invoice>, memos: Collection<int, CreditMemo>}
     */
    public function currentFor(Contact $contact): array
    {
        $invoices = Invoice::withoutGlobalScopes()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get();

        $memos = CreditMemo::withoutGlobalScopes()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get();

        return [
            'net' => (int) $invoices->sum('total_cents') - (int) $memos->sum('total_cents'),
            'invoices' => $invoices,
            'memos' => $memos,
        ];
    }

    /**
     * The number on the customer's single live opening document, when there is
     * exactly one — what the grid shows in its editable cell. Null when the
     * customer has no opening document, or several waiting to be consolidated.
     */
    public function documentNumberFor(Contact $contact): ?string
    {
        $current = $this->currentFor($contact);

        if ($current['invoices']->count() === 1 && $current['memos']->isEmpty()) {
            return (string) $current['invoices']->first()->invoice_no;
        }

        if ($current['memos']->count() === 1 && $current['invoices']->isEmpty()) {
            return (string) $current['memos']->first()->credit_memo_no;
        }

        return null;
    }

    /**
     * Make the customer's opening AR detail equal $signedCents. Posts, reposts
     * or voids immediately — each grid save is a real business event.
     *
     * $documentNumber names the resulting document (a new one, or the existing
     * one being reposted); null keeps whatever is there and generates a number
     * for anything new.
     */
    public function set(OpeningBalanceState $state, Contact $contact, int $signedCents, ?string $documentNumber = null): void
    {
        DB::transaction(function () use ($state, $contact, $signedCents, $documentNumber): void {
            $asOf = $state->asOf();
            $current = $this->currentFor($contact);
            $documentNumber = OpeningDocumentNumber::normalize($documentNumber);

            if ($current['net'] === $signedCents) {
                // Nothing to post — but the operator may still have renamed the
                // document that is already there.
                $this->renameDocument($contact, $documentNumber);

                return;
            }

            $voidDate = $state->company->isLockedFor($asOf) ? null : $asOf;

            // Simple amount change on the one live invoice: repost in place.
            if ($signedCents > 0 && $current['invoices']->count() === 1 && $current['memos']->isEmpty()) {
                $this->repostInvoiceAt($current['invoices']->first(), $signedCents, $documentNumber);

                return;
            }

            if ($signedCents < 0 && $current['memos']->count() === 1 && $current['invoices']->isEmpty()) {
                $this->repostMemoAt($current['memos']->first(), -$signedCents, $documentNumber);

                return;
            }

            // Everything else — sign flip, zero, or consolidating multiple
            // documents — voids what exists and creates one fresh document.
            foreach ($current['invoices'] as $invoice) {
                $this->guardUnsettled($invoice);
                $this->invoicePoster->void($invoice, $voidDate);
            }

            foreach ($current['memos'] as $memo) {
                $this->creditMemoPoster->void($memo, $voidDate);
            }

            if ($signedCents > 0) {
                $this->postOpeningInvoice->handle($contact, $signedCents, $asOf, $documentNumber);
            } elseif ($signedCents < 0) {
                $this->createOpeningCreditMemo($contact, -$signedCents, $asOf, $documentNumber);
            }
        });
    }

    /**
     * Renumber the customer's single live opening document in place, reposting
     * so the journal entry's memo tracks the new number.
     *
     * Returns false when there is nothing to rename — the caller (the grid)
     * holds the typed number until a balance creates the document.
     */
    public function renameDocument(Contact $contact, ?string $documentNumber): bool
    {
        $documentNumber = OpeningDocumentNumber::normalize($documentNumber);

        if ($documentNumber === null) {
            return false;
        }

        return DB::transaction(function () use ($contact, $documentNumber): bool {
            $current = $this->currentFor($contact);

            if ($current['invoices']->count() === 1 && $current['memos']->isEmpty()) {
                $this->renameInvoice($current['invoices']->first(), $documentNumber);

                return true;
            }

            if ($current['memos']->count() === 1 && $current['invoices']->isEmpty()) {
                $this->renameMemo($current['memos']->first(), $documentNumber);

                return true;
            }

            return false;
        });
    }

    protected function renameInvoice(Invoice $invoice, string $documentNumber): void
    {
        if ((string) $invoice->invoice_no === $documentNumber) {
            return;
        }

        $this->guardNumberFree(Invoice::class, 'invoice_no', $invoice, $documentNumber, 'Invoice');

        $invoice->forceFill(['invoice_no' => $documentNumber])->save();

        // The journal entry's memo quotes the document number.
        $this->invoicePoster->repost($invoice->refresh());
    }

    protected function renameMemo(CreditMemo $memo, string $documentNumber): void
    {
        if ((string) $memo->credit_memo_no === $documentNumber) {
            return;
        }

        $this->guardNumberFree(CreditMemo::class, 'credit_memo_no', $memo, $documentNumber, 'Credit memo');

        $memo->forceFill(['credit_memo_no' => $documentNumber])->save();

        $this->creditMemoPoster->repost($memo->refresh());
    }

    protected function repostInvoiceAt(Invoice $invoice, int $amountCents, ?string $documentNumber = null): void
    {
        if ((int) $invoice->total_cents === $amountCents) {
            $this->renameInvoice($invoice, $documentNumber ?? (string) $invoice->invoice_no);

            return;
        }

        $this->guardUnsettled($invoice);

        if ($documentNumber !== null && $documentNumber !== (string) $invoice->invoice_no) {
            $this->guardNumberFree(Invoice::class, 'invoice_no', $invoice, $documentNumber, 'Invoice');
            $invoice->forceFill(['invoice_no' => $documentNumber])->save();
        }

        $invoice->lines()->update([
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_total_cents' => $amountCents,
        ]);

        // The poster recalculates header totals from the lines and reposts the
        // same journal entry in place.
        $this->invoicePoster->repost($invoice->refresh());
    }

    protected function repostMemoAt(CreditMemo $memo, int $amountCents, ?string $documentNumber = null): void
    {
        if ((int) $memo->total_cents === $amountCents) {
            $this->renameMemo($memo, $documentNumber ?? (string) $memo->credit_memo_no);

            return;
        }

        if ($documentNumber !== null && $documentNumber !== (string) $memo->credit_memo_no) {
            $this->guardNumberFree(CreditMemo::class, 'credit_memo_no', $memo, $documentNumber, 'Credit memo');
            $memo->forceFill(['credit_memo_no' => $documentNumber])->save();
        }

        $memo->lines()->update([
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_total_cents' => $amountCents,
        ]);

        $this->creditMemoPoster->repost($memo->refresh());
    }

    protected function createOpeningCreditMemo(Contact $contact, int $amountCents, CarbonImmutable $asOf, ?string $documentNumber = null): CreditMemo
    {
        $company = $contact->company;
        $obe = $this->openingBalanceAccounts->resolveOrFail((int) $company->id);

        if (OpeningDocumentNumber::isTooLong($documentNumber)) {
            throw new RuntimeException('Credit memo number is too long — '.OpeningDocumentNumber::MAX_LENGTH.' characters maximum.');
        }

        if ($documentNumber !== null && $this->numbers->isTaken($company, CreditMemo::class, 'credit_memo_no', $documentNumber)) {
            throw new RuntimeException("Credit memo number {$documentNumber} is already in use.");
        }

        $memo = CreditMemo::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'credit_memo_no' => $documentNumber ?? $this->numbers->next($company, CreditMemo::class, 'credit_memo_no', 'OB'),
            'credit_memo_date' => $asOf,
            'status' => CreditMemoStatus::Draft,
            'subtotal_cents' => $amountCents,
            'tax_cents' => 0,
            'total_cents' => $amountCents,
            'currency_code' => $contact->currency_code,
            'memo' => 'Opening balance',
            'is_opening_balance' => true,
        ]);

        $memo->lines()->create([
            'account_id' => $obe->id,
            'description' => 'Opening balance',
            'quantity' => '1.0000',
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_tax_cents' => 0,
            'line_total_cents' => $amountCents,
            'line_order' => 0,
        ]);

        $this->creditMemoPoster->post($memo->fresh());

        return $memo->refresh();
    }

    /**
     * A typed document number has to clear the per-company unique index before
     * we write it, or the save surfaces as a raw SQL error.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function guardNumberFree(string $modelClass, string $column, Invoice|CreditMemo $document, string $number, string $label): void
    {
        if (OpeningDocumentNumber::isTooLong($number)) {
            throw new RuntimeException($label.' number is too long — '.OpeningDocumentNumber::MAX_LENGTH.' characters maximum.');
        }

        if ($this->numbers->isTaken($document->company, $modelClass, $column, $number, (int) $document->id)) {
            throw new RuntimeException("{$label} number {$number} is already in use.");
        }
    }

    /**
     * An opening invoice that already has receipts applied (or a GL
     * reconciliation) cannot be silently rewritten out from under them.
     */
    protected function guardUnsettled(Invoice $invoice): void
    {
        if ($invoice->settledCents() > 0) {
            throw new RuntimeException(
                "Opening invoice {$invoice->invoice_no} already has payments applied — adjust it from the invoice screen instead.",
            );
        }
    }
}
