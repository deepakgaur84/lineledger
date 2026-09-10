<?php

namespace App\Services\OpeningBalances;

use App\Actions\Purchasing\PostVendorOpeningBalance;
use App\Enums\VendorCreditStatus;
use App\Models\Bill;
use App\Models\Contact;
use App\Models\OpeningBalanceState;
use App\Models\VendorCredit;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Posting\BillPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\VendorCreditPoster;
use App\Support\OpeningBalances\OpeningDocumentNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The vendor mirror of {@see CustomerOpeningBalanceSync}: a positive net
 * balance is one opening-balance bill (DR OBE / CR AP), a negative one an
 * opening vendor credit (DR AP / CR OBE). Amounts are in the vendor's
 * currency.
 *
 * As on the AR side, the document number is the operator's to choose — pass one
 * to {@see set()} or call {@see renameDocument()}; null generates one.
 */
class VendorOpeningBalanceSync
{
    public function __construct(
        protected PostVendorOpeningBalance $postOpeningBill,
        protected BillPoster $billPoster,
        protected VendorCreditPoster $vendorCreditPoster,
        protected DocumentNumberGenerator $numbers,
        protected OpeningBalanceAccountResolver $openingBalanceAccounts,
    ) {}

    /**
     * @return array{net: int, bills: Collection<int, Bill>, credits: Collection<int, VendorCredit>}
     */
    public function currentFor(Contact $contact): array
    {
        $bills = Bill::withoutGlobalScopes()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get();

        $credits = VendorCredit::withoutGlobalScopes()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get();

        return [
            'net' => (int) $bills->sum('total_cents') - (int) $credits->sum('total_cents'),
            'bills' => $bills,
            'credits' => $credits,
        ];
    }

    /**
     * The number on the vendor's single live opening document, when there is
     * exactly one. Null with none, or several waiting to be consolidated.
     */
    public function documentNumberFor(Contact $contact): ?string
    {
        $current = $this->currentFor($contact);

        if ($current['bills']->count() === 1 && $current['credits']->isEmpty()) {
            return (string) $current['bills']->first()->bill_no;
        }

        if ($current['credits']->count() === 1 && $current['bills']->isEmpty()) {
            return (string) $current['credits']->first()->vendor_credit_no;
        }

        return null;
    }

    /**
     * Make the vendor's opening AP detail equal $signedCents (positive = we owe).
     *
     * $documentNumber names the resulting document; null keeps whatever is
     * there and generates a number for anything new.
     */
    public function set(OpeningBalanceState $state, Contact $contact, int $signedCents, ?string $documentNumber = null): void
    {
        DB::transaction(function () use ($state, $contact, $signedCents, $documentNumber): void {
            $asOf = $state->asOf();
            $current = $this->currentFor($contact);
            $documentNumber = OpeningDocumentNumber::normalize($documentNumber);

            if ($current['net'] === $signedCents) {
                $this->renameDocument($contact, $documentNumber);

                return;
            }

            $voidDate = $state->company->isLockedFor($asOf) ? null : $asOf;

            if ($signedCents > 0 && $current['bills']->count() === 1 && $current['credits']->isEmpty()) {
                $this->repostBillAt($current['bills']->first(), $signedCents, $documentNumber);

                return;
            }

            if ($signedCents < 0 && $current['credits']->count() === 1 && $current['bills']->isEmpty()) {
                $this->repostCreditAt($current['credits']->first(), -$signedCents, $documentNumber);

                return;
            }

            foreach ($current['bills'] as $bill) {
                $this->guardUnsettled($bill);
                $this->billPoster->void($bill, $voidDate);
            }

            foreach ($current['credits'] as $credit) {
                $this->vendorCreditPoster->void($credit, $voidDate);
            }

            if ($signedCents > 0) {
                $this->postOpeningBill->handle($contact, $signedCents, $asOf, $documentNumber);
            } elseif ($signedCents < 0) {
                $this->createOpeningVendorCredit($contact, -$signedCents, $asOf, $documentNumber);
            }
        });
    }

    /**
     * Renumber the vendor's single live opening document in place, reposting so
     * the journal entry's memo tracks the new number. False when there is
     * nothing to rename yet.
     */
    public function renameDocument(Contact $contact, ?string $documentNumber): bool
    {
        $documentNumber = OpeningDocumentNumber::normalize($documentNumber);

        if ($documentNumber === null) {
            return false;
        }

        return DB::transaction(function () use ($contact, $documentNumber): bool {
            $current = $this->currentFor($contact);

            if ($current['bills']->count() === 1 && $current['credits']->isEmpty()) {
                $this->renameBill($current['bills']->first(), $documentNumber);

                return true;
            }

            if ($current['credits']->count() === 1 && $current['bills']->isEmpty()) {
                $this->renameCredit($current['credits']->first(), $documentNumber);

                return true;
            }

            return false;
        });
    }

