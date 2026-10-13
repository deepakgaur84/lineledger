    {{-- ───────────── Lines ───────────── --}}
    <table class="lines">
        <thead>
            <tr>
                @if ($settings->show_item_column) <th style="width: 18%;">{{ __('Item') }}</th> @endif
                <th>{{ __('Description') }}</th>
                @if ($settings->show_qty_column) <th class="num" style="width: 8%;">{{ __('Qty') }}</th> @endif
                @if ($settings->show_tax_column) <th style="width: 8%;">{{ __('Tax') }}</th> @endif
                @if ($settings->show_unit_column) <th class="num" style="width: 14%;">{{ __('Price Each') }}</th> @endif
                <th class="num" style="width: 14%;">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @php($rowIndex = 0)
            @foreach ($invoice->lines as $line)
                {{-- Same guard as the classic view: a line that carries a value always prints,
                     so the visible rows still sum to the stored invoice totals. --}}
                @if ($settings->hide_zero_qty_lines && (float) $line->quantity == 0.0 && (int) $line->line_subtotal_cents === 0)
                    @continue
                @endif
                @php($rowIndex++)
                <tr class="{{ $striped && $rowIndex % 2 === 0 ? 'zebra' : '' }}">
                    @if ($settings->show_item_column)
                        <td>{{ optional($line->item)->name }}</td>
                    @endif
                    <td>
                        {!! \App\Support\Text\LineDescription::toHtml($line->description) !!}
                        @if ($settings->show_service_date_column && $line->service_date)
                            <div class="muted" style="font-size: 9px;">{{ __('Service date') }}: {{ $line->service_date->displayDate() }}</div>
                        @endif
                    </td>
                    @if ($settings->show_qty_column)
                        <td class="num">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                    @endif
                    @if ($settings->show_tax_column)
                        <td>{{ optional($line->taxCode)->code }}</td>
                    @endif
                    @if ($settings->show_unit_column)
                        <td class="num">
                            {{ number_format($line->unit_price_cents / 100, 2) }}
                            @if ($line->line_discount_cents)
                                <div class="muted" style="font-size: 9px;">{{ __('less :amt disc', ['amt' => number_format($line->line_discount_cents / 100, 2)]) }}</div>
                            @endif
                        </td>
                    @endif
                    <td class="num">{{ number_format($line->line_subtotal_cents / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
