{{-- Elegant: centred serif header over a double rule, letter-spaced title. --}}
<style>
    .logo-row { margin-bottom: 8px; }
    .company-name { font-family: DejaVu Serif, serif; font-size: 18px; letter-spacing: 0.04em; }
    .double-rule { border-top: 1px solid {{ $design->accent }}; border-bottom: 1px solid {{ $design->accent }}; height: 3px; margin: 12px 0 14px 0; }
    .title { font-family: DejaVu Serif, serif; font-size: 20px; color: {{ $design->accentText }}; text-transform: uppercase; letter-spacing: 0.3em; }
    table.meta-strip { margin-top: 14px; }
    table.meta-strip td { padding: 4px 6px; font-size: 10px; }
    .label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.12em; color: {{ $design->accentText }}; margin-bottom: 3px; }
    .parties { margin-top: 22px; }
    .party { padding: 2px 0; }
    table.lines { margin-top: 22px; }
    table.lines thead th { border-top: 1px solid {{ $design->accent }}; border-bottom: 1px solid {{ $design->accent }}; padding: 6px 8px; font-family: DejaVu Serif, serif; font-size: 9px; font-weight: normal; text-transform: uppercase; letter-spacing: 0.1em; color: {{ $design->accentText }}; text-align: left; }
    table.lines thead th.num { text-align: right; }
    table.lines td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; }
    table.lines tr.zebra td { background: {{ $design->accentSoft }}; }
    table.totals tr.grand td { border-top: 1px solid {{ $design->accent }}; border-bottom: 1px solid {{ $design->accent }}; font-family: DejaVu Serif, serif; font-size: 13px; color: {{ $design->accentText }}; }
    table.totals tr.due td { font-weight: bold; }
    .pay-box { margin-top: 22px; padding: 8px 0; border-top: 1px solid {{ $design->accent }}; text-align: center; }
    .footer { margin-top: 24px; padding-top: 8px; text-align: center; color: #4b5563; }
    .footer .taxno { font-size: 10px; }
</style>
