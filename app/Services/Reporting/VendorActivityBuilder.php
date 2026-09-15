<?php

namespace App\Services\Reporting;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\VendorCredit;
use App\Support\Reporting\SourceLinkResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Vendor Activity report: every posted transaction that names a
 * vendor, one row per vendor per journal entry. Unlike the AP statement, which
 * reads only the Accounts Payable control account, this also lists the cheques,
 * expenses and journal entries coded straight to an expense or bank account —
 * money paid to a vendor that never went through A/P.
 *
 * Read straight off the general ledger (journal_lines.contact_id). A voided
 * document stays listed and flagged void, while the reversal that voided it is
 * left out: the pair nets to zero and the reversal would otherwise read as a
 * second payment. An entry undone with Reverse is not voided, so its reversal
 * is a real posting and stays.
 *
 * Each row names the entry's main account — its largest line, so the bank on a
 * cheque and A/P on a bill — and the accounts on the other side as the split.
 */
class VendorActivityBuilder
{
    /**
     * Source type => display label and the table/column carrying the document's
     * human-facing number. Every one of these tables has a `memo` column.
     *
     * @var array<class-string, array{label: string, table: string, number: string}>
     */
    private const DOCUMENTS = [
        Bill::class => ['label' => 'Bill', 'table' => 'bills', 'number' => 'bill_no'],
        BillPayment::class => ['label' => 'Bill Payment', 'table' => 'bill_payments', 'number' => 'payment_no'],
        Cheque::class => ['label' => 'Cheque', 'table' => 'cheques', 'number' => 'cheque_no'],
        Expense::class => ['label' => 'Expense', 'table' => 'expenses', 'number' => 'reference'],
        VendorCredit::class => ['label' => 'Vendor Credit', 'table' => 'vendor_credits', 'number' => 'vendor_credit_no'],
        Deposit::class => ['label' => 'Deposit', 'table' => 'deposits', 'number' => 'deposit_no'],
        Invoice::class => ['label' => 'Invoice', 'table' => 'invoices', 'number' => 'invoice_no'],
        CustomerReceipt::class => ['label' => 'Receipt', 'table' => 'customer_receipts', 'number' => 'receipt_no'],
        CreditMemo::class => ['label' => 'Credit Memo', 'table' => 'credit_memos', 'number' => 'credit_memo_no'],
    ];

    public function __construct(protected SourceLinkResolver $links) {}

