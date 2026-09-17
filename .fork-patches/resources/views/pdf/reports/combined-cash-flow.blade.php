@extends('pdf.reports._combined-layout', [
    'title' => 'Combined Cash Flow Statement',
    'period' => $startDate.' to '.$endDate,
])

@section('content')
@php
    $activities = [
        'operating' => 'Operating Activities',
        'investing' => 'Investing Activities',
        'financing' => 'Financing Activities',
    ];
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
        @foreach ($activities as $key => $label)
            <tr class="section"><td colspan="{{ $totalCols }}">{{ $label }}</td></tr>

            @if ($key === 'operating')
                <tr>
                    <td style="padding-left: 16px;">Net income</td>
                    {!! $byCompanyCells($report['net_income_by_company'] ?? [], $report['net_income']) !!}
                </tr>
            @endif

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
                            @endphp
                            <td class="num {{ $amount < 0 ? 'neg' : '' }}">{{ number_format($amount / 100, 2) }}</td>
                        @endforeach
                        <td class="num {{ $line['current'] < 0 ? 'neg' : '' }}">{{ number_format($line['current'] / 100, 2) }}</td>
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
                <td style="text-align:right;">Net cash from {{ $label }}</td>
                {!! $byCompanyCells($report['total_'.$key.'_by_company'] ?? [], $report['total_'.$key]) !!}
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total">
            <td style="text-align:right;">Net change in cash</td>
            {!! $byCompanyCells($report['net_change_by_company'] ?? [], $report['net_change']) !!}
        </tr>
        <tr>
            <td style="text-align:right;">Cash at beginning of period</td>
            {!! $byCompanyCells($report['cash_beginning_by_company'] ?? [], $report['cash_beginning']) !!}
        </tr>
        <tr>
            <td style="text-align:right;">Cash at end of period</td>
            {!! $byCompanyCells($report['cash_ending_by_company'] ?? [], $report['cash_ending']) !!}
        </tr>
    </tfoot>
</table>
@endsection
