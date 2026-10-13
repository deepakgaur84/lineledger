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
