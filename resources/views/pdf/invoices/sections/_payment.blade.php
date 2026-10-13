    @if (filled($paymentInstructions))
        <div class="pay-box">
            <div class="label">{{ __('Payment details') }}</div>
            <div style="white-space: pre-line;">{{ $paymentInstructions }}</div>
        </div>
    @endif
