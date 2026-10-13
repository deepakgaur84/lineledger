{{-- Minimal: monochrome, hairline rules, generous white space. --}}
<style>
    .logo-row { margin-bottom: 10px; }
    .company-name { font-size: 13px; }
    .title { font-size: 18px; font-weight: normal; color: {{ $design->accentText }}; text-transform: uppercase; letter-spacing: 0.25em; margin: 0 0 12px 0; text-align: right; }
    table.meta-list td { padding: 1px 0 1px 14px; font-size: 10px; }
    table.meta-list td.mlabel { color: #9ca3af; text-align: right; }
    table.meta-list td.mvalue { text-align: right; }
    .parties { margin-top: 34px; }
    .label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.12em; color: #9ca3af; margin-bottom: 4px; }
    table.lines { margin-top: 34px; }
    table.lines thead th { border-bottom: 1px solid {{ $design->accent }}; padding: 6px 8px; font-size: 8px; font-weight: normal; text-transform: uppercase; letter-spacing: 0.12em; color: #6b7280; text-align: left; }
    table.lines thead th.num { text-align: right; }
    table.lines td { padding: 8px; border-bottom: 1px solid #e5e7eb; }
    table.lines tr.zebra td { background: #f9fafb; }
    table.totals td { padding: 4px 8px; }
    table.totals tr.grand td { border-top: 1px solid {{ $design->accent }}; font-size: 13px; font-weight: bold; padding-top: 8px; }
    table.totals tr.due td { font-weight: bold; }
    .pay-box { margin-top: 26px; padding-top: 8px; border-top: 1px solid #e5e7eb; }
    .footer { margin-top: 30px; color: #6b7280; font-size: 10px; }
</style>
