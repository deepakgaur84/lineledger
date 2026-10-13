{{-- Modern: coloured title, accent rule on top, soft tinted table header. --}}
<style>
    body { border-top: 4px solid {{ $design->accent }}; padding-top: 14px; }
    .logo-row { margin-bottom: 8px; }
    .title { font-size: 30px; font-weight: bold; color: {{ $design->accentText }}; letter-spacing: 0.01em; margin: 0 0 8px 0; text-align: right; }
    .title.long { font-size: 22px; }
    table.meta-list td { padding: 1px 0 1px 14px; font-size: 10px; }
    table.meta-list td.mlabel { color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; text-align: right; }
    table.meta-list td.mvalue { font-weight: bold; text-align: right; }
    .parties { margin-top: 26px; }
    .label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; color: {{ $design->accentText }}; margin-bottom: 4px; }
    table.lines { margin-top: 24px; }
    table.lines thead th { background: {{ $design->accentTint }}; color: {{ $design->accentText }}; border-bottom: 2px solid {{ $design->accent }}; padding: 7px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; text-align: left; }
    table.lines thead th.num { text-align: right; }
    table.lines td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; }
    table.lines tr.zebra td { background: {{ $design->accentSoft }}; }
    table.totals td { border-bottom: 1px solid #f3f4f6; }
    table.totals tr.grand td { border-top: 2px solid {{ $design->accent }}; border-bottom: none; font-size: 14px; font-weight: bold; color: {{ $design->accentText }}; }
    table.totals tr.due td { font-weight: bold; }
    .pay-box { margin-top: 22px; padding: 8px 10px; background: {{ $design->accentSoft }}; border-left: 3px solid {{ $design->accent }}; }
    .footer { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #374151; }
    .footer .taxno { font-size: 10px; }
</style>