    /**
     * One row per (vendor, journal entry) in the period, ordered vendor → date.
     * With no vendor given, every contact flagged as a vendor is included.
     */
    public function query(Company $company, CarbonImmutable $start, CarbonImmutable $end, ?int $vendorId = null): Builder
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('contacts as c', 'c.id', '=', 'jl.contact_id')
            // Matches only when this entry is the reversal a void posted.
            ->leftJoin('journal_entries as voided', function (JoinClause $join): void {
                $join->on('voided.id', '=', 'je.reverses_entry_id')
                    ->on('voided.reversed_by_entry_id', '=', 'je.id')
                    ->whereNotNull('voided.voided_at');
            })
            ->where('je.company_id', $company->id)
            ->where('jl.is_posted', true)
            ->whereBetween('jl.entry_date', [$start->toDateString(), $end->toDateString()])
            ->whereNull('voided.id')
            ->when(
                $vendorId !== null,
                fn (Builder $q) => $q->where('jl.contact_id', $vendorId),
                fn (Builder $q) => $q->where('c.is_vendor', true),
            )
            ->groupBy('c.id', 'c.display_name', 'je.id', 'je.entry_no', 'je.entry_date', 'je.memo', 'je.source_type', 'je.source_id', 'je.voided_at')
            ->orderBy('c.display_name')
            ->orderBy('c.id')
            ->orderBy('je.entry_date')
            ->orderBy('je.entry_no')
            ->orderBy('je.id')
            ->select([
                'c.id as contact_id', 'c.display_name',
                'je.id as entry_id', 'je.entry_no', 'je.entry_date', 'je.memo as entry_memo',
                'je.source_type', 'je.source_id', 'je.voided_at',
            ])
            ->selectRaw('SUM(jl.debit_cents) as debit_cents, SUM(jl.credit_cents) as credit_cents');
    }

    /**
     * Every row of the report, hydrated in chunks — for exports.
     *
     * @return iterable<int, array{contact_id: int, vendor: string, entry_id: int, date: string, type: string, doc_no: string, memo: string, account: string, split: list<string>, amount: int, is_void: bool, url: ?string}>
     */
    public function rows(Company $company, CarbonImmutable $start, CarbonImmutable $end, ?int $vendorId = null): iterable
    {
        foreach ($this->query($company, $start, $end, $vendorId)->lazy(500)->chunk(500) as $chunk) {
            yield from $this->hydrate($company, $chunk->collect());
        }
    }

    /**
     * Turn query() rows into display rows: document type, number and memo, the
     * main and split accounts, the vendor's amount, and the drill-through URL.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array{contact_id: int, vendor: string, entry_id: int, date: string, type: string, doc_no: string, memo: string, account: string, split: list<string>, amount: int, is_void: bool, url: ?string}>
     */
    public function hydrate(Company $company, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $entryIds = $rows->pluck('entry_id')->unique()->values()->all();

        $lines = DB::table('journal_lines as jl')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('jl.journal_entry_id', $entryIds)
            ->orderBy('jl.line_order')
            ->orderBy('jl.id')
            ->get(['jl.journal_entry_id', 'jl.contact_id', 'jl.debit_cents', 'jl.credit_cents', 'jl.memo', 'a.id as account_id', 'a.code', 'a.name'])
            ->groupBy('journal_entry_id');

        $entries = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('id', $entryIds)
            ->get()
            ->keyBy('id');

        $documents = $this->resolveDocuments($company, $rows);

        return $rows->map(function (\stdClass $row) use ($company, $lines, $entries, $documents): array {
            $contactId = (int) $row->contact_id;

            // The vendor's own lines plus the untagged ones: a bill tags only its
            // A/P line, a cheque tags every line, and a multi-payee entry carries
            // other contacts' lines that don't belong on this vendor's row.
            $relevant = collect($lines->get($row->entry_id, []))
                ->filter(fn (\stdClass $l): bool => $l->contact_id === null || (int) $l->contact_id === $contactId)
                ->values();

            [$account, $split] = $this->accountAndSplit($relevant);

            $document = $documents[$row->source_type][$row->source_id] ?? null;
            $meta = self::DOCUMENTS[$row->source_type] ?? null;
            $entry = $entries->get($row->entry_id);

            return [
                'contact_id' => $contactId,
                'vendor' => (string) $row->display_name,
                'entry_id' => (int) $row->entry_id,
                'date' => CarbonImmutable::parse($row->entry_date)->toDateString(),
                'type' => $meta !== null ? __($meta['label']) : ($entry !== null ? $this->links->label($entry) : __('Journal entry')),
                'doc_no' => (string) (($document->number ?? null) ?: $row->entry_no),
                'memo' => (string) (($document->memo ?? null) ?: ($row->entry_memo ?? '')),
                'account' => $account,
                'split' => $split,
                'amount' => max((int) $row->debit_cents, (int) $row->credit_cents),
                'is_void' => $row->voided_at !== null,
                'url' => $entry !== null ? $this->links->urlFor($entry, $company) : null,
            ];
        })->all();
    }

    /**
     * The main account is the entry's largest line — on a tie (a bill payment's
     * A/P debit against its bank credit) the credit side, where the money came
     * from. The split lists the accounts on the other side, largest first.
     *
     * @param  Collection<int, \stdClass>  $lines
     * @return array{0: string, 1: list<string>}
     */
    private function accountAndSplit(Collection $lines): array
    {
        $main = $lines->reduce(function (?\stdClass $best, \stdClass $line): \stdClass {
            if ($best === null) {
                return $line;
            }

            $size = max((int) $line->debit_cents, (int) $line->credit_cents);
            $bestSize = max((int) $best->debit_cents, (int) $best->credit_cents);

            return $size > $bestSize || ($size === $bestSize && (int) $line->credit_cents > 0 && (int) $best->credit_cents === 0)
                ? $line
                : $best;
        });

        if ($main === null) {
            return ['', []];
        }

        $mainIsCredit = (int) $main->credit_cents > 0;

        $split = $lines
            ->filter(fn (\stdClass $l): bool => (int) $l->account_id !== (int) $main->account_id
                && ($mainIsCredit ? (int) $l->debit_cents > 0 : (int) $l->credit_cents > 0))
            ->groupBy('account_id')
            ->map(fn (Collection $group): array => [
                'label' => $this->accountLabel($group->first()),
                'amount' => $group->sum(fn (\stdClass $l): int => (int) $l->debit_cents + (int) $l->credit_cents),
            ])
            ->sortByDesc('amount')
            ->pluck('label')
            ->values()
            ->all();

        return [$this->accountLabel($main), $split];
    }

    private function accountLabel(\stdClass $line): string
    {
        return trim(($line->code ?? '').' — '.$line->name, ' —');
    }

    /**
     * Bulk-load each recognised source document's number and memo, keyed as
     * [source_type][source_id] => {number, memo}. One query per document type.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array<class-string, Collection<int, \stdClass>>
     */
    private function resolveDocuments(Company $company, Collection $rows): array
    {
        $documents = [];

        foreach ($rows->whereNotNull('source_id')->groupBy('source_type') as $type => $group) {
            $meta = self::DOCUMENTS[$type] ?? null;

            if ($meta === null) {
                continue;
            }

            $documents[$type] = DB::table($meta['table'])
                ->where('company_id', $company->id)
                ->whereIn('id', $group->pluck('source_id')->unique()->all())
                ->get(['id', $meta['number'].' as number', 'memo'])
                ->keyBy('id');
        }

        return $documents;
    }
}
