@extends('pdf.reports._layout', [
    'title' => $title ?? 'Depreciation Schedule',
    'period' => 'for the period '.\Illuminate\Support\Carbon::parse($startDate)->format('j M Y').' to '.\Illuminate\Support\Carbon::parse($endDate)->format('j M Y'),
])

@section('content')
<table class="data">
    <thead>
        <tr>
            @foreach ($columnLabels as $i => $label)
                <th class="{{ $alignRight[$i] ? 'num' : '' }}">{{ $label }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($groups as $group)
            <tr class="subsection"><td colspan="{{ count($columnLabels) }}">{{ $group['label'] }}</td></tr>
            @foreach ($group['rows'] as $row)
                <tr>
                    @foreach ($row as $i => $cell)
                        <td class="{{ $alignRight[$i] ? 'num' : '' }}">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
            <tr class="subtotal">
                @foreach ($group['totals'] as $i => $cell)
                    <td class="{{ $alignRight[$i] ? 'num' : '' }}">{{ $cell }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($columnLabels) }}" style="text-align:center; color:#6b7280;">No fixed assets recorded yet.</td></tr>
        @endforelse
    </tbody>
    @if ($groups !== [])
        <tfoot>
            <tr class="subtotal">
                @foreach ($grandTotals as $i => $cell)
                    <td class="{{ $alignRight[$i] ? 'num' : '' }}">{{ $cell }}</td>
                @endforeach
            </tr>
        </tfoot>
    @endif
</table>
@endsection
