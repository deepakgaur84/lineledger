{{--
    Invoice PDF drawn from a designer template (every InvoiceLayout except
    Classic, which keeps its own view: pdf.invoices.invoice).

    Expects what InvoicePdfRenderer passes: $company, $invoice, $settings,
    $style (?FormStyle), $design (App\Support\Sales\InvoiceDesign), $taxSummary.

    Layout is plain tables — dompdf has no flexbox or grid. Each template only
    contributes a stylesheet (pdf/invoices/templates/_<name>.blade.php) plus the
    few structural switches below keyed on $design->template.
--}}
@php
    $templateName = $design->layout->value;

    $showLogo = $style?->show_logo ?? $settings->show_logo;
    $logoUri = $showLogo ? $company->documentLogoDataUri() : null;
    $logoHeight = $design->logoMaxHeight ?? $company->documentLogoMaxHeight();
    $logoPosition = $templateName === 'elegant' ? 'center' : $design->logoPosition->value;

    $logoHtml = $logoUri
        ? '<img src="'.$logoUri.'" style="max-height: '.$logoHeight.'px; max-width: 260px;" alt="'.e($company->name).'">'
        : '';

    $billLines = collect([
        $invoice->contact?->display_name,
        $invoice->contact?->billing_line1,
        $invoice->contact?->billing_line2,
        collect([$invoice->contact?->billing_city, $invoice->contact?->billing_region, $invoice->contact?->billing_postal_code])->filter()->implode(', '),
    ])->filter()->values();

    $shipLines = collect([
        $invoice->contact?->shipping_line1,
        $invoice->contact?->shipping_line2,
        collect([$invoice->contact?->shipping_city, $invoice->contact?->shipping_region, $invoice->contact?->shipping_postal_code])->filter()->implode(', '),
    ])->filter()->values();
    $showShip = $design->showShipTo && $shipLines->isNotEmpty();

    // Label/value pairs for the document header. Empty values are dropped so the
    // header never shows a row of dashes.
    $metaPairs = collect([
        [__('Invoice #'), $invoice->invoice_no],
        [__('Date'), $invoice->invoice_date?->displayDate()],
        [__('Due Date'), $invoice->due_date?->displayDate()],
        [__('Terms'), optional($invoice->terms)->name],
        [__('P.O. #'), $invoice->customer_po],
        [__('Rep'), optional($invoice->salesRep)->display_name],
    ])->filter(fn ($pair) => filled($pair[1]))->values();

    $shipPairs = collect([
        [__('Ship Date'), $invoice->ship_date?->displayDate()],
        [__('Ship Via'), $invoice->ship_via],
        [__('F.O.B.'), $invoice->fob],
        [__('Tracking #'), $invoice->tracking_no],
    ])->filter(fn ($pair) => filled($pair[1]))->values();

    $striped = $design->zebraRows || $templateName === 'ledger';
    $paymentInstructions = $settings->payment_instructions;
    $footerMessage = $style?->footer_message ?: $settings->footer_message;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $design->title() }} {{ $invoice->invoice_no }} — {{ $company->name }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: {{ $design->font->stack() }}; font-size: 11px; color: #1f2937; }
        table { border-collapse: collapse; }
        td, th { vertical-align: top; }
        .full { width: 100%; }
        .plain td, .plain th { border: none; padding: 0; }
        .muted { color: #4b5563; }
        .num { text-align: right; }
        .right { text-align: right; }
        .center { text-align: center; }
        .company-name { font-size: 15px; font-weight: bold; }
        .message { margin-top: 6px; color: #4b5563; white-space: pre-line; }
        table.lines { width: 100%; }
        table.totals { width: 46%; margin-left: 54%; margin-top: 10px; }
        table.totals td { padding: 4px 8px; }
        table.totals td.num { text-align: right; }
        tr { page-break-inside: avoid; }
    </style>
    @include('pdf.invoices.templates._'.$templateName)
</head>
<body class="tpl-{{ $templateName }}">

    {{-- ───────────── Header ───────────── --}}
    @if ($templateName === 'banner')
        <div class="band">
            <table class="full plain">
                <tr>
                    <td><span class="title">{{ $design->title() }}</span></td>
                    <td class="right band-ref">
                        @if (filled($invoice->invoice_no)) <span class="band-no"># {{ $invoice->invoice_no }}</span> @endif
                    </td>
                </tr>
            </table>
        </div>
    @endif

    @if ($logoPosition === 'center' && $logoHtml !== '')
        <div class="center logo-row">{!! $logoHtml !!}</div>
    @endif

    @if ($templateName === 'elegant')
        <div class="center">
            @if ($settings->show_company_name) <div class="company-name">{{ $company->name }}</div> @endif
            @if ($settings->show_legal_name && filled($company->legal_name)) <div class="muted">{{ $company->legal_name }}</div> @endif
            @include('pdf.invoices.templates._contact-line', ['company' => $company, 'settings' => $settings])
        </div>
        <div class="double-rule"></div>
        <div class="center"><span class="title">{{ $design->title() }}</span></div>
        @if ($metaPairs->isNotEmpty())
            <table class="full meta-strip">
                <tr>
                    @foreach ($metaPairs as $pair)
                        <td class="center"><div class="label">{{ $pair[0] }}</div><div>{{ $pair[1] }}</div></td>
                    @endforeach
                </tr>
            </table>
        @endif
        @if ($shipPairs->isNotEmpty())
            <table class="full meta-strip">
                <tr>
                    @foreach ($shipPairs as $pair)
                        <td class="center"><div class="label">{{ $pair[0] }}</div><div>{{ $pair[1] }}</div></td>
                    @endforeach
                </tr>
            </table>
        @endif
    @else
        <table class="full plain header">
            <tr>
                <td style="width: 55%;">
                    @if ($logoPosition === 'left' && $logoHtml !== '')
                        <div class="logo-row">{!! $logoHtml !!}</div>
                    @endif
                    @include('pdf.partials._company-header', [
                        'company' => $company,
                        'settings' => $settings,
                        'documentLogo' => null,
                        'logoMaxHeight' => $logoHeight,
                    ])
                </td>
                <td style="width: 45%;" class="right">
                    @if ($logoPosition === 'right' && $logoHtml !== '')
                        <div class="logo-row">{!! $logoHtml !!}</div>
                    @endif
                    @if ($templateName !== 'banner')
                        <div class="title {{ $design->longTitle() ? 'long' : '' }}">{{ $design->title() }}</div>
                    @endif
                    <table class="meta-list" align="right">
                        @foreach ($metaPairs as $pair)
                            @continue($templateName === 'banner' && $pair[0] === __('Invoice #'))
                            <tr>
                                <td class="mlabel">{{ $pair[0] }}</td>
                                <td class="mvalue">{{ $pair[1] }}</td>
                            </tr>
                        @endforeach
                        @foreach ($shipPairs as $pair)
                            <tr>
                                <td class="mlabel">{{ $pair[0] }}</td>
                                <td class="mvalue">{{ $pair[1] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    @endif

    {{-- ───────────── Parties ───────────── --}}
    <table class="full plain parties">
        <tr>
            <td style="width: {{ $showShip ? '48%' : '100%' }};">
                <div class="party">
                    <div class="label">{{ __('Bill To') }}</div>
                    @foreach ($billLines as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </div>
            </td>
            @if ($showShip)
                <td style="width: 4%;"></td>
                <td style="width: 48%;">
                    <div class="party">
                        <div class="label">{{ __('Ship To') }}</div>
                        @foreach ($shipLines as $line)
                            <div>{{ $line }}</div>
                        @endforeach
                    </div>
                </td>
            @endif
        </tr>
    </table>

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

    @php($schedule = $invoice->paymentRequests->isNotEmpty() ? app(\App\Services\Sales\PaymentRequestScheduleStatus::class)->for($invoice) : collect())
    @if ($settings->show_payment_schedule && $schedule->isNotEmpty())
        <table class="lines schedule" style="margin-top: 16px;">
            <thead>
                <tr>
                    <th colspan="2">{{ __('Payment Schedule') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                    <th class="num">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($schedule as $row)
                    <tr>
                        <td>{{ $row['request']->label }}</td>
                        <td>{{ $row['request']->due_date?->displayDate() }}</td>
                        <td class="num">{{ number_format($row['request']->amount_cents / 100, 2) }}</td>
                        <td class="num">{{ $row['status']->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- ───────────── Footer ───────────── --}}
    @if (filled($paymentInstructions))
        <div class="pay-box">
            <div class="label">{{ __('Payment details') }}</div>
            <div style="white-space: pre-line;">{{ $paymentInstructions }}</div>
        </div>
    @endif

    <div class="footer">
        @if ($settings->show_tax_number && filled($company->tax_number))
            <div class="taxno">{{ __('Tax No.') }} {{ $company->tax_number }}</div>
        @endif
        @if (filled($invoice->customer_message))
            <div class="message">{{ $invoice->customer_message }}</div>
        @endif
        @if (filled($footerMessage))
            <div class="message">{{ $footerMessage }}</div>
        @endif
    </div>
</body>
</html>
