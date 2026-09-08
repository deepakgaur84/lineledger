<?php

use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Concerns\HasReportDimensions;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\SalesPurchaseReportBuilder;
use App\Support\Reporting\RepSalesRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales by Rep (Detail)')] class extends Component {
    use HasCustomReportHeader;
    use HasReportDateRange;
    use HasReportDimensions;

    public Company $company;

    public Contact $rep;

    public function mount(Company $company, Contact $rep): void
    {
        $this->company = $company;
        $this->rep = $rep;

        $this->initReportDateRange();
    }

    /**
     * This rep's sales for the period, one row per revenue account per document.
     * Totals tie to the rep's row on the Sales by Rep summary.
     *
     * @return Collection<int, RepSalesRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        return app(SalesPurchaseReportBuilder::class)->salesByRepDetail(
            $this->company,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
            $this->rep->id,
            $this->effectiveClassId(),
            $this->effectiveLocationId(),
        );
    }

    /**
     * @return Collection<string, Collection<int, RepSalesRow>>
     */
    #[Computed]
    public function grouped(): Collection
    {
        return $this->rows->groupBy(fn (RepSalesRow $row): string => $row->accountLabel());
    }

    public function total(): int
    {
        return (int) $this->rows->sum(fn (RepSalesRow $r): int => $r->amountCents);
    }

    public function exportCsv()
    {
        $rows = $this->rows->map(fn (RepSalesRow $r): array => [
            $r->accountLabel(),
            $r->docDate,
            $r->docType,
            $r->docNo,
            $r->contact,
            CsvExporter::cents($r->amountCents),
        ]);
        $rows->push(['Total', '', '', '', '', CsvExporter::cents($this->total())]);

        return app(CsvExporter::class)->stream(
            "sales-by-rep-{$this->rep->id}-{$this->startDate}-{$this->endDate}.csv",
            ['Account', 'Date', 'Type', 'Document', 'Customer', 'Amount'],
            $rows,
        );
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Sales by Rep').' — '.$rep->display_name)"
        :subtitle="$company->name.' · '.$startDate.' '.__('to').' '.$endDate"
        mode="range"
        :tracks-classes="$this->tracksClasses"
        :tracks-locations="$this->tracksLocations"
        :classification-options="$this->classificationOptions"
        :location-options="$this->locationOptions"
        :exports="['csv']"
        :exports-disabled="$this->rows->isEmpty()"
    >
        <flux:button
            icon="arrow-left"
            variant="ghost"
            :href="route('reports.sales-by-rep', ['company' => $company->slug, 'start' => $startDate, 'end' => $endDate])"
            wire:navigate
            data-test="back-to-sales-by-rep"
        >{{ __('All reps') }}</flux:button>
    </x-reports.control-bar>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Doc #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->grouped as $account => $docs)
                    <tr class="bg-muted/50">
                        <td colspan="5" class="px-4 py-2 font-semibold" data-test="rep-detail-account">{{ $account }}</td>
                    </tr>
                    @foreach ($docs as $doc)
                        <tr data-test="rep-detail-row">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $doc->docDate }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ $doc->docType }}</td>
                            <td class="px-4 py-2 font-mono">
                                <a
                                    href="{{ route($doc->routeName, ['company' => $company->slug, $doc->routeParam => $doc->docId]) }}"
                                    wire:navigate
                                    class="hover:underline"
                                >{{ $doc->docNo }}</a>
                            </td>
                            <td class="px-4 py-2">{{ $doc->contact }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($doc->amountCents / 100, 2) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="4" class="px-4 py-2 text-right font-medium">{{ __('Subtotal for :name', ['name' => $account]) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-medium" data-test="rep-detail-subtotal">{{ number_format($docs->sum(fn ($r) => $r->amountCents) / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-muted-foreground">{{ __('No sales in this period.') }}</td></tr>
                @endforelse
            </tbody>
            @if ($this->rows->isNotEmpty())
                <tfoot class="bg-muted">
                    <tr>
                        <td colspan="4" class="px-4 py-3 font-semibold">{{ __('Total') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold" data-test="rep-detail-total">{{ number_format($this->total() / 100, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</section>
