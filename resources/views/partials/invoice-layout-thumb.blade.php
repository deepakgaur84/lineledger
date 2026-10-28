{{--
    Miniature of an invoice layout for the designer's gallery. Pure inline SVG,
    tinted with the style's accent so the picker previews the colour as well.

    Expects:
      $layout — App\Enums\InvoiceLayout value (string)
      $accent — 6-digit hex, or null for the neutral grey of Classic
--}}
@php
    $ink = \App\Support\Sales\InvoiceDesign::validHex($accent ?? null)
        ?? \App\Enums\InvoiceLayout::tryFrom($layout)?->defaultAccent()
        ?? '#6b7280';
    $tint = \App\Support\Sales\InvoiceDesign::mixWithWhite($ink, 0.14);
    $on = \App\Support\Sales\InvoiceDesign::readableOn($ink);
@endphp
<svg viewBox="0 0 100 130" class="h-auto w-full rounded border border-border bg-white" role="img" aria-label="{{ $layout }}">
    @if ($layout === 'banner')
        <rect x="0" y="0" width="100" height="16" fill="{{ $ink }}" />
        <rect x="8" y="6" width="34" height="4" fill="{{ $on }}" />
    @elseif ($layout === 'modern')
        <rect x="0" y="0" width="100" height="2.5" fill="{{ $ink }}" />
        <rect x="58" y="9" width="34" height="5" fill="{{ $ink }}" />
    @elseif ($layout === 'elegant')
        <rect x="30" y="8" width="40" height="3.5" fill="#374151" />
        <rect x="14" y="15" width="72" height="1" fill="#9ca3af" />
        <rect x="8" y="20" width="84" height="0.8" fill="{{ $ink }}" />
        <rect x="8" y="22.2" width="84" height="0.8" fill="{{ $ink }}" />
        <rect x="32" y="27" width="36" height="3" fill="{{ $ink }}" />
    @elseif ($layout === 'minimal')
        <rect x="64" y="9" width="28" height="2.5" fill="#6b7280" />
    @elseif ($layout === 'airy')
        <rect x="70" y="8" width="22" height="9" fill="#d1d5db" />
    @else
        <rect x="58" y="9" width="34" height="5" fill="#374151" />
    @endif

    @if ($layout !== 'elegant')
        <rect x="8" y="{{ $layout === 'banner' ? 22 : 10 }}" width="30" height="3" fill="#374151" />
        <rect x="8" y="{{ $layout === 'banner' ? 28 : 16 }}" width="22" height="1.6" fill="#9ca3af" />
    @endif

    @if ($layout === 'classic' || $layout === 'ledger')
        <rect x="8" y="40" width="40" height="16" fill="none" stroke="#9ca3af" stroke-width="0.7" />
        <rect x="52" y="40" width="40" height="16" fill="none" stroke="#9ca3af" stroke-width="0.7" />
    @else
        <rect x="8" y="42" width="18" height="1.8" fill="{{ $layout === 'minimal' ? '#9ca3af' : $ink }}" />
        <rect x="8" y="47" width="30" height="1.6" fill="#9ca3af" />
        <rect x="8" y="51" width="24" height="1.6" fill="#9ca3af" />
        <rect x="54" y="42" width="18" height="1.8" fill="{{ $layout === 'minimal' ? '#9ca3af' : $ink }}" />
        <rect x="54" y="47" width="30" height="1.6" fill="#9ca3af" />
    @endif

    {{-- table header --}}
    @if ($layout === 'banner')
        <rect x="8" y="64" width="84" height="7" fill="{{ $ink }}" />
    @elseif ($layout === 'minimal')
        <rect x="8" y="70" width="84" height="0.8" fill="{{ $ink }}" />
    @elseif ($layout === 'elegant')
        <rect x="8" y="64" width="84" height="0.7" fill="{{ $ink }}" />
        <rect x="8" y="71" width="84" height="0.7" fill="{{ $ink }}" />
    @elseif ($layout === 'ledger')
        <rect x="8" y="64" width="84" height="7" fill="{{ $tint }}" stroke="#6b7280" stroke-width="0.6" />
    @elseif ($layout === 'modern')
        <rect x="8" y="64" width="84" height="7" fill="{{ $tint }}" />
        <rect x="8" y="70.2" width="84" height="0.9" fill="{{ $ink }}" />
    @else
        <rect x="8" y="64" width="84" height="7" fill="#f3f4f6" stroke="#9ca3af" stroke-width="0.6" />
    @endif

    {{-- rows --}}
    @foreach ([77, 87, 97] as $i => $y)
        @if ($layout === 'ledger' && $i % 2 === 1)
            <rect x="8" y="{{ $y - 4 }}" width="84" height="10" fill="{{ $tint }}" />
        @endif
        <rect x="11" y="{{ $y }}" width="34" height="1.8" fill="#9ca3af" />
        <rect x="74" y="{{ $y }}" width="16" height="1.8" fill="#9ca3af" />
        <rect x="8" y="{{ $y + 5 }}" width="84" height="0.5" fill="#d1d5db" />
    @endforeach

    {{-- total --}}
    @if ($layout === 'banner')
        <rect x="56" y="108" width="36" height="8" fill="{{ $ink }}" />
        <rect x="60" y="111" width="14" height="2.2" fill="{{ $on }}" />
    @elseif ($layout === 'ledger')
        <rect x="56" y="108" width="36" height="8" fill="{{ $tint }}" stroke="#6b7280" stroke-width="0.6" />
    @else
        <rect x="56" y="108" width="36" height="0.9" fill="{{ $ink }}" />
        <rect x="60" y="111" width="14" height="2.2" fill="{{ $ink }}" />
        <rect x="80" y="111" width="12" height="2.2" fill="{{ $ink }}" />
    @endif
</svg>
