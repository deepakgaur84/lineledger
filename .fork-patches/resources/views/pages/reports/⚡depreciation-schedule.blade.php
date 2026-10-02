<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Concerns\Memorizable;
use App\Models\Asset;
use App\Models\AssetDepreciationEntry;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One row per asset, grouped by category — the fixed-asset register as a
 * proper, exportable report rather than the operational on-screen list at
 * Accounting → Fixed assets. Built directly against a Xero "Depreciation
 * Schedule" export: the same roll-forward shape (Opening + Purchases −
 * Disposals − Depreciation = Closing), the same per-category subtotals and a
 * grand total, and the same idea of a column picker so unneeded columns can
 * be hidden rather than always shown.
 *
 * Two of the reference report's own columns — Sale Price and Dep Recovered —
 * are deliberately not offered at all, not just hidden by default: LineLedger
 * tracks no disposal-proceeds or gain/loss-on-disposal data whatsoever (a
 * disposal today is a register-only status flag, confirmed in §11 of
 * README-FORK-PATCHES.md), so those two columns could only ever show blank.
 * Offering a column that can never have data is worse than not offering it.
 * "Disposals" here is the asset's own net book value at its disposal date —
 * the roll-forward's write-off amount — not a sale price.
 *
 * "Purchases" and "Disposals" are period-relative (did this asset arrive or
 * leave the register DURING the period), computed the same way the
 * Reconciliation report (§11) computes its own on-register-as-of-date check,
 * not a running total. An asset acquired or disposed on a boundary date
 * (exactly on $opening or exactly on $closing) counts as happening within
 * this period, consistent with how the opening/closing NBV figures already
 * treat those same two dates as inclusive.
 */
