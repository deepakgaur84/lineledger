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
