<?php

namespace App\Services\Reporting;

use App\Models\Company;
use App\Models\FormStyle;
use App\Models\Invoice;
use App\Support\Sales\InvoiceDesign;
use App\Support\Tax\LineTaxBreakdown;
use Illuminate\Http\Response;

/**
 * Single source for rendering an invoice to PDF — the view, tax summary and
 * embedded logo. Used by the staff print route, the customer portal, and invoice
 * emails so the document is identical everywhere.
 */
class InvoicePdfRenderer
{
    public function __construct(protected PdfExporter $pdf) {}

    public function filename(Invoice $invoice): string
    {
        return 'invoice-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $invoice->invoice_no).'.pdf';
    }

    /**
     * Inline response (browser PDF viewer / print dialog).
     */
    public function inline(Company $company, Invoice $invoice): Response
    {
        $data = $this->data($company, $invoice);

        return $this->pdf->inline($data['design']->layout->view(), $data, $this->filename($invoice));
    }

    /**
     * Raw PDF bytes — for attaching to an email.
     */
    public function raw(Company $company, Invoice $invoice): string
    {
        $data = $this->data($company, $invoice);

        return $this->pdf->raw($data['design']->layout->view(), $data);
    }

    /**
     * Inline PDF of $invoice drawn with an explicit (usually unsaved or just
     * saved) style instead of the invoice's own — the designer's preview.
     */
    public function previewInline(Company $company, Invoice $invoice, FormStyle $style): Response
    {
        $data = $this->data($company, $invoice, $style);

        return $this->pdf->inline($data['design']->layout->view(), $data, 'invoice-preview.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Company $company, Invoice $invoice, ?FormStyle $styleOverride = null): array
    {
        $invoice->loadMissing('lines.taxCode', 'lines.item', 'contact', 'terms', 'salesRep', 'formStyle');

        // The invoice's chosen style, else the company default. The explicit
        // company_id filter matters for queued contexts (e.g. email sending)
        // where the `current_company` global scope is not bound. Null when the
        // company has no styles — the blade then renders exactly as before.
        $style = $styleOverride ?: $invoice->formStyle ?: FormStyle::query()
            ->where('company_id', $company->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        return [
            'company' => $company,
            'invoice' => $invoice,
            'settings' => $company->invoiceSettingsOrNew(),
            'style' => $style,
            'design' => InvoiceDesign::for($style),
            'taxSummary' => $this->taxSummary($invoice),
        ];
    }

    /**
     * Per-tax-code summary rows, grouping invoice lines on their tax code.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    private function taxSummary(Invoice $invoice): array
    {
        return LineTaxBreakdown::forLines($invoice->lines);
    }

    private function locale(Company $company, Invoice $invoice): string
    {
        $invoice->loadMissing('contact');

        return Locales::forDocument($invoice->contact, $company);
    }
}
