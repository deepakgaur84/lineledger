@extends('pdf.reports._combined-layout', [
    'title' => 'Combined Balance Sheet',
    'period' => 'as of '.$asOf,
])

@section('content')
@php
    $labels ??= \App\Support\Reporting\StatementLabels::forType(null);
    $byCompany = $byCompany ?? false;
    $companies = $companies ?? [];
    $valueCols = count($companies) + 1; // + Combined
    $totalCols = 1 + $valueCols; // + label

    $byCompanyCells = function (array $byCompanyTotals, int $combined) use ($companies) {
        $cells = '';
        foreach ($companies as $company) {
            $cells .= '<td class="num">'.number_format(($byCompanyTotals[$company['id']] ?? 0) / 100, 2).'</td>';
        }

        return $cells.'<td class="num">'.number_format($combined / 100, 2).'</td>';
    };
@endphp
@foreach ([
    ['title' => 'Assets', 'key' => 'assets', 'total' => $report['total_assets']],
    ['title' => 'Liabilities', 'key' => 'liabilities', 'total' => $report['total_liabilities']],
    ['title' => $labels->equityShort(), 'key' => 'equity', 'total' => $report['total_equity']],
] as $sec)
    @php $sectionByCompany = []; @endphp
    <table class="data" style="margin-bottom: 18px;">
        <thead>
            <tr>
                <th>{{ $sec['title'] }}</th>
                @foreach ($companies as $company)
                    <th class="num">{{ $company['name'] }}</th>
                @endforeach
                <th class="num">{{ $byCompany ? 'Combined' : '' }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report[$sec['key']] as $bsGroup)
                <tr class="italic"><td colspan="{{ $totalCols }}">{{ $bsGroup['label'] }}</td></tr>
                @foreach ($bsGroup['blocks'] as $block)
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
                            <td class="num">{{ number_format($line['balance'] / 100, 2) }}</td>
                        </tr>
                    @endforeach
                    @if ($block['type'] === 'section')
                        <tr class="subtotal">
                            <td style="text-align:right;">Total {{ $block['name'] }}</td>
                            {!! $byCompanyCells($blockByCompany, $block['subtotal']) !!}
                        </tr>
                    @endif
                @endforeach
                @if ($bsGroup['has_section'])
                    <tr class="subtotal">
                        <td style="text-align:right;">Total {{ $bsGroup['label'] }}</td>
                        {!! $byCompanyCells($sectionByCompany, $bsGroup['subtotal']) !!}
                    </tr>
                @endif
            @empty
                <tr><td colspan="{{ $totalCols }}" style="color:#6b7280;">No accounts.</td></tr>
            @endforelse

            @if ($sec['key'] === 'equity' && ($report['retained_earnings_prior'] ?? 0) !== 0)
                <tr class="italic">
                    <td style="padding-left: 16px;">{{ $labels->retainedEarningsPriorRow() }}</td>
                    {!! $byCompanyCells($report['retained_earnings_prior_by_company'] ?? [], $report['retained_earnings_prior']) !!}
                </tr>
            @endif

            @if ($sec['key'] === 'equity' && $report['net_income_ytd'] !== 0)
                <tr class="italic">
                    <td style="padding-left: 16px;">{{ $labels->netIncomeYtd() }}</td>
                    {!! $byCompanyCells($report['net_income_ytd_by_company'] ?? [], $report['net_income_ytd']) !!}
                </tr>
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td style="text-align:right;">Total {{ $sec['title'] }}</td>
                @php
                    $sectionTotal = $sec['key'] === 'equity'
                        ? $sec['total'] + ($report['retained_earnings_prior'] ?? 0) + $report['net_income_ytd']
                        : $sec['total'];
                    $sectionTotalByCompany = $sectionByCompany;
                    if ($sec['key'] === 'equity') {
                        foreach ($companies as $company) {
                            $sectionTotalByCompany[$company['id']] = ($sectionByCompany[$company['id']] ?? 0)
                                + ($report['retained_earnings_prior_by_company'][$company['id']] ?? 0)
                                + ($report['net_income_ytd_by_company'][$company['id']] ?? 0);
                        }
                    }
                @endphp
                {!! $byCompanyCells($sectionTotalByCompany, $sectionTotal) !!}
            </tr>
        </tfoot>
    </table>
    @php
        if ($sec['key'] === 'liabilities') {
            $liabilitiesByCompany = $sectionTotalByCompany;
        } elseif ($sec['key'] === 'equity') {
            $equityByCompany = $sectionTotalByCompany;
        }
    @endphp
@endforeach

@php
    $totalLeByCompany = [];
    foreach ($companies as $company) {
        $totalLeByCompany[$company['id']] = ($liabilitiesByCompany[$company['id']] ?? 0) + ($equityByCompany[$company['id']] ?? 0);
    }
@endphp
<table class="data">
    <tr class="total">
        <td style="text-align:right; width: 70%;">{{ $labels->totalLiabilitiesAndEquity() }}</td>
        {!! $byCompanyCells($totalLeByCompany, $report['total_le']) !!}
    </tr>
</table>

@if ($report['total_assets'] !== $report['total_le'])
    <div class="footer neg">Out of balance — difference {{ number_format(abs($report['total_assets'] - $report['total_le']) / 100, 2) }}.</div>
@endif
@endsection
