<?php

use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Concerns\EmailsReport;
use App\Concerns\HasReportDimensions;
use App\Concerns\Memorizable;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Company;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportCalculator;
use App\Support\Reporting\IncomeStatementBucket;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Budget vs. Actual')] class extends Component {
    use EmailsReport;
    use HasCustomReportHeader;
    use HasReportDateRange;
    use HasReportDimensions;
    use Memorizable;

    public Company $company;

    #[Url]
    public ?int $budgetId = null;

    public function mount(Company $company): void
    {
        $this->company = $company;

        // Only a range the user did not ask for (no ?start/?end, no memorized view) may
        // be moved to the budget's own period below.
        $defaultRange = $this->startDate === '' || $this->endDate === '';

        $this->initReportDateRange();
        $this->applyMemorized((int) request('memorized'));
        $this->budgetId ??= $this->budgets()->keys()->first();

        if ($defaultRange && ! request('memorized')) {
            $this->alignRangeToBudget();
        }
    }

    /** Picking a different budget brings its months into view if the dates don't reach them. */
    public function updatedBudgetId(): void
    {
        $this->alignRangeToBudget();
    }

    /**
     * A budget's months only count when they fall inside the report dates. The dates
     * default to "this fiscal year to date", so a budget for another fiscal year (a
     * future one, or last year's) would show a zero Budget column. When none of the
     * budget's months overlap the dates, move the dates to the budget's own period.
     */
    protected function alignRangeToBudget(): void
    {
        $budget = $this->selectedBudget();

        if ($budget === null || $this->startDate === '' || $this->endDate === '') {
            return;
        }

        if (! $budget->overlapsRange(CarbonImmutable::parse($this->startDate), CarbonImmutable::parse($this->endDate))) {
            $this->showBudgetPeriod();
        }
    }

    /** Set the report dates to exactly the selected budget's twelve fiscal months. */
    public function showBudgetPeriod(): void
    {
        $budget = $this->selectedBudget();

        if ($budget === null) {
            return;
        }

        $this->startDate = $budget->periodStart()->toDateString();
        $this->endDate = $budget->periodEnd()->toDateString();
        $this->preset = 'custom';
    }

    protected function selectedBudget(): ?Budget
    {
        return $this->budgetId !== null
            ? Budget::query()->where('company_id', $this->company->id)->find($this->budgetId)
            : null;
    }

    protected function reportKey(): string
    {
        return 'reports.budget-vs-actual';
    }

    /**
     * Budgets available to pick from, id => label.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    #[Computed]
    public function budgets(): \Illuminate\Support\Collection
    {
        return Budget::query()
            ->where('company_id', $this->company->id)
            ->orderByDesc('fiscal_year')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Budget $b): array => [$b->id => $b->pickerLabel()]);
    }

    #[Computed]
    public function report(): array
    {
        $budget = $this->budgetId !== null
            ? Budget::with('lines')->where('company_id', $this->company->id)->find($this->budgetId)
            : null;

        $calc = app(ReportCalculator::class);
        $start = CarbonImmutable::parse($this->startDate);
        $end = CarbonImmutable::parse($this->endDate);

        // A dimension-scoped budget only represents that slice of the ledger, so
        // its scope acts as the baseline filter unless the user narrows further.
        $classId = $this->effectiveClassId() ?? $budget?->class_id;
        $locationId = $this->effectiveLocationId() ?? $budget?->location_id;

        $budgeted = $budget !== null ? $budget->budgetedCentsByAccount($start, $end) : [];

        $accounts = Account::query()
            ->where('company_id', $this->company->id)
            ->whereIn('type', [AccountType::Income->value, AccountType::Expense->value])
            ->orderBy('code')
            ->get();

        $groups = [
            'income' => ['label' => __('Income'), 'rows' => [], 'actual' => 0, 'budget' => 0],
            'cogs' => ['label' => __('Cost of Goods Sold'), 'rows' => [], 'actual' => 0, 'budget' => 0],
            'expense' => ['label' => __('Expenses'), 'rows' => [], 'actual' => 0, 'budget' => 0],
        ];

        foreach ($accounts as $account) {
            $bucket = IncomeStatementBucket::for($account);

            if ($bucket === null) {
                continue;
            }

            $actual = $calc->periodChange($account, $start, $end, $classId, $locationId);
            $budgetCents = (int) ($budgeted[$account->id] ?? 0);

            if ($actual === 0 && $budgetCents === 0) {
                continue;
            }

            $groups[$bucket]['rows'][] = $this->row($account->code, $account->displayName(), $actual, $budgetCents, $bucket);
            $groups[$bucket]['actual'] += $actual;
            $groups[$bucket]['budget'] += $budgetCents;
        }

        $grossActual = $groups['income']['actual'] - $groups['cogs']['actual'];
        $grossBudget = $groups['income']['budget'] - $groups['cogs']['budget'];

        $netActual = $grossActual - $groups['expense']['actual'];
        $netBudget = $grossBudget - $groups['expense']['budget'];

        return [
            'groups' => $groups,
            'gross_profit' => $this->summary($grossActual, $grossBudget, 'income'),
            'net_income' => $this->summary($netActual, $netBudget, 'income'),
            'has_budget' => $budget !== null,
            'period_label' => $budget?->periodLabel(),
            // How many of the budget's months fall inside the dates; 0 means the Budget column is all zeros.
            'months_in_range' => $budget !== null ? $this->monthsInRange($budget, $start, $end) : 0,
            'budget_name' => $budget?->name,
        ];
    }

    protected function monthsInRange(Budget $budget, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $count = 0;

        for ($index = 1; $index <= 12; $index++) {
            $monthStart = $budget->monthStart($index);

            if ($monthStart->gte($start) && $monthStart->lte($end)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(string $code, string $name, int $actual, int $budget, string $bucket): array
    {
        return ['code' => $code, 'name' => $name] + $this->summary($actual, $budget, $bucket);
    }

    /**
     * Variance is favourable when income beats budget or spending stays under it.
     *
     * @return array<string, mixed>
     */
    protected function summary(int $actual, int $budget, string $bucket): array
    {
        $variance = $actual - $budget;
        $favorable = $bucket === 'income' ? $variance >= 0 : $variance <= 0;

        return [
            'actual' => $actual,
            'budget' => $budget,
            'variance' => $variance,
            'variance_pct' => $budget !== 0 ? round($variance / abs($budget) * 100, 1) : null,
            'favorable' => $favorable,
        ];
    }

    public function exportPdf()
    {
        $report = $this->report;
        $money = fn (int $cents): string => number_format($cents / 100, 2);
        $pct = fn (?float $value): string => $value !== null ? $value.'%' : '—';

        $lines = [];

        foreach ($report['groups'] as $group) {
            if (count($group['rows']) === 0) {
                continue;
            }

            $lines[] = ['type' => 'section', 'cells' => [$group['label'], '', '', '', '']];

            foreach ($group['rows'] as $row) {
                $lines[] = ['type' => 'row', 'favorable' => $row['favorable'], 'cells' => [
                    $row['code'].' '.$row['name'], $money($row['actual']), $money($row['budget']), $money($row['variance']), $pct($row['variance_pct']),
                ]];
            }

            $lines[] = ['type' => 'subtotal', 'cells' => [__('Total :group', ['group' => $group['label']]), $money($group['actual']), $money($group['budget']), '', '']];
        }

        foreach (['gross_profit' => __('Gross Profit'), 'net_income' => __('Net Income')] as $key => $label) {
            $lines[] = ['type' => 'total', 'favorable' => $report[$key]['favorable'], 'cells' => [
                $label, $money($report[$key]['actual']), $money($report[$key]['budget']), $money($report[$key]['variance']), $pct($report[$key]['variance_pct']),
            ]];
        }

        return app(PdfExporter::class)->download('pdf.reports.budget-vs-actual', [
            'company' => $this->company,
            'title' => $this->effectiveTitle(__('Budget vs. Actual')),
            'period' => $this->startDate.' to '.$this->endDate,
            'metaLines' => $report['has_budget'] ? [__('Budget: :name (:period)', ['name' => $report['budget_name'], 'period' => $report['period_label']])] : [],
            'hasBudget' => $report['has_budget'],
            'noBudgetInRange' => $report['has_budget'] && $report['months_in_range'] === 0,
            'lines' => $lines,
        ], 'budget-vs-actual-'.$this->startDate.'-to-'.$this->endDate.'.pdf');
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows = [];

        foreach ($this->report['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $rows[] = [
                    $group['label'],
                    $row['code'],
                    $row['name'],
                    number_format($row['actual'] / 100, 2),
                    number_format($row['budget'] / 100, 2),
                    number_format($row['variance'] / 100, 2),
                    $row['variance_pct'] !== null ? $row['variance_pct'].'%' : '',
                ];
            }
        }

        return app(\App\Services\Reporting\CsvExporter::class)->stream(
            'budget-vs-actual-'.$this->startDate.'-to-'.$this->endDate.'.csv',
            ['Section', 'Code', 'Account', 'Actual', 'Budget', 'Variance', 'Variance %'],
            $rows,
        );
    }
}; ?>

