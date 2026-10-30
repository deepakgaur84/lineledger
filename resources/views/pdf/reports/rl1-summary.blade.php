<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>RL-1 Sommaire — {{ $company->name }} — {{ $year }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 2px 0; }
        .subtitle { color: #4b5563; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        thead th { background: #f3f4f6; text-align: left; font-size: 10px; text-transform: uppercase; color: #374151; }
        td.num, th.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        tfoot td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
        .wsdrf { margin-top: 14px; padding: 8px 10px; border: 1px solid #d1d5db; background: #f0fdfa; }
        .footer { margin-top: 16px; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    @php($fmt = fn ($c) => number_format($c / 100, 2))
    {{-- Finalized before boxes B.A, B.B and C: shown as it was issued, with one box B. --}}
    @php($legacy = ! array_key_exists('boxBA', $summary))
    <h1>RL-1 Sommaire {{ $year }}</h1>
    <div class="subtitle">
        {{ $company->name }} · {{ $summary['slip_count'] }} relevé(s)
        @if ($company->payroll_rq_identification_number)
            · No d'identification {{ $company->payroll_rq_identification_number }}@if ($company->payroll_rq_file_number) · Dossier {{ $company->payroll_rq_file_number }}@endif
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Employé</th>
                <th class="num">A Revenus</th>
                @if ($legacy)
                    <th class="num">B RRQ</th>
                @else
                    <th class="num">B.A RRQ</th><th class="num">B.B RRQ suppl.</th><th class="num">C AE</th>
                @endif
                <th class="num">E Impôt</th>
                <th class="num">G Adm. RRQ</th><th class="num">H RQAP</th><th class="num">I Adm. RQAP</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($slips as $slip)
            <tr>
                <td>{{ $slip['name'] }}</td>
                <td class="num">{{ $fmt($slip['boxA']) }}</td>
                @if ($legacy)
                    <td class="num">{{ $fmt($slip['boxB'] ?? 0) }}</td>
                @else
                    <td class="num">{{ $fmt($slip['boxBA'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($slip['boxBB'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($slip['boxC'] ?? 0) }}</td>
                @endif
                <td class="num">{{ $fmt($slip['boxE']) }}</td>
                <td class="num">{{ $fmt($slip['boxG']) }}</td>
                <td class="num">{{ $fmt($slip['boxH']) }}</td>
                <td class="num">{{ $fmt($slip['boxI']) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Totaux</td>
                <td class="num">{{ $fmt($summary['boxA']) }}</td>
                @if ($legacy)
                    <td class="num">{{ $fmt($summary['boxB'] ?? 0) }}</td>
                @else
                    <td class="num">{{ $fmt($summary['boxBA']) }}</td>
                    <td class="num">{{ $fmt($summary['boxBB']) }}</td>
                    <td class="num">{{ $fmt($summary['boxC']) }}</td>
                @endif
                <td class="num">{{ $fmt($summary['boxE']) }}</td>
                <td class="num">{{ $fmt($summary['boxG']) }}</td>
                <td class="num">{{ $fmt($summary['boxH']) }}</td>
                <td class="num">{{ $fmt($summary['boxI']) }}</td>
            </tr>
        </tfoot>
    </table>

    <p style="margin-top:14px;">
        @if ($legacy)
            Cotisation de l'employeur au RRQ : <strong>{{ $fmt($summary['employer_qpp']) }}</strong> &nbsp;·&nbsp;
        @else
            Cotisation de l'employeur au RRQ (ligne 22) : <strong>{{ $fmt($summary['employer_qpp']) }}</strong> &nbsp;·&nbsp;
            cotisation supplémentaire de l'employeur au RRQ (ligne 24) : <strong>{{ $fmt($summary['employer_qpp2'] ?? 0) }}</strong> &nbsp;·&nbsp;
        @endif
        au RQAP : <strong>{{ $fmt($summary['employer_qpip']) }}</strong> &nbsp;·&nbsp;
        au FSS (QHSF) : <strong>{{ $fmt($summary['qhsf']) }}</strong>
    </p>

    @if ($summary['wsdrf_applicable'])
        <div class="wsdrf">
            <strong>Formation de la main-d'œuvre (1 %)</strong><br>
            Masse salariale du Québec : {{ $fmt($summary['wsdrf_payroll_cents']) }} &nbsp;·&nbsp;
            Dépenses de formation admissibles : {{ $fmt($summary['wsdrf_training_cents']) }} &nbsp;·&nbsp;
            Cotisation due à Revenu Québec : <strong>{{ $fmt($summary['wsdrf_levy_cents']) }}</strong>
        </div>
    @endif

    <div class="footer">Généré le {{ \App\Support\Reporting\GeneratedAt::label($company) }}. Montants provenant des paies comptabilisées en {{ $year }}.</div>
</body>
</html>
