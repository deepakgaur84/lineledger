<?php

namespace App\Actions\Banking;

use App\Enums\DepositStatus;
use App\Models\Deposit;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a deposit slip header and its lines, recalculating the
 * deposit amount. Shared by the Livewire form and the API. Does NOT post —
 * the caller decides whether to hand the result to DepositPoster.
 *
 * Editing a posted deposit rebuilds its lines here, then the caller hands the
 * deposit to DepositPoster::repost() to rebuild the GL entry in place.
 *
 * Each line is one of two kinds:
 *   - receipt-source: { customer_receipt_id | sales_receipt_id: int, amount_cents?: int, description?: string }
 *       (amount_cents defaults to the receipt's amount when omitted, and must equal it when given)
 *   - other:          { account_id: int, contact_id?: int, amount_cents: int, description?: string }
 *
 * Receipt lines are checked before anything is written
 * (DepositPoster::ensureReceiptLinesDepositable()): each must be a posted
 * receipt of this organization, received into Undeposited Funds, on no other
 * deposit that hasn't been voided, and deposited for its own amount.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   bank_account_id: int
 *   deposit_no:      ?string  (null → auto-generated, DEP prefix)
 *   deposit_date:    string
 *   memo:            ?string
 *   lines: array<int, array<string, mixed>>
 */
final class SaveDeposit
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected DepositPoster $poster,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Deposit $deposit = null): Deposit
    {
        return DB::transaction(function () use ($data, $deposit): Deposit {
            $company = app('current_company');

            // Before anything is written, and with the receipts row-locked: each
            // receipt line must be one Undeposited Funds still holds, for its own
            // amount — whatever the form or the API sent.
            $receipts = $this->poster->ensureReceiptLinesDepositable((int) $company->id, $data['lines'], $deposit);

            $header = [
                'bank_account_id' => $data['bank_account_id'],
                'deposit_date' => $data['deposit_date'],
                'memo' => $data['memo'] ?? null,
            ];

            // The number is user-editable, so a correction has to reach an existing
            // deposit too — not just the create below. filled() keeps a partial update
            // that omits the key from blanking the current number.
            if (filled($data['deposit_no'] ?? null)) {
                $header['deposit_no'] = $data['deposit_no'];
            }

            if ($deposit && $deposit->exists) {
                $deposit->update($header);
            } else {
                $deposit = Deposit::create($header + [
                    'deposit_no' => $data['deposit_no']
                        ?? $this->numbers->next($company, Deposit::class, 'deposit_no', 'DEP'),
                    'status' => DepositStatus::Draft,
                ]);
            }

            $deposit->lines()->delete();

            foreach (array_values($data['lines']) as $index => $line) {
                if (! empty($line['customer_receipt_id'])) {
                    // Found, posted, undeposited and for this amount: checked above.
                    $receipt = $receipts['customer']->findOrFail((int) $line['customer_receipt_id']);

                    $deposit->lines()->create([
                        'customer_receipt_id' => $receipt->id,
                        'description' => $line['description'] ?? 'Receipt '.$receipt->receipt_no,
                        'amount_cents' => (int) $receipt->amount_cents,
                        'line_order' => $index,
                    ]);

                    continue;
                }

                if (! empty($line['sales_receipt_id'])) {
                    $salesReceipt = $receipts['sales']->findOrFail((int) $line['sales_receipt_id']);

                    $deposit->lines()->create([
                        'sales_receipt_id' => $salesReceipt->id,
                        'description' => $line['description'] ?? 'Sales receipt '.$salesReceipt->sales_receipt_no,
                        'amount_cents' => (int) $salesReceipt->total_cents,
                        'line_order' => $index,
                    ]);

                    continue;
                }

                $deposit->lines()->create([
                    'account_id' => $line['account_id'],
                    'contact_id' => $line['contact_id'] ?? null,
                    'description' => $line['description'] ?? null,
                    'amount_cents' => (int) $line['amount_cents'],
                    'line_order' => $index,
                    'class_id' => $line['class_id'] ?? null,
                    'location_id' => $line['location_id'] ?? null,
                ]);
            }

            $deposit->refresh();
            $deposit->recalculateAmount();

            return $deposit;
        });
    }
}
