@extends('pdf.reports._layout', [
    'title' => $title,
    'period' => $period,
    'metaLines' => $metaLines ?? [],
])

@section('content')
@if (! $hasBudget)
    <p style="color:#6b7280;">{{ __('No budget selected. Create a budget to compare your actual results against your targets.') }}</p>
@else
    @if ($noBudgetInRange)
        <p style="color:#b45309;">{{ __('None of this budget\'s months fall inside these dates, so the Budget column is zero.') }}</p>
    @endif
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('Account') }}</th>
                <th class="num">{{ __('Actual') }}</th>
                <th class="num">{{ __('Budget') }}</th>
                <th class="num">{{ __('Variance') }}</th>
                <th class="num">{{ __('%') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                @php($class = match ($line['type']) { 'section' => 'section', 'subtotal' => 'subtotal', 'total' => 'total', default => '' })
                <tr class="{{ $class }}">
                    @if ($line['type'] === 'section')
                        <td colspan="5">{{ $line['cells'][0] }}</td>
                    @else
                        @foreach ($line['cells'] as $i => $cell)
                            <td class="{{ $i > 0 ? 'num' : '' }} {{ $i === 3 && isset($line['favorable']) && ! $line['favorable'] ? 'neg' : '' }}">{{ $cell }}</td>
                        @endforeach
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
@endsection
