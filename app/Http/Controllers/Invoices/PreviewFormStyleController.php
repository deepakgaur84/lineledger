<?php

namespace App\Http\Controllers\Invoices;

use App\Enums\InvoiceFont;
use App\Enums\InvoiceLayout;
use App\Enums\InvoiceSection;
use App\Enums\LogoPosition;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FormStyle;
use App\Models\Invoice;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Support\Sales\SampleInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Inline PDF of a form style, drawn on the company's most recent invoice (or a
 * built-in sample when it has none) — the invoice designer's preview. Works on a
 * saved style, or on the unsaved settings currently in the designer form.
 */
class PreviewFormStyleController extends Controller
{
    public function __invoke(Request $request, Company $company, int $style, InvoicePdfRenderer $renderer): Response
    {
        $this->allowFraming($request);
        $formStyle = FormStyle::query()->where('company_id', $company->id)->findOrFail($style);

        return $renderer->previewInline($company, $this->invoiceFor($company), $formStyle);
    }

    /** Preview the settings in the designer form without saving them. */
    public function draft(Company $company, Request $request, InvoicePdfRenderer $renderer): Response
    {
        $this->allowFraming($request);

        $data = $request->validate([
            'layout' => ['required', Rule::enum(InvoiceLayout::class)],
            'font_family' => ['nullable', Rule::enum(InvoiceFont::class)],
            'logo_position' => ['nullable', Rule::enum(LogoPosition::class)],
            'logo_max_height' => ['nullable', 'integer', 'min:16', 'max:200'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'title_text' => ['nullable', 'string', 'max:40'],
            'footer_message' => ['nullable', 'string', 'max:2000'],
            'sections' => ['nullable', 'string', 'max:200'],
        ]);

        $formStyle = new FormStyle([
            'company_id' => $company->id,
            'name' => 'Preview',
            'layout' => $data['layout'],
            'font_family' => $data['font_family'] ?? InvoiceFont::default()->value,
            'logo_position' => $data['logo_position'] ?? LogoPosition::default()->value,
            'logo_max_height' => $data['logo_max_height'] ?? null,
            'accent_color' => $data['accent_color'] ?? null,
            'title_text' => $data['title_text'] ?? null,
            'footer_message' => $data['footer_message'] ?? null,
            'sections' => InvoiceSection::parse($data['sections'] ?? null),
            'show_logo' => $request->boolean('show_logo', true),
            'show_ship_to' => $request->boolean('show_ship_to', true),
            'zebra_rows' => $request->boolean('zebra_rows'),
        ]);

        return $renderer->previewInline($company, $this->invoiceFor($company), $formStyle);
    }

    /** The designer shows these PDFs in an <iframe> on its own page. */
    private function allowFraming(Request $request): void
    {
        $request->attributes->set('allow_same_origin_framing', true);
    }

    private function invoiceFor(Company $company): Invoice
    {
        return Invoice::query()
            ->where('company_id', $company->id)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->first() ?? SampleInvoice::make($company);
    }
}
