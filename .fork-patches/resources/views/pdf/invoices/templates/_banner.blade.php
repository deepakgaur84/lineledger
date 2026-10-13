{{-- Banner: full-width accent band; title and number printed on it. --}}
<style>
    .band { background: {{ $design->accent }}; color: {{ $design->onAccent }}; padding: 14px 16px; margin: 0 0 18px 0; }
    .band .title { font-size: 28px; font-weight: bold; letter-spacing: 0.04em; color: {{ $design->onAccent }}; }
    .band .band-no { font-size: 13px; color: {{ $design->onAccent }}; }
    .band td { vertical-align: middle; }
    .logo-row { margin-bottom: 8px; }
    table.meta-list td { padding: 1px 0 1px 14px; font-size: 10px; }
    table.meta-list td.mlabel { color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; text-align: right; }
    table.meta-list td.mvalue { font-weight: bold; text-align: right; }
    .parties { margin-top: 24px; }
    .label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; color: {{ $design->accentText }}; margin-bottom: 4px; }
    table.lines { margin-top: 22px; }
    table.lines thead th { background: {{ $design->accent }}; color: {{ $design->onAccent }}; padding: 7px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; text-align: left; }
    table.lines thead th.num { text-align: right; }
    table.lines td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; }
    table.lines tr.zebra td { background: {{ $design->accentSoft }}; }
    table.totals tr.grand td { background: {{ $design->accent }}; color: {{ $design->onAccent }}; font-size: 14px; font-weight: bold; }
    table.totals tr.due td { font-weight: bold; }
    .pay-box { margin-top: 22px; padding: 8px 10px; border: 1px solid {{ $design->accent }}; }
    .footer { margin-top: 24px; border-top: 1px solid #d1d5db; padding-top: 8px; color: #374151; }
    .footer .taxno { font-size: 10px; }
</style>
