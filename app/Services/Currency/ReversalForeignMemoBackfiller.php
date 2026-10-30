<?php

namespace App\Services\Currency;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\CurrencyRevaluation;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Services\Posting\BillReconciler;
use App\Services\Posting\CurrencyRevaluationService;
use App\Services\Posting\InvoiceReconciler;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Copies the foreign-currency memo onto reversal lines that were written without it.
 *
 * Until the fix, {@see JournalPoster::void()} and {@see ReverseJournalEntry} swapped
 * each line's home debit and credit but left currency_code, fx_rate and the foreign
 * amounts blank. A voided foreign document therefore kept its foreign units on its
 * accounts: the period-end Home Currency Adjustment revalued a balance that no longer
 * existed, and the AR/AP reconcilers, which compare a foreign customer or vendor in
 * foreign units, never re-opened a document whose closing credit was voided.
 *
 * Matching. A reversal names its original through reverses_entry_id (same company
 * only). Both writers numbered the reversal's lines 0, 1, 2… in the order they read
 * the original's lines, ORDER BY line_order, so reversal line i is the original's
 * i-th line. Walking both sides in (line_order, id) order, each reversal line pairs
 * with the first unpaired original line it mirrors — same account and contact,
 * debit and credit swapped — which is exactly that i-th line. The walk refuses,
 * and the reversal is skipped and reported rather than guessed at, when:
 *  - the two entries have a different number of lines, or a reversal line mirrors
 *    no original line: one side was edited after the reversal was written;
 *  - a reversal line could mirror two original lines that share a line_order but
 *    carry different foreign memos: the database could have read those two in
 *    either order, so which one the reversal copied cannot be told;
 *  - a reversal line already carries a memo that is not its original's mirror:
 *    something other than a void wrote it.
 * Reversals are found two ways: a blank line that mirrors a memo-carrying line
 * of the original (what can be filled), or a pair that does not net to zero in
 * foreign units on some account and contact (what has to be reported when it
 * cannot be filled), so an edited reversal is listed rather than passed over.
 *
 * Writing. Only a blank reversal line (no currency, rate or foreign amount) is
 * written, only its four memo columns, and only from the original line it pairs
 * with: the original's currency and rate, its foreign credit as the debit and its
 * foreign debit as the credit — {@see JournalLine::reversingForeignMemo()}, the
 * same mirror a void now writes. Home amounts are never touched, so balances,
 * reports and the audit chain (whose snapshots hold no foreign columns) are
 * unchanged. Then each customer and vendor whose control-account lines changed is
 * re-checked once with releaseExcessReconciliation(), which re-opens an invoice or
 * bill a voided credit had closed: the step the void took at the time, but
 * against a foreign balance that still held the credit. It runs once per company,
 * after every line is in, because a partial fill can overstate a balance.
 *
 * Each company's writes and re-checks commit together, so an interrupted run
 * leaves nothing half-done. Idempotent: a filled line is no longer blank, so a
 * second run matches nothing. A dry run counts what a live run would fill, and
 * which balances it would re-check, without writing.
 */
class ReversalForeignMemoBackfiller
{
    public function __construct(
        private InvoiceReconciler $invoiceReconciler,
        private BillReconciler $billReconciler,
    ) {}

    /**
     * The account subtypes the Home Currency Adjustment revalues (when they carry
     * a foreign currency) — see {@see CurrencyRevaluationService::revalue()}.
     */
    private const REVALUED_SUBTYPES = [
        AccountSubtype::Bank,
        AccountSubtype::CreditCard,
        AccountSubtype::AccountsReceivable,
        AccountSubtype::AccountsPayable,
    ];