new #[Title('Depreciation Schedule')] class extends Component {
    use EmailsReport;
    use HasCustomReportHeader;
    use HasReportDateRange;
    use Memorizable;

    public Company $company;

    /**
     * Every column this report can show, in the fixed order they always
     * render — this order never changes based on selection order, only
     * visibility does, via $hiddenColumns.
     *
     * @var array<string, string>
     */
    private const ALL_COLUMNS = [
        'asset_no' => 'Number',
        'name' => 'Name',
        'category' => 'Category',
        'method' => 'Method',
        'rate' => 'Rate',
        'useful_life' => 'Useful life (months)',
        'cost' => 'Cost',
        'opening_accum_dep' => 'Opening Accum Dep',
        'opening_value' => 'Opening Value',
        'purchased' => 'Purchased',
        'purchases' => 'Purchases',
        'disposed' => 'Disposed',
        'disposals' => 'Disposals',
        'depreciation' => 'Depreciation',
        'closing_accum_dep' => 'Closing Accum Dep',
        'closing_value' => 'Closing Value',
        'status' => 'Status',
    ];

    /**
     * The columns shown the first time anyone opens this report, matching
     * what was asked for directly rather than guessed at.
     *
     * @var list<string>
     */
    private const DEFAULT_VISIBLE = [
        'asset_no', 'name', 'method', 'rate', 'opening_value',
        'purchased', 'purchases', 'disposed', 'disposals',
        'depreciation', 'closing_value',
    ];

    /** @var list<string> */
    public array $hiddenColumns = [];

    public bool $includeDisposed = true;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
        $this->hiddenColumns = array_values(array_diff(array_keys(self::ALL_COLUMNS), self::DEFAULT_VISIBLE));
        $this->applyMemorized((int) request('memorized'));
    }

    protected function reportKey(): string
    {
        return 'reports.depreciation-schedule';
    }

    /**
     * @return array<string, string>
     */
    public function columnOptions(): array
    {
        return self::ALL_COLUMNS;
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function visibleColumns(): array
    {
        return array_values(array_diff(array_keys(self::ALL_COLUMNS), $this->hiddenColumns));
    }

    public function toggleColumn(string $key): void
    {
        if (! array_key_exists($key, self::ALL_COLUMNS)) {
            return;
        }

        $this->hiddenColumns = in_array($key, $this->hiddenColumns, true)
            ? array_values(array_diff($this->hiddenColumns, [$key]))
            : [...$this->hiddenColumns, $key];
    }

    /**
     * @return array{
     *     groups: list<array{label: string, rows: list<array<string, mixed>>, totals: array<string, int>}>,
     *     grand_totals: array<string, int>,
     * }
     */
    #[Computed]
    public function report(): array
    {
        $opening = CarbonImmutable::parse($this->startDate)->subDay();
        $closing = CarbonImmutable::parse($this->endDate);

        $assets = Asset::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->when(! $this->includeDisposed, fn ($q) => $q->where('status', 'in-service'))
            ->with('category')
            ->get();

        $depreciationByAsset = AssetDepreciationEntry::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereIn('asset_id', $assets->pluck('id'))
            ->get(['asset_id', 'period', 'amount_cents'])
            ->groupBy('asset_id');

        $buckets = $assets->groupBy(fn (Asset $asset): string => $asset->asset_category_id !== null ? (string) $asset->asset_category_id : 'none');

        $groups = [];

        foreach ($buckets as $key => $assetsInGroup) {
            $label = $key === 'none' ? __('Uncategorized') : ($assetsInGroup->first()->category?->name ?? __('Uncategorized'));

            $rows = $assetsInGroup
                ->map(fn (Asset $asset): array => $this->rowFor($asset, $depreciationByAsset, $opening, $closing))
                ->sortBy('name')
                ->values()
                ->all();

            $groups[] = [
                'label' => $label,
                'rows' => $rows,
                'totals' => $this->sumRows($rows),
            ];
        }

        usort($groups, fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        $allRows = [];
        foreach ($groups as $group) {
            $allRows = [...$allRows, ...$group['rows']];
        }

        return ['groups' => $groups, 'grand_totals' => $this->sumRows($allRows)];
    }

    /**
     * One report row for one asset. The money fields are always computed,
     * regardless of which columns are currently visible — visibility is a
     * display concern only, and keeping this independent of it means
     * toggling a column can never change anyone else's numbers.
     *
     * @param  Collection<int, Collection<int, AssetDepreciationEntry>>  $depreciationByAsset
     * @return array<string, mixed>
     */
    private function rowFor(Asset $asset, Collection $depreciationByAsset, CarbonImmutable $opening, CarbonImmutable $closing): array
    {
        $costCents = (int) $asset->cost_cents;

        $accumAsOf = function (CarbonImmutable $date) use ($asset, $depreciationByAsset): int {
            return (int) ($depreciationByAsset->get($asset->id) ?? collect())
                ->filter(fn (AssetDepreciationEntry $entry): bool => $entry->period->toDateString() <= $date->toDateString())
                ->sum('amount_cents');
        };

        $onRegisterAsOf = function (CarbonImmutable $date) use ($asset): bool {
            if ($asset->acquired_date === null || $asset->acquired_date->toDateString() > $date->toDateString()) {
                return false;
            }

            return $asset->disposed_at === null || $asset->disposed_at->toDateString() > $date->toDateString();
        };

        $openingAccum = $onRegisterAsOf($opening) ? $accumAsOf($opening) : 0;
        $closingAccum = $onRegisterAsOf($closing) ? $accumAsOf($closing) : 0;
        $openingValue = $onRegisterAsOf($opening) ? $costCents - $openingAccum : 0;
        $closingValue = $onRegisterAsOf($closing) ? $costCents - $closingAccum : 0;

        $acquiredInPeriod = $asset->acquired_date !== null
            && $asset->acquired_date->toDateString() > $opening->toDateString()
            && $asset->acquired_date->toDateString() <= $closing->toDateString();

        $disposedInPeriod = $asset->disposed_at !== null
            && $asset->disposed_at->toDateString() > $opening->toDateString()
            && $asset->disposed_at->toDateString() <= $closing->toDateString();

        $depreciationInPeriod = (int) ($depreciationByAsset->get($asset->id) ?? collect())
            ->filter(function (AssetDepreciationEntry $entry) use ($opening, $closing): bool {
                return $entry->period->toDateString() > $opening->toDateString()
                    && $entry->period->toDateString() <= $closing->toDateString();
            })
            ->sum('amount_cents');

        $method = $asset->depreciationMethod();

        return [
            'asset_no' => $asset->asset_no,
            'name' => $asset->name,
            'category' => $asset->category?->name,
            'method' => $method->label(),
            'rate' => $asset->depreciation_rate !== null ? (float) $asset->depreciation_rate : null,
            'useful_life' => $asset->useful_life_months,
            'cost_cents' => $costCents,
            'opening_accum_cents' => $openingAccum,
            'opening_value_cents' => $openingValue,
            'purchased_date' => $asset->acquired_date?->toDateString(),
            'purchases_cents' => $acquiredInPeriod ? $costCents : 0,
            'disposed_date' => $asset->disposed_at?->toDateString(),
            // The NBV written off at disposal — computed as of the disposal date
            // itself, independent of $openingValue/$closingValue (which are 0 for
            // this asset once disposed), so the amount actually removed from the
            // register is never lost even though the roll-forward's own opening/
            // closing columns correctly show nothing for a disposed asset.
            'disposals_cents' => $disposedInPeriod ? $costCents - $accumAsOf($asset->disposed_at) : 0,
            'depreciation_cents' => $depreciationInPeriod,
            'closing_accum_cents' => $closingAccum,
            'closing_value_cents' => $closingValue,
            'status' => $asset->status->label(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function sumRows(array $rows): array
    {
        $fields = ['cost_cents', 'opening_accum_cents', 'opening_value_cents', 'purchases_cents', 'disposals_cents', 'depreciation_cents', 'closing_accum_cents', 'closing_value_cents'];
        $totals = array_fill_keys($fields, 0);

        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $totals[$field] += $row[$field];
            }
        }

        return $totals;
    }

    public function exportCsv()
    {
        $r = $this->report;
        $visible = $this->visibleColumns;
        $rows = collect();

        foreach ($r['groups'] as $group) {
            $rows->push([$group['label']]);

            foreach ($group['rows'] as $row) {
                $rows->push(array_map(fn (string $col): string => $this->cellText($col, $row), $visible));
            }

            $rows->push(array_map(fn (string $col): string => $this->cellText($col, $group['totals'], isTotalsRow: true, label: __('Total :name', ['name' => $group['label']])), $visible));
        }

        return app(CsvExporter::class)->stream(
            'depreciation-schedule-'.$this->startDate.'-to-'.$this->endDate.'.csv',
            array_map(fn (string $col): string => self::ALL_COLUMNS[$col], $visible),
            $rows,
        );
    }

    public function exportPdf()
    {
        $r = $this->report;
        $visible = $this->visibleColumns;

        // Pre-formatted into plain strings here, not passed as raw data for the
        // view to format itself — the view belongs to a separate render pass
        // (PdfExporter::download() renders it outside this component entirely)
        // with no access to this class's own cellText(), an anonymous class's
        // private method. Formatting once, here, is what keeps this export and
        // the on-screen table — and the CSV export above — all agreeing on
        // what a given cell actually says, rather than three separate places
        // each with their own copy of the same formatting rules to drift apart.
        $groups = array_map(fn (array $group): array => [
            'label' => $group['label'],
            'rows' => array_map(fn (array $row): array => array_map(fn (string $col): string => $this->cellText($col, $row), $visible), $group['rows']),
            'totals' => array_map(fn (string $col): string => $this->cellText($col, $group['totals'], isTotalsRow: true, label: $col === 'name' ? __('Total :name', ['name' => $group['label']]) : null), $visible),
        ], $r['groups']);

        $grandTotals = array_map(fn (string $col): string => $this->cellText($col, $r['grand_totals'], isTotalsRow: true, label: $col === 'name' ? __('Total') : null), $visible);

        return app(PdfExporter::class)->download('pdf.reports.depreciation-schedule', [
            'company' => $this->company,
            'groups' => $groups,
            'grandTotals' => $grandTotals,
            'columnLabels' => array_map(fn (string $col): string => self::ALL_COLUMNS[$col], $visible),
            'alignRight' => array_map(fn (string $col): bool => ! in_array($col, ['asset_no', 'name', 'category', 'method', 'purchased', 'disposed', 'status'], true), $visible),
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'title' => $this->effectiveTitle('Depreciation Schedule'),
        ], "depreciation-schedule-{$this->startDate}-to-{$this->endDate}.pdf");
    }

    /**
     * One cell's display text for a given column — shared between the CSV
     * export and the inline summary row helper below, so the two can never
     * drift apart on what a given field actually means.
     *
     * @param  array<string, mixed>  $row
     */
    private function cellText(string $col, array $row, bool $isTotalsRow = false, ?string $label = null): string
    {
        if ($isTotalsRow) {
            return match ($col) {
                'name' => $label ?? '',
                'cost' => number_format($row['cost_cents'] / 100, 2),
                'opening_accum_dep' => number_format($row['opening_accum_cents'] / 100, 2),
                'opening_value' => number_format($row['opening_value_cents'] / 100, 2),
                'purchases' => number_format($row['purchases_cents'] / 100, 2),
                'disposals' => number_format($row['disposals_cents'] / 100, 2),
                'depreciation' => number_format($row['depreciation_cents'] / 100, 2),
                'closing_accum_dep' => number_format($row['closing_accum_cents'] / 100, 2),
                'closing_value' => number_format($row['closing_value_cents'] / 100, 2),
                default => '',
            };
        }

        return match ($col) {
            'asset_no' => (string) $row['asset_no'],
            'name' => (string) $row['name'],
            'category' => (string) ($row['category'] ?? ''),
            'method' => (string) $row['method'],
            'rate' => $row['rate'] !== null ? rtrim(rtrim(number_format($row['rate'], 3, '.', ''), '0'), '.') : '',
            'useful_life' => (string) ($row['useful_life'] ?? ''),
            'cost' => number_format($row['cost_cents'] / 100, 2),
            'opening_accum_dep' => number_format($row['opening_accum_cents'] / 100, 2),
            'opening_value' => number_format($row['opening_value_cents'] / 100, 2),
            'purchased' => (string) ($row['purchased_date'] ?? ''),
            'purchases' => number_format($row['purchases_cents'] / 100, 2),
            'disposed' => (string) ($row['disposed_date'] ?? ''),
            'disposals' => number_format($row['disposals_cents'] / 100, 2),
            'depreciation' => number_format($row['depreciation_cents'] / 100, 2),
            'closing_accum_dep' => number_format($row['closing_accum_cents'] / 100, 2),
            'closing_value' => number_format($row['closing_value_cents'] / 100, 2),
            'status' => (string) $row['status'],
            default => '',
        };
    }
}; ?>

<div>
    <x-reports.control-bar
        title="{{ $this->effectiveTitle('Depreciation Schedule') }}"
        subtitle="{{ __('For the period :start to :end', ['start' => \Illuminate\Support\Carbon::parse($startDate)->format('j M Y'), 'end' => \Illuminate\Support\Carbon::parse($endDate)->format('j M Y')]) }}"
        :titleEditable="true"
        :memorizable="true"
        :emailable="true"
        :exports="['csv', 'pdf']"
        :print-url="$this->printReportUrl()"
    />

    <div class="mb-6 flex flex-wrap items-center gap-4">
        <flux:switch wire:model.live="includeDisposed" :label="__('Include disposed assets')" data-test="schedule-include-disposed" />

        <flux:dropdown align="start" data-test="schedule-columns-picker">
            <flux:button variant="ghost" icon="adjustments-horizontal" icon:trailing="chevron-down">{{ __('Columns') }}</flux:button>
            <flux:menu>
                @foreach ($this->columnOptions() as $key => $colLabel)
                    <flux:menu.checkbox wire:click="toggleColumn('{{ $key }}')" :checked="in_array($key, $this->visibleColumns, true)" keep-open>{{ __($colLabel) }}</flux:menu.checkbox>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    </div>

    @php
        $r = $this->report;
        $visible = $this->visibleColumns;
    @endphp

    @if ($r['groups'] === [])
        <flux:text class="text-muted-foreground">{{ __('No fixed assets recorded yet.') }}</flux:text>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-test="schedule-table">
                <thead>
                    <tr class="border-b border-border">
                        @foreach ($visible as $col)
                            <th class="px-4 py-2 {{ in_array($col, ['asset_no', 'name', 'category', 'method', 'purchased', 'disposed', 'status'], true) ? 'text-left' : 'text-right' }}">{{ __($this->columnOptions()[$col]) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($r['groups'] as $group)
                        <tr class="border-b border-border">
                            <td colspan="{{ count($visible) }}" class="px-4 pt-4 pb-1 font-semibold">{{ $group['label'] }}</td>
                        </tr>
                        @foreach ($group['rows'] as $row)
                            <tr data-test="schedule-row">
                                @foreach ($visible as $col)
                                    <td class="px-4 py-1 {{ in_array($col, ['asset_no', 'name', 'category', 'method', 'purchased', 'disposed', 'status'], true) ? '' : 'text-right font-mono' }}">{{ $this->cellText($col, $row) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr class="border-t border-border font-semibold" data-test="schedule-group-total">
                            @foreach ($visible as $col)
                                <td class="px-4 py-2 {{ in_array($col, ['asset_no', 'name', 'category', 'method', 'purchased', 'disposed', 'status'], true) ? '' : 'text-right font-mono' }}">{{ $this->cellText($col, $group['totals'], isTotalsRow: true, label: $col === 'name' ? __('Total :name', ['name' => $group['label']]) : null) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-border font-semibold" data-test="schedule-grand-total">
                        @foreach ($visible as $col)
                            <td class="px-4 py-2 {{ in_array($col, ['asset_no', 'name', 'category', 'method', 'purchased', 'disposed', 'status'], true) ? '' : 'text-right font-mono' }}">{{ $this->cellText($col, $r['grand_totals'], isTotalsRow: true, label: $col === 'name' ? __('Total') : null) }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</div>
