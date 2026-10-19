@extends('pdf.reports._layout', [
    'title' => $title,
    'period' => $period ?? null,
    'metaLines' => $metaLines ?? [],
])

@section('content')
{{-- Landscape: Account + twelve fiscal months + Total is 14 columns. Same approach as the
     depreciation schedule: numbers never wrap, the type is tightened to fit the page. --}}
<style>
    @page { size: A4 landscape; }
    table.data.grid th, table.data.grid td { padding: 4px 4px; font-size: 8px; }
    table.data.grid thead th { font-size: 7px; }
    table.data.grid td.num { white-space: nowrap; }
    table.data.grid td.acct { white-space: nowrap; }
    table.data.grid tr { page-break-inside: avoid; }
</style>
@if (! $hasBudget)
    <p style="color:#6b7280;">{{ $emptyMessage }}</p>
@else
    <table class="data grid">
        <thead>
            <tr>
                <th>{{ __('Account') }}</th>
                @foreach ($monthLabels as $label)
                    <th class="num">{{ $label }}</th>
                @endforeach
                <th class="num">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($groups as $group)
                @if ($group['label'] !== null)
                    <tr class="section"><td colspan="14">{{ $group['label'] }}</td></tr>
                @endif
                @foreach ($group['rows'] as $row)
                    <tr>
                        <td class="acct">{{ $row['account'] }}</td>
                        @foreach ($row['cells'] as $cell)
                            <td class="num {{ $cell['neg'] ? 'neg' : '' }}">{{ $cell['text'] }}</td>
                        @endforeach
                        <td class="num {{ $row['total']['neg'] ? 'neg' : '' }}" style="font-weight: bold;">{{ $row['total']['text'] }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="14" style="text-align:center; color:#6b7280;">{{ $emptyMessage }}</td></tr>
            @endforelse
        </tbody>
        @if (! empty($totals))
            <tfoot>
                <tr>
                    <td>{{ __('Total') }}</td>
                    @foreach ($totals['cells'] as $text)
                        <td class="num">{{ $text }}</td>
                    @endforeach
                    <td class="num">{{ $totals['total'] }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
@endif
@endsection
