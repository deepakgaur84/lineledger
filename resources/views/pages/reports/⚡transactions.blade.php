<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasColumnToggles;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Concerns\HasReportDimensions;
use App\Concerns\Memorizable;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\SourceLinkResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * QuickZoom drill target: the posted journal lines behind a figure on another
 * report. Filtered by account, contact, class/location, and date range; each
 * row links to the document that produced it via SourceLinkResolver.
 */
new #[Title('Transactions')] class extends Component {
    use EmailsReport;
    use HasColumnToggles;
    use HasCustomReportHeader;
    use HasReportDateRange;
    use HasReportDimensions;
    use Memorizable;
    use WithPagination;

    private const GROUP_OPTIONS = ['none', 'account', 'contact', 'month', 'source'];

    public Company $company;

    #[Url(as: 'account')]
    public ?int $accountId = null;

    #[Url(as: 'contact')]
    public ?int $contactId = null;

    #[Url(as: 'group')]
    public string $groupBy = 'none';

    #[Url(as: 'source')]
    public string $sourceType = '';

    /** Per-request memo for groupLabelFor(); not Livewire state. @var array<string, string> */
    protected array $groupLabelCache = [];

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
        $this->applyMemorized((int) request('memorized'));
        $this->sanitizeGroupBy();
        $this->sanitizeSourceType();
    }

    protected function reportKey(): string
    {
        return 'reports.transactions';
    }

    /** @return array<string, string> */
    public function columnRegistry(): array
    {
        $columns = [
            'entry_no' => __('Entry #'),
            'name' => __('Name'),
            'memo' => __('Memo'),
        ];

        // Home-currency running balance: any single filtered account, any
        // currency — a grouped/multi-account view has no one chronological
        // line to run a balance down.
        if ($this->filteredAccount() !== null && $this->groupBy === 'none') {
            $columns['balance'] = __('Balance');
        }

        if (($account = $this->foreignAccountFilter()) !== null) {
            $columns['source_debit'] = __('Debit (:currency)', ['currency' => $account->currency_code]);
            $columns['source_credit'] = __('Credit (:currency)', ['currency' => $account->currency_code]);

            // Running balance only makes sense in the plain, ungrouped
            // register view — a grouped/summarized view has no single
            // chronological line to run a balance down.
            if ($this->groupBy === 'none') {
                $columns['source_balance'] = __('Balance (:currency)', ['currency' => $account->currency_code]);
            }
        }

        return $columns;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['accountId', 'contactId', 'startDate', 'endDate', 'preset', 'classId', 'locationId', 'groupBy', 'sourceType'], true)) {
            $this->resetPage();
        }
    }

    public function updatedGroupBy(): void
    {
        $this->sanitizeGroupBy();
    }

    public function updatedSourceType(): void
    {
        $this->sanitizeSourceType();
    }

    private function sanitizeGroupBy(): void
    {
        if (! in_array($this->groupBy, self::GROUP_OPTIONS, true)) {
            $this->groupBy = 'none';
        }
    }

    private function sanitizeSourceType(): void
    {
        if (! in_array($this->sourceType, ['', 'journal'], true)
            && ! array_key_exists($this->sourceType, $this->sourceTypeMap())) {
            $this->sourceType = '';
        }
    }

    /**
     * class_basename => FQCN for every linkable source model — the value space
     * of the Type filter (plus '' = all types and 'journal' = manual entries).
     * Public because the view renders the options from it.
     *
     * @return array<string, class-string>
     */
    public function sourceTypeMap(): array
    {
        $map = [];

        foreach (SourceLinkResolver::sourceTypes() as $fqcn) {
            $map[class_basename($fqcn)] = $fqcn;
        }

        return $map;
    }

    #[Computed]
    public function account(): ?Account
    {
        return $this->accountId !== null ? Account::find($this->accountId) : null;
    }

    #[Computed]
    public function contactFilter(): ?Contact
    {
        return $this->contactId !== null ? Contact::find($this->contactId) : null;
    }

    /**
     * The filtered account, of any currency — gates the general home-currency
     * running balance column, same scope as foreignAccountFilter but without
     * the currency requirement. A balance is only meaningful for one specific
     * account's chronological line of transactions, so grouped/multi-account
     * views never show it.
     */
    #[Computed]
    public function filteredAccount(): ?Account
    {
        return $this->accountId !== null ? Account::find($this->accountId) : null;
    }

    /**
     * The filtered account, when it's a genuine foreign-currency account —
     * gates the source-currency columns, same scope as Xero's own account
     * transaction report (which is always single-account). Grouped/multi-
     * account views never show these columns: mixing currencies in one
     * source-amount column would be meaningless.
     */
    #[Computed]
    public function foreignAccountFilter(): ?Account
    {
        $account = $this->filteredAccount();

        return $account?->currency_code !== null ? $account : null;
    }

    /**
     * Posted lines matching the active filters, in display order. Shared by the
     * on-screen paginator and the full-dataset exports. When grouping, ordering
     * switches to group-key-first so each group's lines are contiguous.
     *
     * @return Builder<JournalLine>
     */
    private function filteredQuery(): Builder
    {
        $query = $this->baseQuery()
            ->with(['account:id,code,name', 'contact:id,display_name', 'journalEntry']);

        return match ($this->groupBy) {
            'account' => $query
                ->join('accounts as group_accounts', 'group_accounts.id', '=', 'journal_lines.account_id')
                ->select('journal_lines.*')
                ->orderBy('group_accounts.code')
                ->orderBy('journal_lines.account_id')
                ->orderBy('journal_lines.entry_date')
                ->orderBy('journal_lines.id'),
            'contact' => $query
                ->leftJoin('contacts as group_contacts', 'group_contacts.id', '=', 'journal_lines.contact_id')
                ->select('journal_lines.*')
                // (display_name IS NULL) sorts the no-contact group last on both
                // SQLite and MySQL, without COALESCE-sentinel collation surprises.
                ->orderByRaw('(group_contacts.display_name IS NULL)')
                ->orderBy('group_contacts.display_name')
                ->orderBy('journal_lines.contact_id')
                ->orderBy('journal_lines.entry_date')
                ->orderBy('journal_lines.id'),
            'source' => $query
                ->join('journal_entries as group_entries', 'group_entries.id', '=', 'journal_lines.journal_entry_id')
                ->select('journal_lines.*')
                ->orderByRaw("COALESCE(group_entries.source_type, '')")
                ->orderBy('journal_lines.entry_date')
                ->orderBy('journal_lines.id'),
            // 'month' groups by the entry_date prefix, so plain date order is
            // already grouped; 'none' keeps the original ordering.
            default => $query
                ->orderBy('journal_lines.entry_date')
                ->orderBy('journal_lines.id'),
        };
    }

    /**
     * The active filters with no eager loads or ordering — shared by the display
     * query and the group-totals aggregate so the two always agree.
     *
     * @return Builder<JournalLine>
     */
    private function baseQuery(): Builder
    {
        $sourceFqcn = $this->sourceTypeMap()[$this->sourceType] ?? null;

        return JournalLine::query()
            ->where('journal_lines.is_posted', true)
            ->whereBetween('journal_lines.entry_date', [$this->startDate, $this->endDate])
            ->when($this->accountId !== null, fn ($q) => $q->where('journal_lines.account_id', $this->accountId))
            ->when($this->contactId !== null, fn ($q) => $q->where('journal_lines.contact_id', $this->contactId))
            ->when($this->effectiveClassId() !== null, fn ($q) => $q->where('journal_lines.class_id', $this->effectiveClassId()))
            ->when($this->effectiveLocationId() !== null, fn ($q) => $q->where('journal_lines.location_id', $this->effectiveLocationId()))
            ->when($this->effectiveFundId() !== null, fn ($q) => $q->where('journal_lines.fund_id', $this->effectiveFundId()))
            ->when($this->sourceType === 'journal', fn ($q) => $q->whereHas('journalEntry', fn ($jq) => $jq->whereNull('source_type')))
            ->when($sourceFqcn !== null, fn ($q) => $q->whereHas('journalEntry', fn ($jq) => $jq->where('source_type', $sourceFqcn)));
    }

    /**
     * Net foreign-currency movement on the filtered account, strictly before
     * the report's start date — the register's opening balance line. Null
     * when no foreign account is being viewed.
     */
    #[Computed]
    public function openingForeignBalanceCents(): ?int
    {
        $account = $this->foreignAccountFilter();

        if ($account === null) {
            return null;
        }

        return (int) JournalLine::query()
            ->where('is_posted', true)
            ->where('account_id', $account->id)
            ->where('entry_date', '<', $this->startDate)
            ->selectRaw('COALESCE(SUM(foreign_debit_cents - foreign_credit_cents), 0) AS net')
            ->value('net');
    }

    /**
     * Running foreign balance immediately before the current page's first
     * row — opening balance plus every prior page's net movement, so the
     * running total in the table stays correct across pagination without
     * needing to fetch every row up front. Null outside the plain,
     * ungrouped register view (see columnRegistry).
     */
    #[Computed]
    public function runningForeignBalanceBeforePage(): ?int
    {
        $account = $this->foreignAccountFilter();

        if ($account === null || $this->groupBy !== 'none') {
            return null;
        }

        $skip = max(0, ($this->lines->firstItem() ?? 1) - 1);

        if ($skip === 0) {
            return $this->openingForeignBalanceCents();
        }

        $priorNet = (int) $this->baseQuery()
            ->orderBy('journal_lines.entry_date')
            ->orderBy('journal_lines.id')
            ->limit($skip)
            ->selectRaw('COALESCE(SUM(foreign_debit_cents - foreign_credit_cents), 0) AS net')
            ->value('net');

        return $this->openingForeignBalanceCents() + $priorNet;
    }

    /**
     * Net home-currency movement on the filtered account, strictly before
     * the report's start date, signed per the account's own normal balance
     * (matches Account::recomputeBalance's convention) — the register's
     * opening balance line. Null when no single account is being viewed.
     */
    #[Computed]
    public function openingHomeBalanceCents(): ?int
    {
        $account = $this->filteredAccount();

        if ($account === null) {
            return null;
        }

        $totals = JournalLine::query()
            ->where('is_posted', true)
            ->where('account_id', $account->id)
            ->where('entry_date', '<', $this->startDate)
            ->selectRaw('COALESCE(SUM(debit_cents), 0) AS debits, COALESCE(SUM(credit_cents), 0) AS credits')
            ->first();

        $debits = (int) $totals->debits;
        $credits = (int) $totals->credits;

        return $account->normal_balance === NormalBalance::Debit
            ? $debits - $credits
            : $credits - $debits;
    }

    /**
     * Running home-currency balance immediately before the current page's
     * first row — same pagination-safe approach as
     * runningForeignBalanceBeforePage, signed per the account's normal
     * balance. Null outside the plain, ungrouped register view.
     */
    #[Computed]
    public function runningHomeBalanceBeforePage(): ?int
    {
        $account = $this->filteredAccount();

        if ($account === null || $this->groupBy !== 'none') {
            return null;
        }

        $skip = max(0, ($this->lines->firstItem() ?? 1) - 1);

        if ($skip === 0) {
            return $this->openingHomeBalanceCents();
        }

        $priorTotals = $this->baseQuery()
            ->orderBy('journal_lines.entry_date')
            ->orderBy('journal_lines.id')
            ->limit($skip)
            ->selectRaw('COALESCE(SUM(debit_cents), 0) AS debits, COALESCE(SUM(credit_cents), 0) AS credits')
            ->first();

        $priorNet = $account->normal_balance === NormalBalance::Debit
            ? (int) $priorTotals->debits - (int) $priorTotals->credits
            : (int) $priorTotals->credits - (int) $priorTotals->debits;

        return $this->openingHomeBalanceCents() + $priorNet;
    }

    /**
     * The account's true home-currency balance as of the report's end date —
     * independent of pagination, so it's correct however many pages the
     * period spans. Null when no single account is being viewed.
     */
    #[Computed]
    public function closingHomeBalanceCents(): ?int
    {
        $account = $this->filteredAccount();

        if ($account === null) {
            return null;
        }

        $totals = $this->baseQuery()
            ->selectRaw('COALESCE(SUM(debit_cents), 0) AS debits, COALESCE(SUM(credit_cents), 0) AS credits')
            ->first();

        $periodNet = $account->normal_balance === NormalBalance::Debit
            ? (int) $totals->debits - (int) $totals->credits
            : (int) $totals->credits - (int) $totals->debits;

        return $this->openingHomeBalanceCents() + $periodNet;
    }

    /**
     * The foreign-currency closing balance as of the report's end date,
     * mirroring closingHomeBalanceCents but for the source-currency amounts.
     * Null unless a foreign-currency account is being viewed.
     */
    #[Computed]
    public function closingForeignBalanceCents(): ?int
    {
        $account = $this->foreignAccountFilter();

        if ($account === null) {
            return null;
        }

        $periodNet = (int) $this->baseQuery()
            ->selectRaw('COALESCE(SUM(foreign_debit_cents - foreign_credit_cents), 0) AS net')
            ->value('net');

        return $this->openingForeignBalanceCents() + $periodNet;
    }

    /**
     * @return LengthAwarePaginator<int, JournalLine>
     */
    #[Computed]
    public function lines(): LengthAwarePaginator
    {
        return $this->filteredQuery()->paginate(50);
    }

    /**
     * Full per-group totals across ALL pages, via one aggregate query over the
     * same filters — a group spanning pages shows its true total on every page
     * (the same aggregate approach generalLedgerAllAccountsPaginated takes for
     * its grand totals).
     *
     * @return array<string, array{debit: int, credit: int, count: int}>
     */
    #[Computed]
    public function groupTotals(): array
    {
        if ($this->groupBy === 'none') {
            return [];
        }

        // SUBSTR(entry_date, 1, 7) works on both SQLite and MySQL: journal lines
        // store entry_date as a plain Y-m-d date (see JournalLine's creating hook).
        $keyExpr = match ($this->groupBy) {
            'account' => 'journal_lines.account_id',
            'contact' => 'journal_lines.contact_id',
            'month' => 'SUBSTR(journal_lines.entry_date, 1, 7)',
            default => 'group_entries.source_type',
        };

        $rows = $this->baseQuery()
            ->when($this->groupBy === 'source', fn ($q) => $q->join('journal_entries as group_entries', 'group_entries.id', '=', 'journal_lines.journal_entry_id'))
            ->selectRaw("{$keyExpr} AS group_key, SUM(journal_lines.debit_cents) AS debit_total, SUM(journal_lines.credit_cents) AS credit_total, COUNT(*) AS line_count")
            ->groupByRaw($keyExpr)
            ->toBase()
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) ($row->group_key ?? '')] = [
                'debit' => (int) $row->debit_total,
                'credit' => (int) $row->credit_total,
                'count' => (int) $row->line_count,
            ];
        }

        return $totals;
    }

    public function groupKeyFor(JournalLine $line): string
    {
        return match ($this->groupBy) {
            'account' => (string) $line->account_id,
            'contact' => (string) ($line->contact_id ?? ''),
            'month' => substr((string) $line->entry_date?->toDateString(), 0, 7),
            'source' => (string) ($line->journalEntry?->source_type ?? ''),
            default => '',
        };
    }

    public function groupLabelFor(string $key): string
    {
        return $this->groupLabelCache[$this->groupBy.'|'.$key] ??= $this->resolveGroupLabel($key);
    }

    private function resolveGroupLabel(string $key): string
    {
        if ($this->groupBy === 'account') {
            $account = Account::find((int) $key);

            return $account !== null ? trim(($account->code ?? '').' — '.$account->name, ' —') : $key;
        }

        return match ($this->groupBy) {
            'contact' => $key === '' ? __('(No name)') : (Contact::find((int) $key)?->display_name ?? __('(No name)')),
            'source' => $key === '' ? __('Journal entry') : class_basename($key),
            default => $key,
        };
    }

    public function sourceUrl(JournalEntry $entry): ?string
    {
        return app(SourceLinkResolver::class)->urlFor($entry, $this->company);
    }

    /**
     * Lazily yield export rows for the full filtered dataset so large ranges
     * stream to the download without materialising every line at once. When
     * grouping is on, each row gains a leading 'group' label; exports carry no
     * subtotal rows (follow-up) and all columns regardless of hidden columns.
     * When filtered to a single account, each row also carries a running
     * home-currency balance; a foreign-currency account additionally carries
     * its source-currency debit/credit and a running source balance — CSV and
     * PDF exports render these; see columnRegistry for why this is account-scoped.
     *
     * @return iterable<int, array{group?: string, date: string, entry_no: ?string, account: string, name: ?string, memo: ?string, debit: int, credit: int, balance?: int, source_debit?: int, source_credit?: int, source_balance?: int}>
     */
    private function exportRows(): iterable
    {
        $grouped = $this->groupBy !== 'none';
        $account = $this->filteredAccount();
        $foreignAccount = $this->foreignAccountFilter();
        $runningHomeBalance = $account !== null ? $this->openingHomeBalanceCents() : null;
        $runningForeignBalance = $foreignAccount !== null ? $this->openingForeignBalanceCents() : null;

        foreach ($this->filteredQuery()->lazy() as $line) {
            $row = [
                'date' => (string) $line->entry_date,
                'entry_no' => $line->journalEntry?->entry_no,
                'account' => trim(($line->account?->code ?? '').' — '.($line->account?->name ?? ''), ' —'),
                'name' => $line->contact?->display_name,
                'memo' => $line->memo ?? $line->journalEntry?->memo,
                'debit' => (int) $line->debit_cents,
                'credit' => (int) $line->credit_cents,
            ];

            if ($account !== null) {
                $runningHomeBalance += $account->normal_balance === NormalBalance::Debit
                    ? (int) $line->debit_cents - (int) $line->credit_cents
                    : (int) $line->credit_cents - (int) $line->debit_cents;

                $row['balance'] = $runningHomeBalance;
            }

            if ($foreignAccount !== null) {
                $runningForeignBalance += (int) $line->foreign_debit_cents - (int) $line->foreign_credit_cents;

                $row['source_debit'] = (int) $line->foreign_debit_cents;
                $row['source_credit'] = (int) $line->foreign_credit_cents;
                $row['source_balance'] = $runningForeignBalance;
            }

            if ($grouped) {
                $row = ['group' => $this->groupLabelFor($this->groupKeyFor($line))] + $row;
            }

            yield $row;
        }
    }

    private function exportFilename(string $extension): string
    {
        return "transactions-{$this->startDate}-to-{$this->endDate}.{$extension}";
    }

    public function exportCsv()
    {
        $grouped = $this->groupBy !== 'none';
        $account = $this->filteredAccount();
        $foreignAccount = $this->foreignAccountFilter();

        $rows = (function () use ($grouped, $account, $foreignAccount) {
            foreach ($this->exportRows() as $row) {
                $cells = [
                    $row['date'],
                    $row['entry_no'],
                    $row['account'],
                    $row['name'],
                    $row['memo'],
                    CsvExporter::cents($row['debit']),
                    CsvExporter::cents($row['credit']),
                ];

                if ($account !== null) {
                    $cells[] = CsvExporter::cents($row['balance']);
                }

                if ($foreignAccount !== null) {
                    $cells[] = CsvExporter::cents($row['source_debit']);
                    $cells[] = CsvExporter::cents($row['source_credit']);
                    $cells[] = CsvExporter::cents($row['source_balance']);
                }

                if ($grouped) {
                    array_unshift($cells, $row['group']);
                }

                yield $cells;
            }
        })();

        $headers = ['Date', 'Entry #', 'Account', 'Name', 'Memo', 'Debit', 'Credit'];

        if ($account !== null) {
            $headers[] = 'Balance';
        }

        if ($foreignAccount !== null) {
            $headers[] = "Debit ({$foreignAccount->currency_code})";
            $headers[] = "Credit ({$foreignAccount->currency_code})";
            $headers[] = "Balance ({$foreignAccount->currency_code})";
        }

        if ($grouped) {
            array_unshift($headers, 'Group');
        }

        return app(CsvExporter::class)->stream($this->exportFilename('csv'), $headers, $rows);
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->transactions(
            $this->exportFilename('xlsx'),
            $this->company,
            $this->exportRows(),
            $this->startDate,
            $this->endDate,
            $this->exportContext(),
            grouped: $this->groupBy !== 'none',
        );
    }

    public function exportPdf()
    {
        // The PDF export stays ungrouped (flat rows; extra 'group' keys are
        // ignored by the template) — grouping is an on-screen + CSV/XLSX concern.
        $account = $this->filteredAccount();

        return app(PdfExporter::class)->download('pdf.reports.transactions', [
            'company' => $this->company,
            'rows' => iterator_to_array($this->exportRows()),
            'title' => $this->effectiveTitle('Transactions'),
            'period' => $this->startDate.' to '.$this->endDate,
            'context' => $this->exportContext(),
            'foreignCurrency' => $this->foreignAccountFilter()?->currency_code,
            'showBalance' => $account !== null,
            'openingBalance' => $account !== null ? $this->openingHomeBalanceCents() : null,
            'closingBalance' => $account !== null ? $this->closingHomeBalanceCents() : null,
            'openingForeignBalance' => $this->openingForeignBalanceCents(),
            'closingForeignBalance' => $this->closingForeignBalanceCents(),
        ], $this->exportFilename('pdf'));
    }

    /** Account/contact filter summary shown in export headers, or null when unfiltered. */
    private function exportContext(): ?string
    {
        $context = collect([
            trim(($this->account?->code ?? '').' — '.($this->account?->name ?? ''), ' —'),
            $this->contactFilter?->display_name,
        ])->filter(fn ($v) => trim((string) $v) !== '')->implode(' · ');

        return $context !== '' ? $context : null;
    }
}; ?>