    /**
     * `revaluations` counts the Home Currency Adjustments dated on or after
     * `since`, the earliest posted reversal filled on a revalued account: each of
     * those revalued the phantom units as if they were still open.
     *
     * @param  bool  $dryRun  Count what a live run would change, without writing.
     * @return array{lines: int, reversals: int, contacts: int, invoices: int, bills: int, revaluations: int, since: ?string, skipped: list<array{id: int, entry_no: string, original_id: int, original_no: string, reason: string}>}
     */
    public function backfill(int $companyId, bool $dryRun = false): array
    {
        $run = function () use ($companyId, $dryRun): array {
            $lines = 0;
            $reversals = 0;
            $skipped = [];
            $customers = [];
            $vendors = [];
            $since = null;

            $receivables = $this->accountIds($companyId, AccountSubtype::AccountsReceivable);
            $payables = $this->accountIds($companyId, AccountSubtype::AccountsPayable);
            $revalued = $this->revaluedAccountIds($companyId);

            foreach ($this->candidates($companyId) as $candidate) {
                $plan = $this->plan($this->linesOf((int) $candidate->original_id), $this->linesOf((int) $candidate->id));

                if (is_string($plan)) {
                    $skipped[] = [
                        'id' => (int) $candidate->id,
                        'entry_no' => (string) $candidate->entry_no,
                        'original_id' => (int) $candidate->original_id,
                        'original_no' => (string) $candidate->original_no,
                        'reason' => $plan,
                    ];

                    continue;
                }

                $filled = $this->fill($plan, $dryRun);

                if ($filled === []) {
                    continue;
                }

                $lines += count($filled);
                $reversals++;
                $date = CarbonImmutable::parse($candidate->entry_date)->toDateString();

                foreach ($filled as $line) {
                    if ((bool) $candidate->is_posted && in_array((int) $line->account_id, $revalued, true)) {
                        $since = $since === null ? $date : min($since, $date);
                    }

                    if ($line->contact_id === null) {
                        continue;
                    }

                    if (in_array((int) $line->account_id, $receivables, true)) {
                        $customers[(int) $line->contact_id] = true;
                    } elseif (in_array((int) $line->account_id, $payables, true)) {
                        $vendors[(int) $line->contact_id] = true;
                    }
                }
            }

            [$invoices, $bills] = $dryRun
                ? [0, 0]
                : $this->reopen($companyId, array_keys($customers), array_keys($vendors));

            return [
                'lines' => $lines,
                'reversals' => $reversals,
                'contacts' => count($customers) + count($vendors),
                'invoices' => $invoices,
                'bills' => $bills,
                'revaluations' => $since === null ? 0 : CurrencyRevaluation::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('as_of_date', '>=', $since)
                    ->count(),
                'since' => $since,
                'skipped' => $skipped,
            ];
        };

        return $dryRun ? $run() : DB::transaction($run);
    }

    /**
     * The reversals a run has to look at, oldest first: those with a blank line
     * that mirrors a memo-carrying line of their original (what a run can fill),
     * plus those that, together with their original, do not net to zero in
     * foreign units on some account and contact (what it must report when it
     * cannot pair them). Once everything is filled both match nothing — a filled
     * line is not blank and a filled pair nets to zero — so a re-run costs two
     * queries per company; a reversal it cannot repair stays listed every run.
     *
     * @return Collection<int, stdClass>
     */
    private function candidates(int $companyId): Collection
    {
        $ids = $this->withBlankMirror($companyId)
            ->merge($this->notNettingToZero($companyId))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        return $ids->chunk(500)->flatMap(fn (Collection $chunk) => $this->reversals($companyId)
            ->whereIntegerInRaw('rev.id', $chunk->all())
            ->orderBy('rev.id')
            ->get(['rev.id', 'rev.entry_no', 'rev.entry_date', 'rev.is_posted', 'orig.id as original_id', 'orig.entry_no as original_no']))
            ->values();
    }

    /**
     * Reversals joined to their original in the same company.
     */
    private function reversals(int $companyId): Builder
    {
        return DB::table('journal_entries as rev')
            ->join('journal_entries as orig', fn (JoinClause $join) => $join
                ->on('orig.id', '=', 'rev.reverses_entry_id')
                ->on('orig.company_id', '=', 'rev.company_id'))
            ->where('rev.company_id', $companyId);
    }

    /**
     * @return Collection<int, mixed>
     */
    private function withBlankMirror(int $companyId): Collection
    {
        return $this->reversals($companyId)
            ->join('journal_lines as rl', 'rl.journal_entry_id', '=', 'rev.id')
            ->join('journal_lines as ol', fn (JoinClause $join) => $join
                ->on('ol.journal_entry_id', '=', 'orig.id')
                ->on('ol.account_id', '=', 'rl.account_id')
                ->on('ol.debit_cents', '=', 'rl.credit_cents')
                ->on('ol.credit_cents', '=', 'rl.debit_cents'))
            ->whereNull('rl.currency_code')
            ->whereNull('rl.fx_rate')
            ->where('rl.foreign_debit_cents', 0)
            ->where('rl.foreign_credit_cents', 0)
            ->where(fn (Builder $q) => $q
                ->whereNotNull('ol.currency_code')
                ->orWhereNotNull('ol.fx_rate')
                ->orWhere('ol.foreign_debit_cents', '!=', 0)
                ->orWhere('ol.foreign_credit_cents', '!=', 0))
            ->distinct()
            ->pluck('rev.id');
    }

