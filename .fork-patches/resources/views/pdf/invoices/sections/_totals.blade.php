    {{-- ───────────── Totals ───────────── --}}
    <table class="totals">
        <tr>
            <td>{{ __('Subtotal') }}</td>
            <td class="num">{{ number_format($invoice->subtotal_cents / 100, 2) }}</td>
        </tr>
        @foreach ($taxSummary as $tax)
            <tr>
                <td>{{ $tax['label'] }} {{ number_format($tax['rate'], 2) }}%</td>
                <td class="num">{{ number_format($tax['tax_cents'] / 100, 2) }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td>{{ __('Total') }}</td>
            <td class="num">${{ number_format($invoice->total_cents / 100, 2) }}</td>
        </tr>
        @if ($invoice->amount_paid_cents > 0)
            <tr>
                <td>{{ __('Paid') }}</td>
                <td class="num">{{ number_format($invoice->amount_paid_cents / 100, 2) }}</td>
            </tr>
            <tr class="due">
                <td>{{ __('Balance Due') }}</td>
                <td class="num">${{ number_format($invoice->balanceCents() / 100, 2) }}</td>
            </tr>
        @endif
    </table>
