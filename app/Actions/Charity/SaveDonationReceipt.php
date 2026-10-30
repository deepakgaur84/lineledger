<?php

namespace App\Actions\Charity;

use App\Enums\GiftType;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Services\Charity\DonationReceiptSynchronizer;
use App\Services\Posting\DocumentNumberGenerator;
use InvalidArgumentException;

/**
 * Creates or updates a DRAFT donation receipt. Generates the serial number on
 * create, snapshots the donor from the contact when one is chosen, and freezes
 * the eligible amount (= fair market value − advantage). Issued/void receipts are
 * immutable — only drafts may be edited. A cash receipt carries no in-kind details.
 *
 * A receipt linked to a donation (`donation_id`: given on create by the donation's
 * spawn or a reissue, never changed after) follows the donation, whatever $data
 * says: its donor, gift type, gift date, amount and revenue account are the
 * donation's, and it never carries a debit account — the donation books the
 * gift, so issuing the receipt must not post it again (no double count). See
 * {@see DonationReceiptSynchronizer}.
 */
final class SaveDonationReceipt
{
    private const DONOR_SNAPSHOT = ['donor_name', 'donor_line1', 'donor_line2', 'donor_city', 'donor_region', 'donor_postal_code', 'donor_country'];

    public function __construct(protected DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?DonationReceipt $receipt = null): DonationReceipt
    {
        if ($receipt && $receipt->exists && ! $receipt->isDraft()) {
            throw new InvalidArgumentException('Only draft donation receipts can be edited.');
        }

        $company = app('current_company');

        $existing = $receipt !== null && $receipt->exists;
        $donationId = $existing ? $receipt->donation_id : ($data['donation_id'] ?? null);
        $donation = $donationId !== null ? Donation::query()->withTrashed()->find($donationId) : null;

        if (! $existing && $donationId !== null && $donation === null) {
            throw new InvalidArgumentException('The donation for this receipt was not found.');
        }

        if ($donation !== null) {
            // A different donor needs a fresh name-and-address snapshot.
            if (self::id($data['contact_id'] ?? null) !== self::id($donation->contact_id)) {
                $data = array_diff_key($data, array_flip(self::DONOR_SNAPSHOT));
            }

            $data = array_merge($data, $donation->receiptFields());
        }

        if ($donationId !== null) {
            $data['debit_account_id'] = null;
        }

        $giftType = $data['gift_type'] ?? GiftType::Cash->value;
        $inKind = ($giftType instanceof GiftType ? $giftType : GiftType::tryFrom((string) $giftType)) === GiftType::InKind;

        $amount = (int) $data['amount_cents'];
        $advantage = (int) ($data['advantage_cents'] ?? 0);

        $attributes = [
            'contact_id' => $data['contact_id'] ?? null,
            'gift_type' => $giftType,
            'gift_date' => $data['gift_date'],
            'amount_cents' => $amount,
            'advantage_cents' => $advantage,
            'eligible_amount_cents' => $amount - $advantage,
            'advantage_description' => $data['advantage_description'] ?? null,
            'in_kind_description' => $inKind ? ($data['in_kind_description'] ?? null) : null,
            'appraised_by' => $inKind ? ($data['appraised_by'] ?? null) : null,
            'appraisal_date' => $inKind ? ($data['appraisal_date'] ?? null) : null,
            'currency_code' => $data['currency_code'] ?? null,
            'revenue_account_id' => $data['revenue_account_id'] ?? null,
            'debit_account_id' => $inKind ? ($data['debit_account_id'] ?? null) : null,
            'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ] + $this->donorSnapshot($data);

        if ($existing) {
            $receipt->update($attributes);

            return $receipt;
        }

        return DonationReceipt::create($attributes + [
            'receipt_no' => $this->numbers->next($company, DonationReceipt::class, 'receipt_no', 'DR'),
            'status' => 'draft',
            'donation_id' => $donation?->id,
        ]);
    }

    /**
     * Re-save a draft from its own details, so a receipt linked to a donation
     * picks up the donation's current donor, gift type, gift date and amount.
     */
    public function syncFromDonation(DonationReceipt $receipt): DonationReceipt
    {
        return $this->handle(self::dataFrom($receipt), $receipt);
    }

    /**
     * A receipt's details as save data — what a re-save or a reissue carries
     * over, donor snapshot included.
     *
     * @return array<string, mixed>
     */
    public static function dataFrom(DonationReceipt $receipt): array
    {
        return [
            'contact_id' => $receipt->contact_id,
            'gift_type' => $receipt->gift_type->value,
            'gift_date' => $receipt->gift_date->toDateString(),
            'donor_name' => $receipt->donor_name,
            'donor_line1' => $receipt->donor_line1,
            'donor_line2' => $receipt->donor_line2,
            'donor_city' => $receipt->donor_city,
            'donor_region' => $receipt->donor_region,
            'donor_postal_code' => $receipt->donor_postal_code,
            'donor_country' => $receipt->donor_country,
            'amount_cents' => $receipt->amount_cents,
            'advantage_cents' => $receipt->advantage_cents,
            'advantage_description' => $receipt->advantage_description,
            'in_kind_description' => $receipt->in_kind_description,
            'appraised_by' => $receipt->appraised_by,
            'appraisal_date' => $receipt->appraisal_date?->toDateString(),
            'currency_code' => $receipt->currency_code,
            'revenue_account_id' => $receipt->revenue_account_id,
            'debit_account_id' => $receipt->debit_account_id,
            'customer_receipt_id' => $receipt->customer_receipt_id,
            'notes' => $receipt->notes,
        ];
    }

    /**
     * Build the donor name/address snapshot, defaulting from the linked contact
     * when explicit values aren't supplied.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, ?string>
     */
    protected function donorSnapshot(array $data): array
    {
        $contact = isset($data['contact_id']) && $data['contact_id'] !== null
            ? Contact::query()->find($data['contact_id'])
            : null;

        return [
            'donor_name' => $data['donor_name'] ?? $contact?->display_name ?? '',
            'donor_line1' => $data['donor_line1'] ?? $contact?->billing_line1,
            'donor_line2' => $data['donor_line2'] ?? $contact?->billing_line2,
            'donor_city' => $data['donor_city'] ?? $contact?->billing_city,
            'donor_region' => $data['donor_region'] ?? $contact?->billing_region,
            'donor_postal_code' => $data['donor_postal_code'] ?? $contact?->billing_postal_code,
            'donor_country' => $data['donor_country'] ?? $contact?->billing_country,
        ];
    }

    private static function id(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
