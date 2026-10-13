@extends('pdf.reports._layout', [
    'title' => $title ?? 'Fixed Asset Reconciliation',
    'period' => 'for the period '.\Illuminate\Support\Carbon::parse($startDate)->format('j M Y').' to '.\Illuminate\Support\Carbon::parse($endDate)->format('j M Y'),
])

@section('content')
<table class="data">
    <thead>
        <tr>
            <th>Source</th>
            <th class="num">Opening Cost</th>
            <th class="num">Opening Accum Dep</th>
            <th class="num">Opening Book Value</th>
            <th class="num">Closing Cost</th>
            <th class="num">Closing Accum Dep</th>
            <th class="num">Closing Book Value</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($report['groups'] as $group)
            <tr class="subsection"><td colspan="7">{{ $group['label'] }}</td></tr>
            @foreach (['Balance Sheet' => 'bs', 'Asset Register' => 'reg'] as $rowLabel => $prefix)
                <tr>
                    <td>{{ $rowLabel }}</td>
                    <td class="num">{{ number_format($group['opening'][$prefix.'_cost'] / 100, 2) }}</td>
                    <td class="num">{{ number_format($group['opening'][$prefix.'_accum'] / 100, 2) }}</td>
                    <td class="num">{{ number_format($group['opening'][$prefix.'_book'] / 100, 2) }}</td>
                    <td class="num">{{ number_format($group['closing'][$prefix.'_cost'] / 100, 2) }}</td>
                    <td class="num">{{ number_format($group['closing'][$prefix.'_accum'] / 100, 2) }}</td>
                    <td class="num">{{ number_format($group['closing'][$prefix.'_book'] / 100, 2) }}</td>
                </tr>
            @endforeach
            @php($hasDiff = $group['opening']['diff_cost'] !== 0 || $group['opening']['diff_accum'] !== 0 || $group['closing']['diff_cost'] !== 0 || $group['closing']['diff_accum'] !== 0)
            <tr class="{{ $hasDiff ? 'neg' : '' }}">
                <td>&nbsp;&nbsp;Difference</td>
                <td class="num">{{ number_format($group['opening']['diff_cost'] / 100, 2) }}</td>
                <td class="num">{{ number_format($group['opening']['diff_accum'] / 100, 2) }}</td>
                <td class="num">{{ number_format($group['opening']['diff_book'] / 100, 2) }}</td>
                <td class="num">{{ number_format($group['closing']['diff_cost'] / 100, 2) }}</td>
                <td class="num">{{ number_format($group['closing']['diff_accum'] / 100, 2) }}</td>
                <td class="num">{{ number_format($group['closing']['diff_book'] / 100, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; color:#6b7280;">No fixed assets recorded yet.</td></tr>
        @endforelse
    </tbody>
    @if ($report['groups'] !== [])
        <tfoot>
            <tr class="subtotal">
                <td>Total Difference</td>
                <td class="num">{{ number_format($report['totals']['opening']['diff_cost'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['opening']['diff_accum'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['opening']['diff_book'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['closing']['diff_cost'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['closing']['diff_accum'] / 100, 2) }}</td>
                <td class="num">{{ number_format($report['totals']['closing']['diff_book'] / 100, 2) }}</td>
            </tr>
        </tfoot>
    @endif
</table>
@endsection
