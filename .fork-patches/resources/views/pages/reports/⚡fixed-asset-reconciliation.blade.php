<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Concerns\Memorizable;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetDepreciationEntry;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Compares the fixed-asset register (the `assets` table plus the depreciation
 * actually posted through it) against what the general ledger itself says, at
 * the start and end of a period — the same shape as Xero's own Fixed Asset
 * Reconciliation report, which this was built directly against.
 *
 * A difference here means one of two things: either the register has an asset
 * (or a depreciation entry) whose cost never made it into the GL through a
 * bill, cheque, or journal entry — a real risk with the register-only Bulk
 * Import and Opening Balances importers — or the GL has activity on an asset
 * account that the register knows nothing about. Either way, this report is
 * read-only: it never corrects anything, it only shows where the two
 * disagree.
 *
 * Accumulated Depreciation accounts are seeded with AccountSubtype::FixedAsset
 * (an Asset-type account, confirmed directly against the seeders), so their
 * GL balance comes back NEGATIVE from ReportCalculator::balanceAsOf() — a
 * credit balance sitting on a debit-normal account type. That is negated
 * below so the GL side's Accum Dep is a positive magnitude, matching both the
 * Register side (already positive, since AssetDepreciationEntry.amount_cents
 * always is) and the reference report this was built from.
 */
