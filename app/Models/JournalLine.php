<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @property int|null $fund_id
 */
#[Fillable([
    'journal_entry_id',
    'account_id',
    'debit_cents',
    'credit_cents',
    'currency_code',
    'fx_rate',
    'foreign_debit_cents',
    'foreign_credit_cents',
    'memo',
    'contact_id',
    'tax_code_id',
    'line_order',
    'class_id',
    'location_id',
    'fund_id',
    'cleared_at',
    'bank_reconciliation_id',
    'is_posted',
    'entry_date',
])]
class JournalLine extends Model
{
    /**
     * Seed the denormalised posting state from the parent entry at insert time so
     * that lines attached to an already-posted entry (e.g. fabricated directly in
     * tests) are immediately balance-correct. For the normal draft → post() flow
     * the entry is still a draft here; {@see JournalEntry} propagates the flip to
     * is_posted (and any entry_date edit) to existing lines via its own saved hook.
     */
    protected static function booted(): void
    {
        static::creating(function (JournalLine $line): void {
            if ($line->journal_entry_id === null) {
                return;
            }

            $entry = $line->relationLoaded('journalEntry')
                ? $line->getRelation('journalEntry')
                : JournalEntry::withoutGlobalScopes()->find($line->journal_entry_id);

            if ($entry !== null) {
                $line->is_posted = $entry->is_posted;
                $line->entry_date = $entry->entry_date?->toDateString();
            }
        });
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<BankReconciliation, $this>
     */
    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    /**
     * @return BelongsTo<Classification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'class_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withoutGlobalScopes();
    }

    /**
     * Leave out both halves of a void — the voided entry's line and its
     * reversal's line — while neither half on that account has been settled.
     * A voided cheque is never presented, so neither half reaches the bank:
     * the pair nets to zero and is not an outstanding item. Once either half
     * is cleared, both are kept, so the other half can still be settled
     * against it instead of being stranded.
     *
     * Only voids count. An entry undone with Reverse is not voided, and its
     * reversal is a real posting that stays in.
     *
     * Pass the in-progress reconciliation being worked on and its ticks count
     * as settled too, while the cleared stamp it put on its own service-charge
     * or interest line when posting it does not — that line only counts once
     * it is ticked.
     *
     * @param  Builder<JournalLine>  $query
     * @return Builder<JournalLine>
     */
    public function scopeWithoutUnsettledVoids(Builder $query, ?BankReconciliation $within = null): Builder
    {
        return $query
            ->whereNotExists(self::unsettledVoid($query, 'id', $within))
            ->whereNotExists(self::unsettledVoid($query, 'reversed_by_entry_id', $within));
    }

    /**
     * The inverse of {@see scopeWithoutUnsettledVoids()}: only the lines it
     * would leave out.
     *
     * @param  Builder<JournalLine>  $query
     * @return Builder<JournalLine>
     */
    public function scopeUnsettledVoids(Builder $query, ?BankReconciliation $within = null): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereExists(self::unsettledVoid($query, 'id', $within))
            ->orWhereExists(self::unsettledVoid($query, 'reversed_by_entry_id', $within)));
    }

    /**
     * EXISTS body matching the voided entry a line belongs to — as the voided
     * entry itself ($side = 'id') or as its reversal ($side =
     * 'reversed_by_entry_id') — when no line of that pair on the same account
     * is settled.
     *
     * @param  Builder<JournalLine>  $outer
     * @return Closure(QueryBuilder): void
     */
    private static function unsettledVoid(Builder $outer, string $side, ?BankReconciliation $within): Closure
    {
        $entryColumn = $outer->qualifyColumn('journal_entry_id');
        $accountColumn = $outer->qualifyColumn('account_id');
        $recId = $within?->id;
        $marked = $within?->markedLineIds() ?? [];

        return function (QueryBuilder $q) use ($side, $entryColumn, $accountColumn, $recId, $marked): void {
            $q->select('void_entries.id')
                ->from('journal_entries as void_entries')
                ->whereColumn("void_entries.{$side}", $entryColumn)
                ->whereNotNull('void_entries.voided_at')
                ->whereNotNull('void_entries.reversed_by_entry_id')
                ->whereNotExists(function (QueryBuilder $q) use ($accountColumn, $recId, $marked): void {
                    $q->select('pair_lines.id')
                        ->from('journal_lines as pair_lines')
                        ->whereColumn('pair_lines.account_id', $accountColumn)
                        ->where(fn (QueryBuilder $q) => $q
                            ->whereColumn('pair_lines.journal_entry_id', 'void_entries.id')
                            ->orWhereColumn('pair_lines.journal_entry_id', 'void_entries.reversed_by_entry_id'))
                        ->where(function (QueryBuilder $q) use ($recId, $marked): void {
                            if ($recId === null) {
                                $q->whereNotNull('pair_lines.cleared_at')
                                    ->orWhereNotNull('pair_lines.bank_reconciliation_id');
                            } else {
                                $q->where(fn (QueryBuilder $q) => $q
                                    ->whereNotNull('pair_lines.bank_reconciliation_id')
                                    ->where('pair_lines.bank_reconciliation_id', '!=', $recId))
                                    ->orWhere(fn (QueryBuilder $q) => $q
                                        ->whereNull('pair_lines.bank_reconciliation_id')
                                        ->whereNotNull('pair_lines.cleared_at'));
                            }

                            if ($marked !== []) {
                                // Raw integers, not bindings: a big reconciliation can
                                // tick more lines than a statement may bind.
                                $q->orWhereIntegerInRaw('pair_lines.id', $marked);
                            }
                        });
                });
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_cents' => 'integer',
            'credit_cents' => 'integer',
            'foreign_debit_cents' => 'integer',
            'foreign_credit_cents' => 'integer',
            'cleared_at' => 'datetime',
            'is_posted' => 'boolean',
            'entry_date' => 'date:Y-m-d',
        ];
    }
}
