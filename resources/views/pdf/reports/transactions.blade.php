@extends('pdf.reports._layout', [
    'title' => $title ?? 'Transactions',
    'period' => $period,
    'metaLines' => array_filter([$context ?? null]),
])

@section('content')
@php
    $totalDebit = collect($rows)->sum('debit');
    $totalCredit = collect($rows)->sum('credit');
    $hasBalance = $showBalance ?? false;
    $isForeign = ($foreignCurrency ?? null) !== null;
    $columnCount = 7 + ($hasBalance ? 1 : 0) + ($isForeign ? 3 : 0);
@endphp
<table class="data">
    <thead>
        <tr>
            <th>Date</th>
            <th>Entry #</th>
            <th>Account</th>
            <th>Name</th>
            <th>Memo</th>
            <th class="num">Debit</th>
            <th class="num">Credit</th>
            @if ($hasBalance)
                <th class="num">Balance</th>
            @endif
            @if ($isForeign)
                <th class="num">Debit ({{ $foreignCurrency }})</th>
                <th class="num">Credit ({{ $foreignCurrency }})</th>
                <th class="num">Balance ({{ $foreignCurrency }})</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @if ($hasBalance)
            <tr style="background:#f3f4f6;">
                <td colspan="{{ $columnCount - 1 - ($isForeign ? 1 : 0) }}" style="text-align:right; font-weight:bold;">Opening Balance</td>
                <td class="num" style="font-weight:bold;">{{ number_format(($openingBalance ?? 0) / 100, 2) }}</td>
                @if ($isForeign)
                    <td class="num" style="font-weight:bold;">{{ number_format(($openingForeignBalance ?? 0) / 100, 2) }}</td>
                @endif
            </tr>
        @endif
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['date'] }}</td>
                <td>{{ $row['entry_no'] }}</td>
                <td>{{ $row['account'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['memo'] }}</td>
                <td class="num">{{ $row['debit'] ? number_format($row['debit'] / 100, 2) : '' }}</td>
                <td class="num">{{ $row['credit'] ? number_format($row['credit'] / 100, 2) : '' }}</td>
                @if ($hasBalance)
                    <td class="num">{{ number_format($row['balance'] / 100, 2) }}</td>
                @endif
                @if ($isForeign)
                    <td class="num">{{ $row['source_debit'] ? number_format($row['source_debit'] / 100, 2) : '' }}</td>
                    <td class="num">{{ $row['source_credit'] ? number_format($row['source_credit'] / 100, 2) : '' }}</td>
                    <td class="num">{{ number_format($row['source_balance'] / 100, 2) }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $columnCount }}" style="text-align:center; color:#6b7280;">No transactions match these filters.</td></tr>
        @endforelse
        @if ($hasBalance)
            <tr style="background:#f3f4f6;">
                <td colspan="{{ $columnCount - 1 - ($isForeign ? 1 : 0) }}" style="text-align:right; font-weight:bold;">Closing Balance</td>
                <td class="num" style="font-weight:bold;">{{ number_format(($closingBalance ?? 0) / 100, 2) }}</td>
                @if ($isForeign)
                    <td class="num" style="font-weight:bold;">{{ number_format(($closingForeignBalance ?? 0) / 100, 2) }}</td>
                @endif
            </tr>
        @endif
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" style="text-align:right;">Totals</td>
            <td class="num">{{ number_format($totalDebit / 100, 2) }}</td>
            <td class="num">{{ number_format($totalCredit / 100, 2) }}</td>
            @if ($hasBalance)
                <td class="num"></td>
            @endif
            @if ($isForeign)
                {{-- Source debit/credit totals aren't shown here — a source-currency
                     sum across a mixed-rate period isn't a meaningful figure the
                     way the closing balance (above) is. --}}
                <td class="num"></td>
                <td class="num"></td>
                <td class="num"></td>
            @endif
        </tr>
    </tfoot>
</table>
@endsection