new #[Title('Fixed Asset Reconciliation')] class extends Component {
    use EmailsReport;
    use HasCustomReportHeader;
    use HasReportDateRange;
    use Memorizable;

    public Company $company;

    #[Url(as: 'by')]
    public string $groupBy = 'account';

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
        $this->applyMemorized((int) request('memorized'));
    }

    protected function reportKey(): string
    {
        return 'reports.fixed-asset-reconciliation';
    }

    /**
     * @return array{
     *     group_by: string,
     *     groups: list<array{label: string, opening: array<string, int>, closing: array<string, int>}>,
     *     totals: array{opening: array<string, int>, closing: array<string, int>},
     * }
     */
    #[Computed]
    public function report(): array
    {
        $opening = CarbonImmutable::parse($this->startDate)->subDay();
        $closing = CarbonImmutable::parse($this->endDate);

        $assets = Asset::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->with('category')
            ->get();

        $depreciationByAsset = AssetDepreciationEntry::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereIn('asset_id', $assets->pluck('id'))
            ->get(['asset_id', 'period', 'amount_cents'])
            ->groupBy('asset_id');

        $buckets = $this->groupBy === 'category'
            ? $assets->groupBy(fn (Asset $asset): string => $asset->asset_category_id !== null ? (string) $asset->asset_category_id : 'none')
            : $assets->groupBy(fn (Asset $asset): string => (string) $asset->asset_account_id);

        $groups = [];

        foreach ($buckets as $key => $assetsInGroup) {
            $label = $this->groupBy === 'category'
                ? ($key === 'none' ? __('Uncategorized') : ($assetsInGroup->first()->category?->name ?? __('Uncategorized')))
                : (Account::withoutGlobalScopes()->find((int) $key)?->name ?? __('Unknown account'));

            [$assetAccountIds, $accumAccountIds] = $this->glAccountsForGroup($key, $assetsInGroup);

            $groups[] = [
                'label' => $label,
                'opening' => $this->figuresFor($assetsInGroup, $depreciationByAsset, $assetAccountIds, $accumAccountIds, $opening),
                'closing' => $this->figuresFor($assetsInGroup, $depreciationByAsset, $assetAccountIds, $accumAccountIds, $closing),
            ];
        }

        usort($groups, fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        $totals = ['opening' => $this->emptyFigures(), 'closing' => $this->emptyFigures()];

        foreach ($groups as $group) {
            foreach (['opening', 'closing'] as $when) {
                foreach ($group[$when] as $field => $amount) {
                    $totals[$when][$field] += $amount;
                }
            }
        }

        return ['group_by' => $this->groupBy, 'groups' => $groups, 'totals' => $totals];
    }

    /**
     * Which GL accounts represent this group's Balance Sheet side.
     *
     * Grouping by account: the group's own key, and every distinct
     * accumulated-depreciation account its assets actually use (almost always
     * one, but summed rather than assumed).
     *
     * Grouping by category: the category's own default accounts when it has
     * them — so an individual asset drifting onto a different account shows
     * up as a genuine difference, not something silently absorbed. A category
     * (or "Uncategorized") with no default falls back to whatever accounts
     * its assets actually use, the same as grouping by account.
     *
     * @param  Collection<int, Asset>  $assetsInGroup
     * @return array{0: list<int>, 1: list<int>}
     */
    private function glAccountsForGroup(string $key, Collection $assetsInGroup): array
    {
        if ($this->groupBy === 'account') {
            $accumIds = $assetsInGroup->pluck('accumulated_depreciation_account_id')->filter()->unique()->values()->all();

            return [[(int) $key], $accumIds];
        }

        $category = $key !== 'none' ? $assetsInGroup->first()->category : null;

        if ($category?->default_asset_account_id !== null) {
            $accumId = $category->default_accumulated_depreciation_account_id;

            return [[$category->default_asset_account_id], $accumId !== null ? [$accumId] : []];
        }

        $assetIds = $assetsInGroup->pluck('asset_account_id')->filter()->unique()->values()->all();
        $accumIds = $assetsInGroup->pluck('accumulated_depreciation_account_id')->filter()->unique()->values()->all();

        return [$assetIds, $accumIds];
    }

    /**
     * @return array<string, int>
     */
    private function emptyFigures(): array
    {
        return [
            'bs_cost' => 0, 'bs_accum' => 0, 'bs_book' => 0,
            'reg_cost' => 0, 'reg_accum' => 0, 'reg_book' => 0,
            'diff_cost' => 0, 'diff_accum' => 0, 'diff_book' => 0,
        ];
    }

    /**
     * One column's worth (Opening or Closing) of every figure for one group,
     * as of one date.
     *
     * "On the register as of $date" — acquired by then, and not yet disposed
     * — is the single rule governing both Register Cost and Register Accum
     * Dep, so a disposed asset drops out of both together rather than lingering
     * in one column and not the other. This deliberately does NOT check
     * whether the GL itself still carries the disposed asset (LineLedger's
     * disposal is a register-only status flag today, with no journal entry
     * of its own) — so a disposal with nothing removed from the GL correctly
     * surfaces here as a difference, which is exactly the kind of gap this
     * report exists to catch.
     *
     * @param  Collection<int, Asset>  $assetsInGroup
     * @param  Collection<int, Collection<int, AssetDepreciationEntry>>  $depreciationByAsset
     * @param  list<int>  $assetAccountIds
     * @param  list<int>  $accumAccountIds
     * @return array<string, int>
     */
    private function figuresFor(Collection $assetsInGroup, Collection $depreciationByAsset, array $assetAccountIds, array $accumAccountIds, CarbonImmutable $date): array
    {
        $onRegister = $assetsInGroup->filter(function (Asset $asset) use ($date): bool {
            if ($asset->acquired_date === null || $asset->acquired_date->toDateString() > $date->toDateString()) {
                return false;
            }

            return $asset->disposed_at === null || $asset->disposed_at->toDateString() > $date->toDateString();
        });

        $regCost = (int) $onRegister->sum('cost_cents');

        $regAccum = (int) $onRegister->sum(function (Asset $asset) use ($depreciationByAsset, $date): int {
            return ($depreciationByAsset->get($asset->id) ?? collect())
                ->filter(fn (AssetDepreciationEntry $entry): bool => $entry->period->toDateString() <= $date->toDateString())
                ->sum('amount_cents');
        });

        $calculator = app(ReportCalculator::class);

        $bsCost = array_sum(array_map(
            fn (int $id): int => $calculator->balanceAsOf(Account::withoutGlobalScopes()->find($id), $date),
            $assetAccountIds
        ));

        // Negated — see the class docblock for why Accum Dep comes back negative.
        $bsAccum = -array_sum(array_map(
            fn (int $id): int => $calculator->balanceAsOf(Account::withoutGlobalScopes()->find($id), $date),
            $accumAccountIds
        ));

        return [
            'bs_cost' => $bsCost, 'bs_accum' => $bsAccum, 'bs_book' => $bsCost - $bsAccum,
            'reg_cost' => $regCost, 'reg_accum' => $regAccum, 'reg_book' => $regCost - $regAccum,
            'diff_cost' => $bsCost - $regCost, 'diff_accum' => $bsAccum - $regAccum, 'diff_book' => ($bsCost - $bsAccum) - ($regCost - $regAccum),
        ];
    }

    public function exportCsv()
    {
        $r = $this->report;
        $rows = collect();

        foreach ($r['groups'] as $group) {
            $rows->push([$group['label'], '', '', '', '', '', '']);
            foreach (['opening' => __('Opening'), 'closing' => __('Closing')] as $key => $rowLabel) {
                $f = $group[$key];
                $rows->push(['  '.$rowLabel.' — '.__('Balance Sheet'), CsvExporter::cents($f['bs_cost']), CsvExporter::cents($f['bs_accum']), CsvExporter::cents($f['bs_book']), '', '', '']);
                $rows->push(['  '.$rowLabel.' — '.__('Asset Register'), CsvExporter::cents($f['reg_cost']), CsvExporter::cents($f['reg_accum']), CsvExporter::cents($f['reg_book']), '', '', '']);
                $rows->push(['  '.$rowLabel.' — '.__('Difference'), CsvExporter::cents($f['diff_cost']), CsvExporter::cents($f['diff_accum']), CsvExporter::cents($f['diff_book']), '', '', '']);
            }
        }

        return app(CsvExporter::class)->stream(
            'fixed-asset-reconciliation-'.$this->startDate.'-to-'.$this->endDate.'.csv',
            ['Source', 'Cost', 'Accum Dep', 'Book Value', '', '', ''],
            $rows,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.fixed-asset-reconciliation', [
            'company' => $this->company,
            'report' => $this->report,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'title' => $this->effectiveTitle('Fixed Asset Reconciliation'),
        ], "fixed-asset-reconciliation-{$this->startDate}-to-{$this->endDate}.pdf");
    }
}; ?>

