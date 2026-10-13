{{-- Ledger: traditional bordered grid with striped rows. --}}
<style>
    .logo-row { margin-bottom: 8px; }
    .title { font-size: 30px; font-weight: bold; color: {{ $design->accentText }}; text-align: right; margin: 0 0 8px 0; letter-spacing: 0.02em; }
    .title.long { font-size: 22px; }
    table.meta-list { border: 1px solid #6b7280; }
    table.meta-list td { padding: 3px 8px; font-size: 10px; border-bottom: 1px solid #d1d5db; }
    table.meta-list td.mlabel { background: {{ $design->accentTint }}; font-weight: bold; text-align: left; }
    table.meta-list td.mvalue { text-align: right; }
    .parties { margin-top: 20px; }
    .party { border: 1px solid #6b7280; padding: 6px 8px; min-height: 64px; }
    .label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; color: #374151; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; margin-bottom: 4px; }
    table.lines { margin-top: 18px; border: 1px solid #6b7280; }
    table.lines thead th { background: {{ $design->accentTint }}; border: 1px solid #6b7280; padding: 5px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em; text-align: left; }
    table.lines thead th.num { text-align: right; }
    table.lines td { border: 1px solid #d1d5db; padding: 5px 8px; }
    table.lines tr.zebra td { background: {{ $design->accentSoft }}; }
    table.totals { border: 1px solid #6b7280; }
    table.totals td { border-bottom: 1px solid #d1d5db; }
    table.totals tr.grand td { background: {{ $design->accentTint }}; font-size: 13px; font-weight: bold; }
    table.totals tr.due td { font-weight: bold; }
    .pay-box { margin-top: 18px; border: 1px solid #6b7280; padding: 6px 8px; }
    .footer { margin-top: 24px; border-top: 1px solid #6b7280; padding-top: 8px; color: #374151; }
    .footer .taxno { font-size: 10px; }
</style>