<section class="w-full">
    @php
        $context = collect([$this->account?->code.' — '.$this->account?->name, $this->contactFilter?->display_name])
            ->filter(fn ($v) => trim((string) $v, ' —') !== '')
            ->implode(' · ');
    @endphp

    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Transactions'))"
        :subtitle="trim($context) !== '' ? $context.' · '.$startDate.' '.__('to').' '.$endDate : $startDate.' '.__('to').' '.$endDate"
        mode="range"
        :tracks-classes="$this->tracksClasses"
        :tracks-locations="$this->tracksLocations"
        :classification-options="$this->classificationOptions"
        :location-options="$this->locationOptions"
        :exports="['csv', 'xlsx', 'pdf']"
        :title-editable="false"
        :memorizable="true"
        :emailable="$this->canEmailReport()"
        :print-url="$this->printReportUrl()"
    >
        <flux:select wire:model.live="groupBy" :label="__('Group by')" class="max-w-[170px]" data-test="group-by">
            <flux:select.option value="none">{{ __('None') }}</flux:select.option>
            <flux:select.option value="account">{{ __('Account') }}</flux:select.option>
            <flux:select.option value="contact">{{ __('Name') }}</flux:select.option>
            <flux:select.option value="month">{{ __('Month') }}</flux:select.option>
            <flux:select.option value="source">{{ __('Source type') }}</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="sourceType" :label="__('Type')" class="max-w-[180px]" data-test="filter-source-type">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            <flux:select.option value="journal">{{ __('Journal entry') }}</flux:select.option>
            @foreach (array_keys($this->sourceTypeMap()) as $basename)
                <flux:select.option value="{{ $basename }}">{{ $basename }}</flux:select.option>
            @endforeach
        </flux:select>

        <x-reports.column-picker :columns="$this->columnRegistry()" />
    </x-reports.control-bar>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    @if ($this->columnVisible('entry_no'))
                        <th class="px-4 py-2 text-left">{{ __('Entry #') }}</th>
                    @endif
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    @if ($this->columnVisible('name'))
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    @endif
                    @if ($this->columnVisible('memo'))
                        <th class="px-4 py-2 text-left">{{ __('Memo') }}</th>
                    @endif
                    <th class="px-4 py-2 text-right">{{ __('Debit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Credit') }}</th>
                    @if ($this->columnVisible('balance'))
                        <th class="px-4 py-2 text-right">{{ __('Balance') }}</th>
                    @endif
                    @if ($this->columnVisible('source_debit'))
                        <th class="px-4 py-2 text-right">{{ $this->columnRegistry()['source_debit'] }}</th>
                        <th class="px-4 py-2 text-right">{{ $this->columnRegistry()['source_credit'] }}</th>
                    @endif
                    @if ($this->columnVisible('source_balance'))
                        <th class="px-4 py-2 text-right">{{ $this->columnRegistry()['source_balance'] }}</th>
                    @endif
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @php
                    $runningForeignBalance = $this->runningForeignBalanceBeforePage();
                    $runningHomeBalance = $this->runningHomeBalanceBeforePage();
                    $pageLines = $this->lines->items();
                    $fullSpan = $this->visibleColumnCount(fixed: 5);
                @endphp
                @if ($this->columnVisible('balance') && $this->lines->currentPage() === 1)
                    <tr class="bg-muted/50" data-test="opening-balance-row">
                        <td colspan="{{ $fullSpan - 2 - ($this->columnVisible('source_balance') ? 1 : 0) }}" class="px-4 py-2 text-right font-medium">{{ __('Opening Balance') }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($this->openingHomeBalanceCents() / 100, 2) }}</td>
                        @if ($this->columnVisible('source_balance'))
                            <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($this->openingForeignBalanceCents() / 100, 2) }}</td>
                        @endif
                        <td class="px-4 py-2"></td>
                    </tr>
                @endif
                @forelse ($pageLines as $i => $line)
                    @php
                        $url = $this->sourceUrl($line->journalEntry);
                        $groupKey = $groupBy !== 'none' ? $this->groupKeyFor($line) : null;
                        $prevKey = $groupBy !== 'none' && $i > 0 ? $this->groupKeyFor($pageLines[$i - 1]) : null;
                        $nextKey = $groupBy !== 'none' && isset($pageLines[$i + 1]) ? $this->groupKeyFor($pageLines[$i + 1]) : null;
                    @endphp

                    @if ($groupBy !== 'none' && ($i === 0 || $groupKey !== $prevKey))
                        <tr class="bg-muted" data-test="txn-group-header">
                            <td colspan="{{ $fullSpan }}" class="px-4 py-2 font-medium">
                                {{-- Grouped by name: the header drills to that name's own transactions too. --}}
                                @if ($groupBy === 'contact' && $groupKey !== '' && (int) $groupKey !== (int) $contactId)
                                    <a href="{{ route('reports.transactions', ['company' => $company->slug, 'contact' => (int) $groupKey, 'start' => $startDate, 'end' => $endDate]) }}" wire:navigate class="hover:underline" data-test="drill-contact-group">{{ $this->groupLabelFor($groupKey) }}</a>
                                @else
                                    {{ $this->groupLabelFor($groupKey) }}
                                @endif
                            </td>
                        </tr>
                    @endif

                    <tr data-test="txn-row">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $line->entry_date }}</td>
                        @if ($this->columnVisible('entry_no'))
                            <td class="px-4 py-2 font-mono">{{ $line->journalEntry?->entry_no }}</td>
                        @endif
                        <td class="px-4 py-2">{{ $line->account?->code }} — {{ $line->account?->name }}</td>
                        @if ($this->columnVisible('name'))
                            <td class="px-4 py-2">
                                {{-- Drill to that name's own transactions, unless the report is already filtered to it. --}}
                                @if ($line->contact_id && (int) $line->contact_id !== (int) $contactId)
                                    <a href="{{ route('reports.transactions', ['company' => $company->slug, 'contact' => $line->contact_id, 'start' => $startDate, 'end' => $endDate]) }}" wire:navigate class="hover:underline" data-test="drill-contact">{{ $line->contact?->display_name }}</a>
                                @else
                                    {{ $line->contact?->display_name }}
                                @endif
                            </td>
                        @endif
                        @if ($this->columnVisible('memo'))
                            <td class="px-4 py-2 text-muted-foreground">{{ $line->memo ?? $line->journalEntry?->memo }}</td>
                        @endif
                        <td class="px-4 py-2 text-right font-mono">{{ $line->debit_cents ? number_format($line->debit_cents / 100, 2) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $line->credit_cents ? number_format($line->credit_cents / 100, 2) : '' }}</td>
                        @if ($this->columnVisible('balance'))
                            @php
                                $runningHomeBalance += $this->filteredAccount()->normal_balance === NormalBalance::Debit
                                    ? (int) $line->debit_cents - (int) $line->credit_cents
                                    : (int) $line->credit_cents - (int) $line->debit_cents;
                            @endphp
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($runningHomeBalance / 100, 2) }}</td>
                        @endif
                        @if ($this->columnVisible('source_debit'))
                            <td class="px-4 py-2 text-right font-mono">{{ $line->foreign_debit_cents ? number_format($line->foreign_debit_cents / 100, 2) : '' }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ $line->foreign_credit_cents ? number_format($line->foreign_credit_cents / 100, 2) : '' }}</td>
                        @endif
                        @if ($this->columnVisible('source_balance'))
                            @php
                                $runningForeignBalance += (int) $line->foreign_debit_cents - (int) $line->foreign_credit_cents;
                            @endphp
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($runningForeignBalance / 100, 2) }}</td>
                        @endif
                        <td class="px-4 py-2 text-right">
                            @if ($url)
                                <flux:button :href="$url" wire:navigate variant="ghost" size="xs" icon="arrow-top-right-on-square" data-test="txn-source-link">{{ __('Open') }}</flux:button>
                            @endif
                        </td>
                    </tr>

                    @if ($groupBy !== 'none' && $groupKey !== $nextKey)
                        @php $groupSum = $this->groupTotals[$groupKey] ?? ['debit' => 0, 'credit' => 0, 'count' => 0]; @endphp
                        <tr class="bg-muted/50" data-test="txn-group-subtotal">
                            <td colspan="{{ $fullSpan - 3 }}" class="px-4 py-2 text-right font-medium">
                                {{ __('Total :label (:count lines)', ['label' => $this->groupLabelFor($groupKey), 'count' => $groupSum['count']]) }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($groupSum['debit'] / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($groupSum['credit'] / 100, 2) }}</td>
                            <td class="px-4 py-2"></td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="{{ $fullSpan }}" class="px-4 py-6 text-center text-muted-foreground">{{ __('No transactions match these filters.') }}</td></tr>
                @endforelse
                @if ($this->columnVisible('balance') && $this->lines->currentPage() === $this->lines->lastPage())
                    <tr class="bg-muted/50 font-semibold" data-test="closing-balance-row">
                        <td colspan="{{ $fullSpan - 2 - ($this->columnVisible('source_balance') ? 1 : 0) }}" class="px-4 py-2 text-right font-medium">{{ __('Closing Balance') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($this->closingHomeBalanceCents() / 100, 2) }}</td>
                        @if ($this->columnVisible('source_balance'))
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($this->closingForeignBalanceCents() / 100, 2) }}</td>
                        @endif
                        <td class="px-4 py-2"></td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->lines->links() }}
    </div>
</section>
