    <div class="footer">
        {{-- Same registration lines as the Classic and Airy layouts: GST/VAT, company number and the provincial tax number (QST/PST). --}}
        @include('pdf.invoices.partials.tax-numbers')
        @if (filled($invoice->customer_message))
            <div class="message">{{ $invoice->customer_message }}</div>
        @endif
        @if (filled($footerMessage))
            <div class="message">{{ $footerMessage }}</div>
        @endif
    </div>
