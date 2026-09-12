@extends('pdf.reports._layout', [
    'title' => $title ?? 'Transactions',
    'period' => $period,
    'metaLines' => array_filter([$context ?? null]),
])

@section('content')
@php
    $totalDebit = collect($rows)->sum('debit');
    $totalCredit = collect($rows)->sum('credit');
    $isForeign = ($foreignCurrency ?? null) !== null;
    $columnCount = $isForeign ? 10 : 7;
    $lastRow = $isForeign ? collect($rows)->last() : null;
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
            @if ($isForeign)
                <th class="num">Debit ({{ $foreignCurrency }})</th>
                <th class="num">Credit ({{ $foreignCurrency }})</th>
                <th class="num">Balance ({{ $foreignCurrency }})</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['date'] }}</td>
                <td>{{ $row['entry_no'] }}</td>
                <td>{{ $row['account'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['memo'] }}</td>
                <td class="num">{{ $row['debit'] ? number_format($row['debit'] / 100, 2) : '' }}</td>
                <td class="num">{{ $row['credit'] ? number_format($row['credit'] / 100, 2) : '' }}</td>
                @if ($isForeign)
                    <td class="num">{{ $row['source_debit'] ? number_format($row['source_debit'] / 100, 2) : '' }}</td>
                    <td class="num">{{ $row['source_credit'] ? number_format($row['source_credit'] / 100, 2) : '' }}</td>
                    <td class="num">{{ number_format($row['source_balance'] / 100, 2) }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $columnCount }}" style="text-align:center; color:#6b7280;">No transactions match these filters.</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" style="text-align:right;">Totals</td>
            <td class="num">{{ number_format($totalDebit / 100, 2) }}</td>
            <td class="num">{{ number_format($totalCredit / 100, 2) }}</td>
            @if ($isForeign)
                {{-- Source debit/credit totals aren't shown here — a source-currency
                     sum across a mixed-rate period isn't a meaningful figure the
                     way the running balance (below, as of the last row) is. --}}
                <td class="num"></td>
                <td class="num"></td>
                <td class="num">{{ $lastRow ? number_format($lastRow['source_balance'] / 100, 2) : '' }}</td>
            @endif
        </tr>
    </tfoot>
</table>
@endsection
