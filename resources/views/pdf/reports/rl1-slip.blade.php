<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>RL-1 — {{ $slip['name'] }} — {{ $year }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 2px 0; }
        .subtitle { color: #4b5563; margin-bottom: 14px; }
        .meta { color: #6b7280; font-size: 10px; margin-bottom: 16px; }
        .boxes { width: 100%; border-collapse: collapse; }
        .boxes td { width: 33%; padding: 8px; border: 1px solid #d1d5db; vertical-align: top; }
        .box-label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
        .box-value { font-family: DejaVu Sans Mono, monospace; font-size: 14px; font-weight: bold; }
        .footer { margin-top: 18px; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    @php($fmt = fn ($c) => number_format($c / 100, 2))
    {{-- RL-1.G-V: leave B.A, B.B, C, E and H blank when nothing was withheld. --}}
    @php($blankIfNone = fn ($c) => (int) $c === 0 ? '' : $fmt($c))
    <h1>RL-1 — Revenus d'emploi et revenus divers</h1>
    <div class="subtitle">{{ $company->name }} · Année d'imposition {{ $year }}</div>
    <div class="meta">
        Employé : {{ $slip['name'] }}@if ($slip['sin_last4']) · NAS •••• {{ $slip['sin_last4'] }}@endif
    </div>

    @if (array_key_exists('boxBA', $slip))
        <table class="boxes">
            <tr>
                <td><div class="box-label">Case A — Revenus d'emploi</div><div class="box-value">{{ $fmt($slip['boxA']) }}</div></td>
                <td><div class="box-label">Case B.A — Cotisation au RRQ</div><div class="box-value">{{ $blankIfNone($slip['boxBA']) }}</div></td>
                <td><div class="box-label">Case B.B — Cotisation supplémentaire au RRQ</div><div class="box-value">{{ $blankIfNone($slip['boxBB']) }}</div></td>
            </tr>
            <tr>
                <td><div class="box-label">Case C — Cotisation à l'assurance emploi</div><div class="box-value">{{ $blankIfNone($slip['boxC']) }}</div></td>
                <td><div class="box-label">Case E — Impôt du Québec retenu</div><div class="box-value">{{ $blankIfNone($slip['boxE']) }}</div></td>
                <td><div class="box-label">Case G — Salaire admissible au RRQ</div><div class="box-value">{{ $fmt($slip['boxG']) }}</div></td>
            </tr>
            <tr>
                <td><div class="box-label">Case H — Cotisation au RQAP</div><div class="box-value">{{ $blankIfNone($slip['boxH']) }}</div></td>
                <td><div class="box-label">Case I — Salaire admissible au RQAP</div><div class="box-value">{{ $fmt($slip['boxI']) }}</div></td>
                <td></td>
            </tr>
        </table>
    @else
        {{-- A slip finalized before boxes B.A, B.B and C, shown as it was issued. --}}
        <table class="boxes">
            <tr>
                <td><div class="box-label">Case A — Revenus d'emploi</div><div class="box-value">{{ $fmt($slip['boxA']) }}</div></td>
                <td><div class="box-label">Case B — Cotisation au RRQ</div><div class="box-value">{{ $fmt($slip['boxB'] ?? 0) }}</div></td>
                <td><div class="box-label">Case E — Impôt du Québec retenu</div><div class="box-value">{{ $fmt($slip['boxE']) }}</div></td>
            </tr>
            <tr>
                <td><div class="box-label">Case G — Salaire admissible au RRQ</div><div class="box-value">{{ $fmt($slip['boxG']) }}</div></td>
                <td><div class="box-label">Case H — Cotisation au RQAP</div><div class="box-value">{{ $fmt($slip['boxH']) }}</div></td>
                <td><div class="box-label">Case I — Salaire admissible au RQAP</div><div class="box-value">{{ $fmt($slip['boxI']) }}</div></td>
            </tr>
        </table>
    @endif

    @if (config('payroll.rl1.authorization_number'))
        <div class="footer">{{ config('payroll.rl1.authorization_number') }}</div>
    @else
        <div class="footer" style="font-weight: bold;">COPIE DE TRAVAIL — NE PAS TRANSMETTRE / WORKING COPY — NOT FOR FILING. Un relevé 1 officiel sur papier exige un numéro d'autorisation de Revenu Québec (FS·······, RQ_AUTHORIZATION_NUMBER). Transmettez plutôt le fichier XML RL-1.</div>
    @endif
    <div class="footer">Généré le {{ \App\Support\Reporting\GeneratedAt::label($company) }}. Montants provenant des paies comptabilisées en {{ $year }}. Vérifiez les exigences de Revenu Québec avant l'émission.</div>
</body>
</html>