<div>
    <x-reports.control-bar
        title="{{ $this->effectiveTitle('Fixed Asset Reconciliation') }}"
        :exports="['csv', 'pdf']"
        subtitle="{{ __('For the period :start to :end', ['start' => \Illuminate\Support\Carbon::parse($startDate)->format('j M Y'), 'end' => \Illuminate\Support\Carbon::parse($endDate)->format('j M Y')]) }}"
        :titleEditable="true"
        :memorizable="true"
        :emailable="true"
        :print-url="$this->printReportUrl()"
    />

    <div class="mb-6">
        <flux:radio.group wire:model.live="groupBy" variant="segmented" :label="__('Group by')" data-test="recon-group-by">
            <flux:radio value="account" :label="__('Account')" />
            <flux:radio value="category" :label="__('Category')" />
        </flux:radio.group>
    </div>

    @php
        $r = $this->report;
    @endphp

    @if ($r['groups'] === [])
        <flux:text class="text-muted-foreground">{{ __('No fixed assets recorded yet.') }}</flux:text>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-test="recon-table">
                <thead>
                    <tr class="border-b border-border">
                        <th class="px-4 py-2 text-left">{{ __('Source') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Opening Cost') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Opening Accum Dep') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Opening Book Value') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Closing Cost') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Closing Accum Dep') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Closing Book Value') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($r['groups'] as $group)
                        <tr class="border-b border-border">
                            <td colspan="7" class="px-4 pt-4 pb-1 font-semibold">{{ $group['label'] }}</td>
                        </tr>
                        @foreach ([__('Balance Sheet') => 'bs', __('Asset Register') => 'reg'] as $rowLabel => $prefix)
                            <tr data-test="recon-row">
                                <td class="px-4 py-1">{{ $rowLabel }}</td>
                                <td class="px-4 py-1 text-right font-mono">{{ number_format($group['opening'][$prefix.'_cost'] / 100, 2) }}</td>
                                <td class="px-4 py-1 text-right font-mono">{{ number_format($group['opening'][$prefix.'_accum'] / 100, 2) }}</td>
                                <td class="px-4 py-1 text-right font-mono">{{ number_format($group['opening'][$prefix.'_book'] / 100, 2) }}</td>
                                <td class="px-4 py-1 text-right font-mono">{{ number_format($group['closing'][$prefix.'_cost'] / 100, 2) }}</td>
                                <td class="px-4 py-1 text-right font-mono">{{ number_format($group['closing'][$prefix.'_accum'] / 100, 2) }}</td>
                                <td class="px-4 py-1 text-right font-mono">{{ number_format($group['closing'][$prefix.'_book'] / 100, 2) }}</td>
                            </tr>
                        @endforeach
                        @php
                            $hasDiff = $group['opening']['diff_cost'] !== 0 || $group['opening']['diff_accum'] !== 0 || $group['closing']['diff_cost'] !== 0 || $group['closing']['diff_accum'] !== 0;
                        @endphp
                        <tr class="{{ $hasDiff ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground' }}" data-test="recon-difference-row">
                            <td class="px-4 py-1 pl-8 italic">{{ __('Difference') }}</td>
                            <td class="px-4 py-1 text-right font-mono">{{ number_format($group['opening']['diff_cost'] / 100, 2) }}</td>
                            <td class="px-4 py-1 text-right font-mono">{{ number_format($group['opening']['diff_accum'] / 100, 2) }}</td>
                            <td class="px-4 py-1 text-right font-mono">{{ number_format($group['opening']['diff_book'] / 100, 2) }}</td>
                            <td class="px-4 py-1 text-right font-mono">{{ number_format($group['closing']['diff_cost'] / 100, 2) }}</td>
                            <td class="px-4 py-1 text-right font-mono">{{ number_format($group['closing']['diff_accum'] / 100, 2) }}</td>
                            <td class="px-4 py-1 text-right font-mono">{{ number_format($group['closing']['diff_book'] / 100, 2) }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-border font-semibold" data-test="recon-total-row">
                        <td class="px-4 py-2">{{ __('Total Difference') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($r['totals']['opening']['diff_cost'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($r['totals']['opening']['diff_accum'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($r['totals']['opening']['diff_book'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($r['totals']['closing']['diff_cost'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($r['totals']['closing']['diff_accum'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($r['totals']['closing']['diff_book'] / 100, 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</div>
