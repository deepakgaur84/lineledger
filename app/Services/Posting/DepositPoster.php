<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\DepositStatus;
use App\Enums\ReceiptStatus;
use App\Enums\SalesReceiptStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\PostingValidationException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\DepositLine;
use App\Models\JournalEntry;
use App\Models\SalesReceipt;
use App\Scopes\CompanyScope;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Currency\ExchangeRateService;
use App\Services\Reconciliation\BankReconciliationLockGuard;
use App\Support\Banking\BankLineMemo;
use App\Support\Currency;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a deposit slip to the GL.
 *   DR  Bank account (total deposit)
 *   CR    Undeposited Funds (for receipt-source lines — moves $ from Undep → Bank)
 *   CR    Other account (for "other" deposit lines — e.g. owner contribution, refund received)
 *
 * A negative line flips its leg: a card refund (a negative receipt) debits
 * Undeposited Funds, and a merchant fee debits its expense, so only the net
 * hits the bank. When refunds outweigh the receipts — a card batch that settled
 * negative, or a refund on a day with no sales — the total is negative and the
 * bank is CREDITED: money out, matching the debit on the bank statement. Only a
 * zero total is refused.
 *
 * Receipt-source lines mark the underlying CustomerReceipt as deposited
 * by linking through deposit_lines.customer_receipt_id, so they won't
 * appear in subsequent deposit pickers.
 */