<div class="space-y-6">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Budget vs. Actual'))"
        :subtitle="$company->name.' · '.$startDate.' '.__('to').' '.$endDate"
        mode="range"
        :tracks-classes="$this->tracksClasses"
        :tracks-locations="$this->tracksLocations"
        :classification-options="$this->classificationOptions"
        :location-options="$this->locationOptions"
        :title-editable="true"
        :memorizable="true"
        :exports="['csv', 'pdf']"
        :emailable="$this->canEmailReport()"
        :print-url="$this->printReportUrl()"
    >
        <flux:select wire:model.live="budgetId" :label="__('Budget')" class="w-56">
            @foreach ($this->budgets as $id => $label)
                <flux:select.option :value="$id">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </x-reports.control-bar>

    @if (! $this->report['has_budget'])
        <flux:callout icon="calculator">
            <flux:callout.heading>{{ __('No budget selected') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Create a budget to compare your actual results against your targets.') }}
                <flux:link :href="route('budgets.create', $company)" wire:navigate>{{ __('New budget') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @else
        @if ($this->report['months_in_range'] === 0)
            <flux:callout icon="information-circle" variant="warning" data-test="budget-out-of-range">
                <flux:callout.heading>{{ __('This budget has no months in the selected dates') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('“:name” covers :period, so its Budget column is zero for these dates. The fiscal year on a budget is the calendar year the fiscal year begins in.', ['name' => $this->report['budget_name'], 'period' => $this->report['period_label']]) }}
                    <flux:link href="#" wire:click.prevent="showBudgetPeriod" data-test="show-budget-period">{{ __('Show the budget\'s period') }}</flux:link>
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">{{ __('Account') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ __('Actual') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ __('Budget') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ __('Variance') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ __('%') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($this->report['groups'] as $key => $group)
                        @continue (count($group['rows']) === 0)
                        <tr class="bg-muted">
                            <td colspan="5" class="px-4 py-2 font-semibold">{{ $group['label'] }}</td>
                        </tr>
                        @foreach ($group['rows'] as $row)
                            <tr wire:key="bva-{{ $key }}-{{ $row['code'] }}">
                                <td class="px-4 py-2"><span class="font-mono text-muted-foreground">{{ $row['code'] }}</span> {{ $row['name'] }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($row['actual'] / 100, 2) }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($row['budget'] / 100, 2) }}</td>
                                <td class="px-4 py-2 text-right font-mono {{ $row['favorable'] ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">{{ number_format($row['variance'] / 100, 2) }}</td>
                                <td class="px-4 py-2 text-right font-mono text-muted-foreground">{{ $row['variance_pct'] !== null ? $row['variance_pct'].'%' : '—' }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold">
                            <td class="px-4 py-2 text-right">{{ __('Total :group', ['group' => $group['label']]) }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($group['actual'] / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($group['budget'] / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right font-mono"></td>
                            <td class="px-4 py-2"></td>
                        </tr>
                    @endforeach
                    @foreach (['gross_profit' => __('Gross Profit'), 'net_income' => __('Net Income')] as $key => $label)
                        <tr class="border-t-2 border-border font-bold">
                            <td class="px-4 py-2">{{ $label }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($this->report[$key]['actual'] / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($this->report[$key]['budget'] / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right font-mono {{ $this->report[$key]['favorable'] ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">{{ number_format($this->report[$key]['variance'] / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right font-mono text-muted-foreground">{{ $this->report[$key]['variance_pct'] !== null ? $this->report[$key]['variance_pct'].'%' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