    /**
     * @return Collection<int, mixed>
     */
    private function notNettingToZero(int $companyId): Collection
    {
        // One row per foreign-carrying line of either side, keyed by the reversal.
        $side = fn (string $entry): Builder => $this->reversals($companyId)
            ->join('journal_lines as jl', 'jl.journal_entry_id', '=', $entry)
            ->where(fn (Builder $q) => $q->where('jl.foreign_debit_cents', '!=', 0)->orWhere('jl.foreign_credit_cents', '!=', 0))
            ->select('rev.id as reversal_id', 'jl.account_id', 'jl.contact_id')
            ->selectRaw('jl.foreign_debit_cents - jl.foreign_credit_cents as net');

        return DB::query()
            ->fromSub($side('orig.id')->unionAll($side('rev.id')), 'pair_lines')
            ->groupBy('reversal_id', 'account_id', 'contact_id')
            ->havingRaw('SUM(net) <> 0')
            ->distinct()
            ->pluck('reversal_id');
    }

    /**
     * @return Collection<int, JournalLine>
     */
    private function linesOf(int $entryId): Collection
    {
        return JournalLine::query()
            ->where('journal_entry_id', $entryId)
            ->orderBy('line_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The [reversal line, original line] pairs to fill, or the reason this
     * reversal is skipped. A reversal line that already carries a memo must
     * carry exactly the mirror of its original's; anything else means something
     * other than a void wrote it, and the whole reversal is left alone.
     *
     * @param  Collection<int, JournalLine>  $original
     * @param  Collection<int, JournalLine>  $reversal
     * @return list<array{JournalLine, JournalLine}>|string
     */
    private function plan(Collection $original, Collection $reversal): array|string
    {
        $pairs = $this->pair($original, $reversal);

        if (is_string($pairs)) {
            return $pairs;
        }

        $fill = [];

        foreach ($pairs as [$line, $source]) {
            if (! $line->hasForeignMemo()) {
                if ($source->hasForeignMemo()) {
                    $fill[] = [$line, $source];
                }

                continue;
            }

            if ($this->memoOf($line) !== $this->mirrorMemoOf($source)) {
                return sprintf('its line %d already carries a foreign-currency memo that is not the mirror of the original\'s, so it was left as it is.', (int) $line->line_order + 1);
            }
        }

        return $fill;
    }

    /**
     * Pair each reversal line with the original line it was copied from, or say
     * why that cannot be done safely. See the class docblock for the rule.
     *
     * @param  Collection<int, JournalLine>  $original
     * @param  Collection<int, JournalLine>  $reversal
     * @return list<array{JournalLine, JournalLine}>|string [reversal line, original line] pairs, or the reason to skip
     */
    private function pair(Collection $original, Collection $reversal): array|string
    {
        if ($original->count() !== $reversal->count()) {
            return sprintf('it has %d line(s) and the original %d, so one of them was edited after the reversal was written.', $reversal->count(), $original->count());
        }

        $unpaired = $original->values()->all();
        $pairs = [];

        foreach ($reversal as $line) {
            $candidates = array_filter($unpaired, fn (JournalLine $o): bool => $this->mirrors($line, $o));

            if ($candidates === []) {
                return sprintf('its line %d mirrors none of the original\'s lines (same account and contact, debit and credit swapped), so one of them was edited after the reversal was written.', (int) $line->line_order + 1);
            }

            $key = array_key_first($candidates);
            $match = $candidates[$key];

            foreach ($candidates as $other) {
                if ($other !== $match
                    && (int) $other->line_order === (int) $match->line_order
                    && $this->memoOf($other) !== $this->memoOf($match)) {
                    return sprintf('its line %d could mirror either of two original lines that share a line order but carry different foreign amounts, so which one it copied cannot be told.', (int) $line->line_order + 1);
                }
            }

            $pairs[] = [$line, $match];
            unset($unpaired[$key]);
        }

        return $pairs;
    }

    private function mirrors(JournalLine $reversal, JournalLine $original): bool
    {
        return (int) $reversal->account_id === (int) $original->account_id
            && (int) $reversal->debit_cents === (int) $original->credit_cents
            && (int) $reversal->credit_cents === (int) $original->debit_cents
            && self::nullableInt($reversal->contact_id) === self::nullableInt($original->contact_id);
    }

    /**
     * The memo columns in a comparable form: the rate as a fixed-scale string, so
     * '1.35' and '1.35000000' (driver-dependent spellings of one decimal) agree.
     *
     * @return array{?string, ?string, int, int}
     */
    private function memoOf(JournalLine $line): array
    {
        return self::comparableMemo($line->currency_code, $line->fx_rate, $line->foreign_debit_cents, $line->foreign_credit_cents);
    }

    /**
     * The memo a reversal of this line carries, in {@see memoOf()}'s form.
     *
     * @return array{?string, ?string, int, int}
     */
    private function mirrorMemoOf(JournalLine $original): array
    {
        $mirror = $original->reversingForeignMemo();

        return self::comparableMemo($mirror['currency_code'], $mirror['fx_rate'], $mirror['foreign_debit_cents'], $mirror['foreign_credit_cents']);
    }

    /**
     * @return array{?string, ?string, int, int}
     */
    private static function comparableMemo(?string $currency, mixed $rate, mixed $foreignDebit, mixed $foreignCredit): array
    {
        return [
            $currency,
            $rate === null ? null : number_format((float) $rate, 8, '.', ''),
            (int) $foreignDebit,
            (int) $foreignCredit,
        ];
    }

    /**
     * Write the mirrored memo onto each planned reversal line. The update
     * re-checks that the line is still blank, so it never overwrites a memo,
     * even one written since the line was read.
     *
     * @param  list<array{JournalLine, JournalLine}>  $pairs
     * @return list<JournalLine> the reversal lines filled (or, on a dry run, that would be)
     */
    private function fill(array $pairs, bool $dryRun): array
    {
        $filled = [];

        foreach ($pairs as [$line, $original]) {
            if ($dryRun || $this->blank(JournalLine::query()->whereKey($line->id))->update($original->reversingForeignMemo()) === 1) {
                $filled[] = $line;
            }
        }

        return $filled;
    }

    /**
     * @param  EloquentBuilder<JournalLine>  $query
     * @return EloquentBuilder<JournalLine>
     */
    private function blank(EloquentBuilder $query): EloquentBuilder
    {
        return $query
            ->whereNull('currency_code')
            ->whereNull('fx_rate')
            ->where('foreign_debit_cents', 0)
            ->where('foreign_credit_cents', 0);
    }

    /**
     * Re-open what a voided credit had closed, now that the foreign balances
     * hold the reversal: the same releaseExcessReconciliation() a void runs. It
     * only ever re-opens — a document is never closed here.
     *
     * @param  list<int>  $customerIds
     * @param  list<int>  $vendorIds
     * @return array{int, int} invoices and bills re-opened
     */
    private function reopen(int $companyId, array $customerIds, array $vendorIds): array
    {
        if ($customerIds === [] && $vendorIds === []) {
            return [0, 0];
        }

        $company = Company::query()->withoutGlobalScopes()->findOrFail($companyId);

        // Scope the document reads to this tenant, and put back whatever the
        // caller had bound.
        $previous = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        try {
            $invoices = 0;

            foreach ($customerIds as $contactId) {
                $invoices += $this->countReopened(
                    Invoice::query()->where('contact_id', $contactId),
                    fn () => $this->invoiceReconciler->releaseExcessReconciliation($company, $contactId),
                );
            }

            $bills = 0;

            foreach ($vendorIds as $contactId) {
                $bills += $this->countReopened(
                    Bill::query()->where('contact_id', $contactId),
                    fn () => $this->billReconciler->releaseExcessReconciliation($company, $contactId),
                );
            }

            return [$invoices, $bills];
        } finally {
            if ($previous !== null) {
                app()->instance('current_company', $previous);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }

    /**
     * Run a release and count the documents whose reconciled amount it lowered.
     *
     * @param  EloquentBuilder<Invoice>|EloquentBuilder<Bill>  $documents
     */
    private function countReopened(EloquentBuilder $documents, Closure $release): int
    {
        $before = (clone $documents)->where('reconciled_cents', '>', 0)->pluck('reconciled_cents', 'id');

        if ($before->isEmpty()) {
            return 0;
        }

        $release();

        $after = (clone $documents)->whereKey($before->keys()->all())->pluck('reconciled_cents', 'id');

        return $before->filter(fn ($cents, $id): bool => (int) ($after[$id] ?? 0) < (int) $cents)->count();
    }

    /**
     * @return list<int>
     */
    private function revaluedAccountIds(int $companyId): array
    {
        return Account::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('currency_code')
            ->whereIn('subtype', array_map(fn (AccountSubtype $subtype): string => $subtype->value, self::REVALUED_SUBTYPES))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function accountIds(int $companyId, AccountSubtype $subtype): array
    {
        return Account::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subtype', $subtype->value)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
