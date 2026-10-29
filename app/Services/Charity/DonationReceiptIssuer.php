<?php

namespace App\Services\Charity;

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Charity\SaveDonationReceipt;
use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Enums\DonationReceiptStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Posting\JournalPoster;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues, voids, and reissues official donation receipts.
 *
 *  - issue():     draft → issued. Freezes the eligible amount, runs CRA validations,
 *                 and (for a stand-alone in-kind gift) posts DR asset/expense / CR
 *                 donation revenue at fair market value. Cash gifts post no GL (the
 *                 money is already booked elsewhere) to avoid double-counting revenue.
 *                 A receipt spawned from a donation never posts, in kind or not —
 *                 the donation booked the gift — and is issued only once that
 *                 donation is posted, for its figures as they then stand.
 *  - void():      issued → void. Retains the serial number (CRA requires voided
 *                 receipts be kept) and reverses any in-kind GL entry.
 *  - voidDraft(): draft → void, for a draft that must never be issued (its
 *                 donation was voided); the serial it took is kept the same way.
 *  - reissue():   voids the original and returns a fresh draft that references it,
 *                 so a corrected receipt carries a new serial linked to the cancelled
 *                 one. A spawned receipt's draft stays linked to the donation.
 *
 * {@see DonationReceiptSynchronizer} drives these from the donation's side.
 */
final class DonationReceiptIssuer
{
    /** CRA appraisal threshold for non-cash gifts: $1,000. */
    private const APPRAISAL_THRESHOLD_CENTS = 100_000;

    public function __construct(
        protected SaveJournalEntry $saveJournalEntry,
        protected JournalPoster $poster,
        protected SaveDonationReceipt $saver,
        protected AccountingAuditRecorder $audit,
    ) {}

    public function issue(DonationReceipt $receipt): DonationReceipt
    {
        if (! $receipt->isDraft()) {
            throw new InvalidArgumentException('Only draft donation receipts can be issued.');
        }

        if ($receipt->isLinkedToDonation()) {
            $this->assertDonationIsReceiptable($receipt);

            // Issue for the donation as it stands now, not as it stood when the
            // draft was last saved.
            $receipt = $this->saver->syncFromDonation($receipt);
        }

        $company = app('current_company');
        $eligible = $receipt->amount_cents - $receipt->advantage_cents;

        $this->assertReceiptIsIssuable($receipt, $eligible);

        return DB::transaction(function () use ($receipt, $company, $eligible): DonationReceipt {
            $entry = null;

            if ($receipt->postsInKindEntry()) {
                $revenueAccountId = $receipt->revenue_account_id ?? $this->defaultDonationAccountId($company);

                $entry = $this->saveJournalEntry->handle([
                    'entry_date' => $receipt->gift_date->toDateString(),
                    'memo' => 'In-kind donation receipt '.$receipt->receipt_no,
                    'lines' => [
                        ['account_id' => $receipt->debit_account_id, 'debit_cents' => $receipt->amount_cents, 'credit_cents' => 0],
                        ['account_id' => $revenueAccountId, 'debit_cents' => 0, 'credit_cents' => $receipt->amount_cents],
                    ],
                ]);
                $this->poster->post($entry);
            }

            $receipt->forceFill([
                'status' => DonationReceiptStatus::Issued,
                'issued_date' => $company->currentDateTime()->toDateString(),
                'eligible_amount_cents' => $eligible,
                'journal_entry_id' => $entry?->id,
                'issued_by_user_id' => Auth::id(),
            ])->save();

            $this->audit->record($company->id, AuditAction::DonationReceiptIssued, $receipt, [
                'receipt_no' => $receipt->receipt_no,
                'eligible_amount_cents' => $eligible,
                'gift_type' => $receipt->gift_type->value,
            ], $entry);

            return $receipt;
        });
    }

