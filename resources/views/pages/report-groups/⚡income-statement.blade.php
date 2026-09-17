<?php

use App\Models\ReportGroup;
use App\Services\Reporting\CombinedReportCalculator;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\StatementLabels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Combined Income Statement')] class extends Component {
    public ReportGroup $reportGroup;

    #[Url(as: 'start')]
    public string $startDate = '';

    #[Url(as: 'end')]
    public string $endDate = '';

    #[Url]
    public bool $byCompany = false;

    public function mount(ReportGroup $reportGroup): void
    {
        Gate::authorize('view', $reportGroup);

        $this->reportGroup = $reportGroup;

        $tzNow = \Illuminate\Support\Facades\Auth::user()?->currentCompany?->currentDateTime() ?? now();

        if ($this->startDate === '') {
            $this->startDate = $tzNow->startOfYear()->toDateString();
        }
        if ($this->endDate === '') {
            $this->endDate = $tzNow->toDateString();
        }
    }

    #[Computed]
    public function report(): array
    {
        return app(CombinedReportCalculator::class)->incomeStatement(
            $this->reportGroup,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
        );
    }

    /** Statement vocabulary; non-profit only when every member company is. */
    #[Computed]
    public function labels(): StatementLabels
    {
        return StatementLabels::forGroup($this->reportGroup->companies);
    }

    #[Computed]
    public function warnings(): array
    {
        return [
            'currency' => app(CombinedReportCalculator::class)->currencyMismatches($this->reportGroup),
            'fiscal' => false,
        ];
    }

    public function exportCsv()
    {
        $r = $this->report;
        $rows = collect();
        $companies = $this->byCompany ? $r['companies'] : [];

        $lineValues = function (array $line) use ($companies, &$runningByCompany) {
            $values = [];
            foreach ($companies as $company) {
                $amount = $line['by_company'][$company['id']] ?? 0;
                $runningByCompany[$company['id']] = ($runningByCompany[$company['id']] ?? 0) + $amount;
                $values[] = CsvExporter::cents($amount);
            }
            $values[] = CsvExporter::cents($line['current']);

            return $values;
        };

        $byCompanyValues = function (array $byCompany, int $combined) use ($companies) {
            $values = [];
            foreach ($companies as $company) {
                $values[] = CsvExporter::cents($byCompany[$company['id']] ?? 0);
            }
            $values[] = CsvExporter::cents($combined);

            return $values;
        };

        $emit = function (string $section, array $blocks, int $total) use (&$rows, $lineValues, $byCompanyValues, &$runningByCompany) {
            $runningByCompany = [];
            $rows->push([strtoupper($section)]);
            foreach ($blocks as $block) {
                if ($block['type'] === 'section') {
                    $rows->push(['', $block['name']]);
                }
                $blockByCompany = [];
                foreach ($block['rows'] as $line) {
                    $name = ($block['type'] === 'section' ? '    ' : '').$line['name'];
                    $rows->push(array_merge(['', $name], $lineValues($line)));
                }
                if ($block['type'] === 'section') {
                    $rows->push(array_merge(['', 'Total '.$block['name']], $byCompanyValues($runningByCompany, $block['subtotal'])));
                }
            }
            $rows->push(array_merge(['Total '.ucfirst($section), ''], $byCompanyValues($runningByCompany, $total)));
            $rows->push(['']);

            return $runningByCompany;
        };

        $incomeByCompany = $emit('income', $r['income'], $r['total_income']);
        $cogsByCompany = [];
        if (! empty($r['cogs'])) {
            $cogsByCompany = $emit('cost of goods sold', $r['cogs'], $r['total_cogs']);
            $grossProfitByCompany = [];
            foreach ($companies as $company) {
                $grossProfitByCompany[$company['id']] = ($incomeByCompany[$company['id']] ?? 0) - ($cogsByCompany[$company['id']] ?? 0);
            }
            $rows->push(array_merge([$this->labels->grossProfit(), ''], $byCompanyValues($grossProfitByCompany, $r['gross_profit'])));
            $rows->push(['']);
        }
        $expenseByCompany = $emit('expense', $r['expense'], $r['total_expense']);

        $netIncomeByCompany = [];
        foreach ($companies as $company) {
            $netIncomeByCompany[$company['id']] = ($incomeByCompany[$company['id']] ?? 0) - ($cogsByCompany[$company['id']] ?? 0) - ($expenseByCompany[$company['id']] ?? 0);
        }
        $rows->push(array_merge([strtoupper($this->labels->netIncome()), ''], $byCompanyValues($netIncomeByCompany, $r['net_income'])));

        $header = array_merge(['Section', 'Line'], collect($companies)->pluck('name')->all(), ['Combined']);

        return app(CsvExporter::class)->stream(
            "combined-income-statement-{$this->startDate}-{$this->endDate}.csv",
            $header,
            $rows,
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->combinedIncomeStatement(
            "combined-income-statement-{$this->startDate}-{$this->endDate}.xlsx",
            $this->reportGroup,
            $this->report,
            $this->startDate,
            $this->endDate,
            $this->byCompany,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.combined-income-statement', [
            'group' => $this->reportGroup,
            'report' => $this->report,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'labels' => $this->labels,
            'byCompany' => $this->byCompany,
            'companies' => $this->byCompany ? $this->report['companies'] : [],
        ], "combined-income-statement-{$this->startDate}-{$this->endDate}.pdf");
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Combined Income Statement') }}</flux:heading>
            <flux:subheading>{{ $reportGroup->name }} &middot; {{ $reportGroup->currency_code }} &middot; {{ $startDate }} {{ __('to') }} {{ $endDate }}</flux:subheading>
        </div>
        <div class="flex items-end gap-2">
            <flux:input type="date" wire:model.live="startDate" :label="__('Start')" class="max-w-[180px]" />
            <flux:input type="date" wire:model.live="endDate" :label="__('End')" class="max-w-[180px]" />
            <flux:switch wire:model.live="byCompany" :label="__('By company')" />
            @can('update', $reportGroup)
                <flux:button icon="cog-6-tooth" variant="ghost" :href="route('report-groups.income-statement.sections', $reportGroup)" wire:navigate data-test="sections-config-link">{{ __('Sections') }}</flux:button>
            @endcan
            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down">{{ __('Download') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('CSV') }}</flux:menu.item>
                    <flux:menu.item icon="table-cells" wire:click="exportXlsx">{{ __('Excel') }}</flux:menu.item>
                    <flux:menu.item icon="document" wire:click="exportPdf">{{ __('PDF') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @include('pages.report-groups._nav', ['reportGroup' => $reportGroup])

    @include('pages.report-groups._warnings', ['warnings' => $this->warnings])

    @php($companies = $this->report['companies'])
    @php($span = $byCompany ? count($companies) + 2 : 2)

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Line') }}</th>
                    @if ($byCompany)
                        @foreach ($companies as $c)
                            <th class="px-4 py-2 text-right">{{ $c['name'] }}</th>
                        @endforeach
                    @endif
                    <th class="px-4 py-2 text-right">{{ __('Combined') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach (['income' => __('Income'), 'cogs' => __('Cost of Goods Sold'), 'expense' => __('Expenses')] as $key => $label)
                    @php($blocks = $this->report[$key])
                    @if (! empty($blocks))
                        <tr class="bg-muted"><td colspan="{{ $span }}" class="px-4 py-2 font-semibold">{{ $label }}</td></tr>
                        @foreach ($blocks as $block)
                            @if ($block['type'] === 'section')
                                <tr data-test="cis-section-header"><td colspan="{{ $span }}" class="px-4 py-1 pl-6 font-medium text-muted-foreground">{{ $block['name'] }}</td></tr>
                            @endif
                            @foreach ($block['rows'] as $line)
                                <tr data-test="is-row">
                                    <td class="px-4 py-1 {{ $block['type'] === 'section' ? 'pl-12' : 'pl-8' }}">{{ $line['name'] }}</td>
                                    @if ($byCompany)
                                        @foreach ($companies as $c)
                                            <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ number_format(($line['by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                                        @endforeach
                                    @endif
                                    <td class="px-4 py-1 text-right font-mono">{{ number_format($line['current'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                            @if ($block['type'] === 'section')
                                <tr class="border-t border-border">
                                    <td class="px-4 py-1 pl-8 text-sm italic text-muted-foreground">{{ __('Total') }} {{ $block['name'] }}</td>
                                    @if ($byCompany)
                                        @foreach ($companies as $c)
                                            <td class="px-4 py-1 text-right font-mono italic text-muted-foreground">{{ number_format(($block['by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                                        @endforeach
                                    @endif
                                    <td class="px-4 py-1 text-right font-mono italic text-muted-foreground" data-test="cis-section-subtotal-{{ $block['id'] }}">{{ number_format($block['subtotal'] / 100, 2) }}</td>
                                </tr>
                            @endif
                        @endforeach
                        <tr class="border-t border-border">
                            <td class="px-4 py-2 font-medium">{{ __('Total') }} {{ $label }}</td>
                            @if ($byCompany)<td colspan="{{ count($companies) }}"></td>@endif
                            <td class="px-4 py-2 text-right font-mono font-medium" data-test="is-total-{{ $key }}">{{ number_format($this->report['total_'.$key] / 100, 2) }}</td>
                        </tr>
                        @if ($key === 'cogs')
                            <tr class="bg-muted">
                                <td class="px-4 py-2 font-semibold">{{ $this->labels->grossProfit() }}</td>
                                @if ($byCompany)<td colspan="{{ count($companies) }}"></td>@endif
                                <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($this->report['gross_profit'] / 100, 2) }}</td>
                            </tr>
                        @endif
                    @endif
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base">
                    <td class="px-4 py-3 font-semibold">{{ $this->labels->netIncome() }}</td>
                    @if ($byCompany)<td colspan="{{ count($companies) }}"></td>@endif
                    <td class="px-4 py-3 text-right font-mono font-semibold @if ($this->report['net_income'] < 0) text-red-600 @endif" data-test="is-net-income">{{ number_format($this->report['net_income'] / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
