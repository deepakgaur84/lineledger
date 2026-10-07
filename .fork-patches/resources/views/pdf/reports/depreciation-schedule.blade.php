@extends('pdf.reports._layout', [
    'title' => $title ?? 'Depreciation Schedule',
    'period' => 'for the period '.\Illuminate\Support\Carbon::parse($startDate)->format('j M Y').' to '.\Illuminate\Support\Carbon::parse($endDate)->format('j M Y'),
])

@section('content')
{{-- Landscape: this report has up to 17 columns, which cannot fit a portrait A4 page (the
     last column was being cut off the right edge). @page rules from the view are merged
     over the shared layout's, so this changes the page size for this report only — and for
     every way it is produced (download, email attachment), since they all render this view.
     The layout's margin is kept. The table is then tightened so it fills the width without
     wrapping every cell: numbers never wrap, and 13+ columns drop to a denser type size.

     Which columns may wrap is decided from the DATA, not from column names: a column whose
     longest cell is short is an id, a date or a number and must never break mid-token (a date
     was splitting as "2026-09-" / "30"); only genuinely free text (name, method, category)
     wraps. Totals rows are included so a long "Total <category>" label counts as free text. --}}
@php
    $wrapOk = [];
    foreach ($columnLabels as $i => $_label) {
        $longest = mb_strlen((string) ($grandTotals[$i] ?? ''));
        foreach ($groups as $g) {
            $longest = max($longest, mb_strlen((string) ($g['totals'][$i] ?? '')));
            foreach ($g['rows'] as $r) {
                $longest = max($longest, mb_strlen((string) ($r[$i] ?? '')));
            }
        }
        $wrapOk[$i] = $longest > 14;
    }
@endphp
<style>
    @page { size: A4 landscape; }
    table.data.schedule th, table.data.schedule td { padding: 4px 5px; font-size: 9px; }
    table.data.schedule thead th { font-size: 8px; }
    table.data.schedule td.num, table.data.schedule td.nw { white-space: nowrap; }
    table.data.schedule tr { page-break-inside: avoid; }
    table.data.schedule.dense th, table.data.schedule.dense td { padding: 3px 4px; font-size: 8px; }
    table.data.schedule.dense thead th { font-size: 7px; }
</style>
<table class="data schedule {{ count($columnLabels) > 12 ? 'dense' : '' }}">
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
                        <td class="{{ $alignRight[$i] ? 'num' : '' }}{{ $wrapOk[$i] ? '' : ' nw' }}">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
            <tr class="subtotal">
                @foreach ($group['totals'] as $i => $cell)
                    <td class="{{ $alignRight[$i] ? 'num' : '' }}{{ $wrapOk[$i] ? '' : ' nw' }}">{{ $cell }}</td>
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
                    <td class="{{ $alignRight[$i] ? 'num' : '' }}{{ $wrapOk[$i] ? '' : ' nw' }}">{{ $cell }}</td>
                @endforeach
            </tr>
        </tfoot>
    @endif
</table>
@endsection
