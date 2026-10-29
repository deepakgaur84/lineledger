<?php

namespace App\Services\Charity;

use App\Actions\Charity\SaveDonationReceipt;
use App\Models\Donation;
use App\Models\DonationReceipt;

/**
 * Keeps the official receipt spawned from a donation in step with it.
 *
 * The donation owns the gift — donor, gift type, gift date and amount (fair
 * market value) — and books it to the GL. The receipt adds only what the CRA asks
 * of the receipt itself (advantage, property description, appraisal) and never
 * posts. CRA receipting rules, as the app models them: a draft may change, an
 * issued receipt never does — it is cancelled (void, serial kept on file) and a
 * corrected one is issued in its place.
 *
 *  - Donation saved (always a draft): a draft receipt is re-saved with the
 *    donation's figures. An issued receipt whose donor, gift type, date or amount
 *    no longer match is voided and replaced by a corrected draft
 *    ({@see DonationReceiptIssuer::reissue()}), which the donation then points
 *    at. A void receipt is left alone.
 *  - Donation voided: the gift was reversed, so its receipt is voided too — an
 *    issued one through {@see DonationReceiptIssuer::void()} (any entry it posted
 *    is reversed), a draft so it can never be issued.
 *  - Receipt issued: {@see DonationReceiptIssuer::issue()} refuses until the
 *    donation is posted, re-reads its figures, and posts nothing.
 *
 * Runs inside the caller's transaction. Callers own edit locks.
 */
final class DonationReceiptSynchronizer
{
    public function __construct(
        private SaveDonationReceipt $saver,
        private DonationReceiptIssuer $issuer,
    ) {}

    public function donationSaved(Donation $donation): void
    {
        $receipt = $this->receiptOf($donation);

        if ($receipt === null || $receipt->isVoid()) {
            return;
        }

        if ($receipt->isDraft()) {
            $this->saver->syncFromDonation($receipt);

            return;
        }

        if ($this->statesTheGift($receipt, $donation)) {
            return;
        }

        $this->issuer->reissue($receipt, __('Donation :number was changed after this receipt was issued.', [
            'number' => $donation->donation_no,
        ]));

        $donation->refresh();
    }

    public function donationVoided(Donation $donation): void
    {
        $receipt = $this->receiptOf($donation);

        if ($receipt === null || $receipt->isVoid()) {
            return;
        }

        $reason = __('Donation :number was voided.', ['number' => $donation->donation_no]);

        $receipt->isIssued()
            ? $this->issuer->void($receipt, $reason)
            : $this->issuer->voidDraft($receipt, $reason);
    }

    private function receiptOf(Donation $donation): ?DonationReceipt
    {
        return $donation->donation_receipt_id === null
            ? null
            : DonationReceipt::query()->find($donation->donation_receipt_id);
    }

    /**
     * Whether the issued receipt still states the gift as the donation now records
     * it — the donor, gift type, gift date and amount printed on it.
     */
    private function statesTheGift(DonationReceipt $receipt, Donation $donation): bool
    {
        $gift = $donation->receiptFields();

        return $receipt->amount_cents === $gift['amount_cents']
            && $receipt->gift_type->value === $gift['gift_type']
            && $receipt->gift_date->toDateString() === $gift['gift_date']
            && (int) $receipt->contact_id === (int) $gift['contact_id'];
    }
}