    public function void(DonationReceipt $receipt, string $reason): DonationReceipt
    {
        if (! $receipt->isIssued()) {
            throw new InvalidArgumentException('Only issued donation receipts can be voided.');
        }

        return DB::transaction(function () use ($receipt, $reason): DonationReceipt {
            if ($receipt->journal_entry_id !== null && $receipt->journalEntry !== null) {
                $this->poster->void($receipt->journalEntry);
            }

            $receipt->forceFill([
                'status' => DonationReceiptStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->record($receipt->company_id, AuditAction::DonationReceiptVoided, $receipt, [
                'receipt_no' => $receipt->receipt_no,
                'reason' => $reason,
            ]);

            return $receipt;
        });
    }

    /**
     * Draft → void, for a draft that must never be issued: its donation was
     * voided. The serial number was taken when the draft was saved, so it stays
     * on file as void like any cancelled receipt; nothing was posted to reverse.
     */
    public function voidDraft(DonationReceipt $receipt, string $reason): DonationReceipt
    {
        if (! $receipt->isDraft()) {
            throw new InvalidArgumentException('Only draft donation receipts can be voided as drafts.');
        }

        return DB::transaction(function () use ($receipt, $reason): DonationReceipt {
            $receipt->forceFill([
                'status' => DonationReceiptStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->record($receipt->company_id, AuditAction::DonationReceiptVoided, $receipt, [
                'receipt_no' => $receipt->receipt_no,
                'reason' => $reason,
                'was_draft' => true,
            ]);

            return $receipt;
        });
    }

    public function reissue(DonationReceipt $original, ?string $reason = null): DonationReceipt
    {
        if (! $original->isIssued()) {
            throw new InvalidArgumentException('Only issued donation receipts can be reissued.');
        }

        if ($original->isLinkedToDonation()) {
            $donation = $original->donation()->withTrashed()->first();

            if ($donation === null || $donation->trashed() || $donation->isVoid()) {
                throw new InvalidArgumentException(__('Donation :number was voided, so this receipt cannot be reissued. Void it instead.', [
                    'number' => $donation->donation_no ?? '#'.$original->donation_id,
                ]));
            }
        }

        return DB::transaction(function () use ($original, $reason): DonationReceipt {
            $this->void($original, $reason ?? 'Reissued as a corrected receipt.');

            // A receipt spawned from a donation stays linked to it, so the
            // corrected draft follows the donation and never posts either.
            $draft = $this->saver->handle(['donation_id' => $original->donation_id] + SaveDonationReceipt::dataFrom($original));

            $draft->forceFill(['reissued_from_id' => $original->id])->save();

            if ($original->isLinkedToDonation()) {
                Donation::query()
                    ->withTrashed()
                    ->whereKey($original->donation_id)
                    ->where(fn ($q) => $q->whereNull('donation_receipt_id')->orWhere('donation_receipt_id', $original->id))
                    ->update(['donation_receipt_id' => $draft->id]);
            }

            $this->audit->record($original->company_id, AuditAction::DonationReceiptReissued, $draft, [
                'reissued_from_receipt_no' => $original->receipt_no,
                'new_receipt_no' => $draft->receipt_no,
            ]);

            return $draft;
        });
    }

    /**
     * A receipt spawned from a donation states a gift the donation books, so it
     * is issued only once that donation is posted: a draft donation may still be
     * a pledge (the CRA allows no receipt for one), and a voided one was reversed.
     */
    protected function assertDonationIsReceiptable(DonationReceipt $receipt): void
    {
        $donation = $receipt->donation()->withTrashed()->first();

        if ($donation === null || $donation->trashed()) {
            throw new InvalidArgumentException(__('The donation this receipt was created from no longer exists, so the receipt cannot be issued.'));
        }

        if ($donation->isVoid()) {
            throw new InvalidArgumentException(__('Donation :number was voided, so its receipt cannot be issued.', ['number' => $donation->donation_no]));
        }

        if (! $donation->isPosted()) {
            throw new InvalidArgumentException(__('Post donation :number before issuing its receipt. A draft donation may still be a pledge, and an official receipt is only for a gift received.', [
                'number' => $donation->donation_no,
            ]));
        }
    }

    protected function assertReceiptIsIssuable(DonationReceipt $receipt, int $eligible): void
    {
        if ($eligible <= 0) {
            throw new InvalidArgumentException('The eligible amount must be greater than zero (the advantage cannot equal or exceed the gift).');
        }

        if ($receipt->hasAdvantage() && blank($receipt->advantage_description)) {
            throw new InvalidArgumentException('A description of the advantage is required when an advantage is present.');
        }

        if ($receipt->isInKind()) {
            if (blank($receipt->in_kind_description)) {
                throw new InvalidArgumentException('A description of the property is required for an in-kind receipt.');
            }

            if ($receipt->amount_cents >= self::APPRAISAL_THRESHOLD_CENTS
                && (blank($receipt->appraised_by) || $receipt->appraisal_date === null)) {
                throw new InvalidArgumentException('An appraisal (appraiser and date) is required for an in-kind gift over $1,000.');
            }
        }
    }

    protected function defaultDonationAccountId(Company $company): int
    {
        $account = Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '4000')->first()
            ?? Account::withoutGlobalScopes()->where('company_id', $company->id)->where('type', AccountType::Income->value)->orderBy('code')->first();

        if ($account === null) {
            throw new InvalidArgumentException('No donation income account is available to post the in-kind gift.');
        }

        return $account->id;
    }
}