class DepositPoster
{
    use Concerns\PlugsForeignRounding;

    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected ExchangeRateService $exchangeRates,
        protected BankReconciliationLockGuard $reconciliationLockGuard,
    ) {}

    public function post(Deposit $deposit): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($deposit) {
            $deposit->loadMissing('lines.customerReceipt', 'lines.salesReceipt', 'bankAccount', 'company');

            if ($deposit->journal_entry_id) {
                throw AlreadyPostedException::for((int) $deposit->journal_entry_id);
            }

            if ($deposit->company->isLockedFor(CarbonImmutable::parse($deposit->deposit_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($deposit->deposit_date),
                    CarbonImmutable::parse($deposit->company->lock_date),
                );
            }

            $this->ensureReceiptLinesDepositable((int) $deposit->company_id, $deposit->lines, $deposit);

            $deposit->recalculateAmount();

            if ($deposit->lines->isEmpty() || (int) $deposit->amount_cents === 0) {
                throw new PostingValidationException(__('A deposit needs at least one line and a total other than zero.'));
            }

            $undeposited = $this->undepositedFundsAccount($deposit);

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($deposit->company),
                'entry_date' => $deposit->deposit_date,
                'memo' => 'Deposit '.$deposit->deposit_no,
                'source_type' => Deposit::class,
                'source_id' => $deposit->id,
            ]);

            $this->buildDepositLines($deposit, $entry, $undeposited);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $deposit->forceFill([
                'status' => DepositStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $deposit->company_id,
                AuditAction::DepositPosted,
                $deposit,
                [
                    'deposit_no' => $deposit->deposit_no,
                    'deposit_date' => optional($deposit->deposit_date)->toDateString(),
                    'amount_cents' => (int) $deposit->amount_cents,
                    'bank_account_id' => (int) $deposit->bank_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Edit a posted deposit in place: rebuild its GL entry's lines on the same
     * journal entry (keeping the source link and deposit.journal_entry_id intact),
     * then recompute every touched account balance.
     *
     * Use this when the user edits a posted deposit without the void+recreate
     * ceremony. Because a deposit moves money in and out of the bank account, the
     * rebuild is refused if a completed reconciliation has ticked one of its lines.
     * JournalPoster::post() is not re-run, so the lock date is enforced explicitly
     * here on both the original and the (possibly new) deposit date.
     */
    public function repost(Deposit $deposit): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($deposit) {
            $deposit->loadMissing('lines.customerReceipt', 'lines.salesReceipt', 'bankAccount', 'company', 'journalEntry.lines');

            if (! $deposit->journal_entry_id) {
                throw new RuntimeException('Deposit has not been posted yet — call post() instead.');
            }

            if ($deposit->status === DepositStatus::Void) {
                throw new RuntimeException('Cannot repost a voided deposit.');
            }

            $entry = $deposit->journalEntry;
            $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);
            $lockDate = $deposit->company->lock_date;

            $originalEntryDate = CarbonImmutable::parse($entry->entry_date);
            $newEntryDate = CarbonImmutable::parse($deposit->deposit_date);

            foreach ([$originalEntryDate, $newEntryDate] as $date) {
                if ($deposit->company->isLockedFor($date)) {
                    throw PeriodLockedException::for($date, CarbonImmutable::parse($lockDate));
                }
            }

            $deposit->recalculateAmount();

            if ($deposit->lines->isEmpty() || (int) $deposit->amount_cents === 0) {
                throw new PostingValidationException(__('A deposit needs at least one line and a total other than zero.'));
            }

            $undeposited = $this->undepositedFundsAccount($deposit);

            // The rebuild deletes and rewrites every line, so refuse it if a
            // completed reconciliation has claimed one of them.
            $this->reconciliationLockGuard->ensureLinesNotReconciled((int) $deposit->company_id, $entry->lines);

            $oldAccountIds = $entry->lines->pluck('account_id')->all();

            $entry->forceFill([
                'entry_date' => $deposit->deposit_date,
                'memo' => 'Deposit '.$deposit->deposit_no,
            ])->save();

            $entry->lines()->delete();

            $this->buildDepositLines($deposit, $entry, $undeposited);

            $entry->refresh();

            if (! $entry->isBalanced()) {
                throw UnbalancedJournalException::from($entry->totalDebitsCents(), $entry->totalCreditsCents());
            }

            foreach (array_unique([...$oldAccountIds, ...$entry->lines->pluck('account_id')->all()]) as $id) {
                Account::withoutGlobalScopes()->find($id)?->recomputeBalance();
            }

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $deposit->company_id,
                AuditAction::DepositReposted,
                $deposit,
                [
                    'deposit_no' => $deposit->deposit_no,
                    'deposit_date' => optional($deposit->deposit_date)->toDateString(),
                    'amount_cents' => (int) $deposit->amount_cents,
                    'bank_account_id' => (int) $deposit->bank_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal_before' => $journalBefore,
                    'journal_after' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Refuse receipt lines that would take out of Undeposited Funds money it
     * never held. Each must name a posted receipt (or pay-now sales receipt) of
     * this organization that was received into Undeposited Funds, appears once,
     * isn't on another deposit that hasn't been voided, and is deposited for
     * exactly its own amount — so a receipt can't be banked twice, by a retried
     * request or by two people at the deposit screen, nor for an edited figure.
     * The receipts are row-locked first, so a second deposit of the same one
     * waits for this one and then finds it taken. Used by SaveDeposit before
     * anything is written, and by post() for a draft saved earlier. Returns the
     * receipts the lines name.
     *
     * @param  iterable<array<string, mixed>|DepositLine>  $lines
     * @return array{customer: Collection<int, CustomerReceipt>, sales: Collection<int, SalesReceipt>}
     *
     * @throws PostingValidationException
     */
    public function ensureReceiptLinesDepositable(int $companyId, iterable $lines, ?Deposit $deposit = null): array
    {
        $sources = [];

        foreach ($lines as $line) {
            if (! empty($line['customer_receipt_id'])) {
                $sources[] = ['customer', (int) $line['customer_receipt_id'], $line['amount_cents'] ?? null];
            } elseif (! empty($line['sales_receipt_id'])) {
                $sources[] = ['sales', (int) $line['sales_receipt_id'], $line['amount_cents'] ?? null];
            }
        }

        if ($sources === []) {
            return ['customer' => new Collection, 'sales' => new Collection];
        }

        $ids = fn (string $kind): array => array_values(array_unique(array_map(
            fn (array $source): int => $source[1],
            array_filter($sources, fn (array $source): bool => $source[0] === $kind),
        )));

        // Row locks first: a second deposit of the same receipt, from a retried
        // request or someone else at the deposit screen, waits here for this one
        // and then finds the receipt taken.
        $receipts = [
            'customer' => CustomerReceipt::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->whereKey($ids('customer'))
                ->lockForUpdate()
                ->get(),
            'sales' => SalesReceipt::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->whereKey($ids('sales'))
                ->lockForUpdate()
                ->get(),
        ];

        $undepositedIds = Account::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('subtype', AccountSubtype::UndepositedFunds->value)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $held = [
            'customer' => $this->depositsHolding('customer_receipt_id', $companyId, $ids('customer'), $deposit),
            'sales' => $this->depositsHolding('sales_receipt_id', $companyId, $ids('sales'), $deposit),
        ];

        $seen = [];

        foreach ($sources as [$kind, $id, $amountCents]) {
            $receipt = $receipts[$kind]->find($id);

            if ($receipt === null) {
                throw new PostingValidationException(__('That receipt could not be found.'));
            }

            $isPayment = $receipt instanceof CustomerReceipt;
            $no = ['no' => $isPayment ? $receipt->receipt_no : $receipt->sales_receipt_no];

            if (isset($seen[$kind][$id])) {
                throw new PostingValidationException(__('Receipt :no appears on this deposit more than once.', $no));
            }

            $seen[$kind][$id] = true;

            $posted = $isPayment
                ? $receipt->status === ReceiptStatus::Posted
                : $receipt->status === SalesReceiptStatus::Posted;

            if (! $posted) {
                throw new PostingValidationException(__('Receipt :no is not posted, so it can\'t be deposited.', $no));
            }

            if (! in_array((int) $receipt->deposit_to_account_id, $undepositedIds, true)) {
                throw new PostingValidationException(__('Receipt :no was not received into Undeposited Funds, so it can\'t be deposited from there.', $no));
            }

            if (isset($held[$kind][$id])) {
                throw new PostingValidationException(__('Receipt :no is already on deposit :deposit.', [...$no, 'deposit' => $held[$kind][$id]]));
            }

            $receiptCents = (int) ($isPayment ? $receipt->amount_cents : $receipt->total_cents);

            if ($amountCents !== null && (int) $amountCents !== $receiptCents) {
                throw new PostingValidationException(__('Receipt :no is for :amount, so it must be deposited for that amount.', [
                    ...$no,
                    'amount' => Money::fromCents($receiptCents)->toDecimalString(),
                ]));
            }
        }

        return $receipts;
    }

    /**
     * The number of the deposit (other than $deposit, and not voided) each of
     * these receipts is already on, keyed by receipt id.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function depositsHolding(string $column, int $companyId, array $ids, ?Deposit $deposit): array
    {
        if ($ids === []) {
            return [];
        }

        $ownId = $deposit?->exists ? (int) $deposit->id : null;

        return DB::table('deposit_lines as dl')
            ->join('deposits as d', 'd.id', '=', 'dl.deposit_id')
            ->where('d.company_id', $companyId)
            ->where('d.status', '!=', DepositStatus::Void->value)
            ->whereNull('d.deleted_at')
            ->when($ownId !== null, fn ($query) => $query->where('d.id', '!=', $ownId))
            ->whereIn('dl.'.$column, $ids)
            ->pluck('d.deposit_no', 'dl.'.$column)
            ->mapWithKeys(fn ($no, $id) => [(int) $id => (string) $no])
            ->all();
    }

    public function void(Deposit $deposit, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($deposit, $voidDate) {
            $deposit->loadMissing('journalEntry');

            if (! $deposit->journal_entry_id) {
                throw new RuntimeException('Deposit is not posted.');
            }

            if ($deposit->status === DepositStatus::Void) {
                throw new RuntimeException('Deposit is already voided.');
            }

            $this->journalPoster->void($deposit->journalEntry, $voidDate, "Void of deposit {$deposit->deposit_no}");

            $deposit->forceFill([
                'status' => DepositStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $this->auditRecorder->record(
                (int) $deposit->company_id,
                AuditAction::DepositVoided,
                $deposit,
                [
                    'deposit_no' => $deposit->deposit_no,
                    'voided_at' => optional($deposit->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $deposit->journal_entry_id,
                ],
                $deposit->journalEntry,
            );
        }));
    }

    /**
     * Debit the bank for the deposit total (credit it when the total is
     * negative) and credit each line's source. A deposit into a foreign bank converts every leg to home cents at the
     * deposit-date rate, carrying the foreign amount as a memo. Receipt-source
     * lines clear Undeposited Funds, a home-currency clearing account, so they are
     * not allowed on a foreign deposit — foreign receipts deposit straight to the
     * bank instead.
     */
    protected function buildDepositLines(Deposit $deposit, JournalEntry $entry, Account $undeposited): void
    {
        $bankCurrency = $deposit->bankAccount?->currency_code;
        $isForeign = $bankCurrency !== null && ! $deposit->company->isHomeCurrency($bankCurrency);
        $currency = $isForeign ? mb_strtoupper((string) $bankCurrency) : null;
        $rate = $isForeign ? $this->lockRate($deposit) : '1';

        $totalForeign = (int) $deposit->amount_cents;
        $bankHome = Currency::toHomeCents($totalForeign, $rate);

        /** @var list<array{account_id: int, foreign: int, home: int, memo: ?string, contact_id: ?int}> $legs */
        $legs = [];

        foreach ($deposit->lines as $line) {
            if ($line->isReceiptSource() && $isForeign) {
                throw new RuntimeException('A foreign-currency deposit cannot batch Undeposited Funds receipts; deposit foreign receipts directly to the bank.');
            }

            $creditAccountId = $line->isReceiptSource()
                ? $undeposited->id
                : $line->account_id;

            if (! $creditAccountId) {
                throw new RuntimeException('Deposit line is missing an account.');
            }

            $foreign = (int) $line->amount_cents;
            $legs[] = [
                'account_id' => $creditAccountId,
                'foreign' => $foreign,
                'home' => Currency::toHomeCents($foreign, $rate),
                'memo' => $line->description,
                'contact_id' => $line->contact_id,
            ];
        }

        $this->applyRoundingPlug($legs, $bankHome);

        $order = 0;

        $entry->lines()->create([
            'account_id' => $deposit->bank_account_id,
            'debit_cents' => max($bankHome, 0),
            'credit_cents' => max(-$bankHome, 0),
            'memo' => BankLineMemo::forSource($deposit),
            'line_order' => $order++,
            ...Currency::lineMemo($currency, $rate, max($totalForeign, 0), max(-$totalForeign, 0)),
        ]);

        foreach ($legs as $leg) {
            // A negative line is a fee/deduction (e.g. a merchant charge) or a card
            // refund: it posts as a DEBIT to its account so only the net hits the bank.
            $home = (int) $leg['home'];
            $foreign = (int) $leg['foreign'];

            $entry->lines()->create([
                'account_id' => $leg['account_id'],
                'debit_cents' => max(-$home, 0),
                'credit_cents' => max($home, 0),
                'memo' => $leg['memo'],
                'contact_id' => $leg['contact_id'],
                'line_order' => $order++,
                ...Currency::lineMemo($currency, $rate, max(-$foreign, 0), max($foreign, 0)),
            ]);
        }

        if ($isForeign) {
            $deposit->forceFill(['fx_rate' => $rate, 'home_amount_cents' => $bankHome])->save();
        }
    }

    protected function lockRate(Deposit $deposit): string
    {
        if ($deposit->fx_rate !== null) {
            return (string) $deposit->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $deposit->company,
            (string) $deposit->bankAccount?->currency_code,
            CarbonImmutable::parse($deposit->deposit_date),
        );

        $deposit->forceFill(['fx_rate' => $rate])->save();

        return $rate;
    }

    protected function undepositedFundsAccount(Deposit $deposit): Account
    {
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $deposit->company_id)
            ->where('subtype', AccountSubtype::UndepositedFunds->value)
            ->where('is_system', true)
            ->first();

        if (! $account) {
            throw new RuntimeException('Missing system Undeposited Funds account.');
        }

        return $account;
    }
}
