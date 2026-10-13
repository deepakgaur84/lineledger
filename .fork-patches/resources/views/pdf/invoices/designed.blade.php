{{--
    Invoice PDF drawn from a designer template (every InvoiceLayout except
    Classic, which keeps its own view: pdf.invoices.invoice).

    Expects what InvoicePdfRenderer passes: $company, $invoice, $settings,
    $style (?FormStyle), $design (App\Support\Sales\InvoiceDesign), $taxSummary.

    The body below the header is a list of sections ($design->sections: parties,
    lines, totals, schedule, payment, footer) — each lives in
    pdf/invoices/sections/_<name>.blade.php and can be reordered or hidden per
    style.

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

    {{-- ───────────── Body sections, in the order (and with the visibility) the style chose ───────────── --}}
    @foreach ($design->sections as $section)
        @include('pdf.invoices.sections._'.$section->value)
    @endforeach
</body>
</html>
