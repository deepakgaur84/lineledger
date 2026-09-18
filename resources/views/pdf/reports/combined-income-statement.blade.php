@extends('pdf.reports._combined-layout', [
    'title' => 'Combined Income Statement',
    'period' => $startDate.' to '.$endDate,
])

@section('content')
@php
    $labels ??= \App\Support\Reporting\StatementLabels::forType(null);
    $byCompany = $byCompany ?? false;
    $companies = $companies ?? [];
    $totalCols = 2 + count($companies);

    $byCompanyCells = function (array $byCompanyTotals, int $combined) use ($companies) {
        $cells = '';
        foreach ($companies as $company) {
            $cells .= '<td class="num">'.number_format(($byCompanyTotals[$company['id']] ?? 0) / 100, 2).'</td>';
        }

        return $cells.'<td class="num">'.number_format($combined / 100, 2).'</td>';
    };

    $incomeByCompany = [];
    $cogsByCompany = [];
    $expenseByCompany = [];
@endphp
<table class="data">
    <thead>
        <tr>
            <th>Line</th>
            @foreach ($companies as $company)
                <th class="num">{{ $company['name'] }}</th>
            @endforeach
            <th class="num">{{ $byCompany ? 'Combined' : 'Amount' }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach (['income' => 'Income', 'cogs' => 'Cost of Goods Sold', 'expense' => 'Expenses'] as $key => $label)
            @if (! empty($report[$key]))
                @php $sectionByCompany = []; @endphp
                <tr class="section"><td colspan="{{ $totalCols }}">{{ $label }}</td></tr>
                @foreach ($report[$key] as $block)
                    @if ($block['type'] === 'section')
                        <tr class="subsection"><td colspan="{{ $totalCols }}" style="padding-left: 16px;">{{ $block['name'] }}</td></tr>
                    @endif
                    @php $blockByCompany = []; @endphp
                    @foreach ($block['rows'] as $line)
                        <tr>
                            <td style="padding-left: {{ $block['type'] === 'section' ? '32px' : '16px' }};">{{ $line['name'] }}</td>
                            @foreach ($companies as $company)
                                @php
                                    $amount = $line['by_company'][$company['id']] ?? 0;
                                    $blockByCompany[$company['id']] = ($blockByCompany[$company['id']] ?? 0) + $amount;
                                    $sectionByCompany[$company['id']] = ($sectionByCompany[$company['id']] ?? 0) + $amount;
                                @endphp
                                <td class="num">{{ number_format($amount / 100, 2) }}</td>
                            @endforeach
                            <td class="num">{{ number_format($line['current'] / 100, 2) }}</td>
                        </tr>
                    @endforeach
                    @if ($block['type'] === 'section')
                        <tr class="subtotal">
                            <td style="text-align:right;">Total {{ $block['name'] }}</td>
                            {!! $byCompanyCells($blockByCompany, $block['subtotal']) !!}
                        </tr>
                    @endif
                @endforeach
                <tr class="subtotal">
                    <td style="text-align:right;">Total {{ $label }}</td>
                    {!! $byCompanyCells($sectionByCompany, $report['total_'.$key]) !!}
                </tr>
                @php
                    if ($key === 'income') { $incomeByCompany = $sectionByCompany; }
                    elseif ($key === 'cogs') { $cogsByCompany = $sectionByCompany; }
                    elseif ($key === 'expense') { $expenseByCompany = $sectionByCompany; }
                @endphp
                @if ($key === 'cogs')
                    @php
                        $grossProfitByCompany = [];
                        foreach ($companies as $company) {
                            $grossProfitByCompany[$company['id']] = ($incomeByCompany[$company['id']] ?? 0) - ($cogsByCompany[$company['id']] ?? 0);
                        }
                    @endphp
                    <tr class="subtotal">
                        <td style="text-align:right;">{{ $labels->grossProfit() }}</td>
                        {!! $byCompanyCells($grossProfitByCompany, $report['gross_profit']) !!}
                    </tr>
                @endif
            @endif
        @endforeach
    </tbody>
    <tfoot>
        @php
            $netIncomeByCompany = [];
            foreach ($companies as $company) {
                $netIncomeByCompany[$company['id']] = ($incomeByCompany[$company['id']] ?? 0) - ($cogsByCompany[$company['id']] ?? 0) - ($expenseByCompany[$company['id']] ?? 0);
            }
        @endphp
        <tr class="total">
            <td style="text-align:right;">{{ $labels->netIncome() }}</td>
            {!! $byCompanyCells($netIncomeByCompany, $report['net_income']) !!}
        </tr>
    </tfoot>
</table>
@endsection
