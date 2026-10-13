<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FormStyle;
use App\Models\Invoice;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Support\Sales\SampleInvoice;
use Illuminate\Http\Response;

/**
 * Inline PDF of a saved form style, drawn on the company's most recent invoice
 * (or a built-in sample when it has none) — the invoice designer's preview.
 */
class PreviewFormStyleController extends Controller
{
    public function __invoke(Company $company, int $style, InvoicePdfRenderer $renderer): Response
    {
        $formStyle = FormStyle::query()->where('company_id', $company->id)->findOrFail($style);

        $invoice = Invoice::query()
            ->where('company_id', $company->id)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->first() ?? SampleInvoice::make($company);

        return $renderer->previewInline($company, $invoice, $formStyle);
    }
}