    protected function renameBill(Bill $bill, string $documentNumber): void
    {
        if ((string) $bill->bill_no === $documentNumber) {
            return;
        }

        $this->guardNumberFree(Bill::class, 'bill_no', $bill, $documentNumber, 'Bill');

        $bill->forceFill(['bill_no' => $documentNumber])->save();

        $this->billPoster->repost($bill->refresh());
    }

    protected function renameCredit(VendorCredit $credit, string $documentNumber): void
    {
        if ((string) $credit->vendor_credit_no === $documentNumber) {
            return;
        }

        $this->guardNumberFree(VendorCredit::class, 'vendor_credit_no', $credit, $documentNumber, 'Vendor credit');

        $credit->forceFill(['vendor_credit_no' => $documentNumber])->save();

        $this->vendorCreditPoster->repost($credit->refresh());
    }

    /**
     * A typed document number has to clear the per-company unique index before
     * we write it, or the save surfaces as a raw SQL error.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function guardNumberFree(string $modelClass, string $column, Bill|VendorCredit $document, string $number, string $label): void
    {
        if (OpeningDocumentNumber::isTooLong($number)) {
            throw new RuntimeException($label.' number is too long — '.OpeningDocumentNumber::MAX_LENGTH.' characters maximum.');
        }

        if ($this->numbers->isTaken($document->company, $modelClass, $column, $number, (int) $document->id)) {
            throw new RuntimeException("{$label} number {$number} is already in use.");
        }
    }

    protected function repostBillAt(Bill $bill, int $amountCents, ?string $documentNumber = null): void
    {
        if ((int) $bill->total_cents === $amountCents) {
            $this->renameBill($bill, $documentNumber ?? (string) $bill->bill_no);

            return;
        }

        $this->guardUnsettled($bill);

        if ($documentNumber !== null && $documentNumber !== (string) $bill->bill_no) {
            $this->guardNumberFree(Bill::class, 'bill_no', $bill, $documentNumber, 'Bill');
            $bill->forceFill(['bill_no' => $documentNumber])->save();
        }

        $bill->lines()->update([
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_total_cents' => $amountCents,
        ]);

        $this->billPoster->repost($bill->refresh());
    }

    protected function repostCreditAt(VendorCredit $credit, int $amountCents, ?string $documentNumber = null): void
    {
        if ((int) $credit->total_cents === $amountCents) {
            $this->renameCredit($credit, $documentNumber ?? (string) $credit->vendor_credit_no);

            return;
        }

        if ($documentNumber !== null && $documentNumber !== (string) $credit->vendor_credit_no) {
            $this->guardNumberFree(VendorCredit::class, 'vendor_credit_no', $credit, $documentNumber, 'Vendor credit');
            $credit->forceFill(['vendor_credit_no' => $documentNumber])->save();
        }

        $credit->lines()->update([
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_total_cents' => $amountCents,
        ]);

        $this->vendorCreditPoster->repost($credit->refresh());
    }

    protected function createOpeningVendorCredit(Contact $contact, int $amountCents, CarbonImmutable $asOf, ?string $documentNumber = null): VendorCredit
    {
        $company = $contact->company;
        $obe = $this->openingBalanceAccounts->resolveOrFail((int) $company->id);

        if (OpeningDocumentNumber::isTooLong($documentNumber)) {
            throw new RuntimeException('Vendor credit number is too long — '.OpeningDocumentNumber::MAX_LENGTH.' characters maximum.');
        }

        if ($documentNumber !== null && $this->numbers->isTaken($company, VendorCredit::class, 'vendor_credit_no', $documentNumber)) {
            throw new RuntimeException("Vendor credit number {$documentNumber} is already in use.");
        }

        $credit = VendorCredit::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'vendor_credit_no' => $documentNumber ?? $this->numbers->next($company, VendorCredit::class, 'vendor_credit_no', 'OB'),
            'vendor_credit_date' => $asOf,
            'status' => VendorCreditStatus::Draft,
            'subtotal_cents' => $amountCents,
            'tax_cents' => 0,
            'total_cents' => $amountCents,
            'currency_code' => $contact->currency_code,
            'memo' => 'Opening balance',
            'is_opening_balance' => true,
        ]);

        $credit->lines()->create([
            'account_id' => $obe->id,
            'description' => 'Opening balance',
            'quantity' => '1.0000',
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_tax_cents' => 0,
            'line_total_cents' => $amountCents,
            'line_order' => 0,
        ]);

        $this->vendorCreditPoster->post($credit->fresh());

        return $credit->refresh();
    }

    protected function guardUnsettled(Bill $bill): void
    {
        if ((int) $bill->amount_paid_cents + (int) ($bill->reconciled_cents ?? 0) > 0) {
            throw new RuntimeException(
                "Opening bill {$bill->bill_no} already has payments applied — adjust it from the bill screen instead.",
            );
        }
    }
}
