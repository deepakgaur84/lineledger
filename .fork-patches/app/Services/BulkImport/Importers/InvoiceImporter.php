<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\Sales\SaveInvoice;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Services\BulkImport\GroupedImporterDefinition;
use App\Services\Posting\InvoicePoster;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Invoices — the customer-side mirror of BillImporter; see that class for
 * the fuller design notes, all of which apply equally here. One CSV row is
 * one LINE, not one invoice; rows sharing the same import_ref become one
 * invoice with multiple lines. import_ref only groups rows within this
 * file — it's never stored, and is separate from invoice_no (the actual
 * invoice number), which can be left blank to auto-number the invoice the
 * same way one entered by hand would be.
 *
 * Like BillImporter, this one POSTS the invoice after saving it (SaveInvoice
 * only creates a draft — see App\Services\Posting\InvoicePoster). A posting
 * failure still leaves a valid draft behind (caught separately from the
 * save itself — see commitGroup) rather than losing the row's data.
 */
class InvoiceImporter implements GroupedImporterDefinition
{
    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return 'Invoices';
    }

    public function csvColumns(): array
    {
        return [
            'import_ref' => 'Required. Groups rows into one invoice — every line of the same invoice repeats the same import_ref. Only used to group rows in this file; never stored, and unrelated to invoice_no.',
            'invoice_no' => 'Optional. Left blank, the invoice is numbered automatically the same way one entered by hand would be. Given, must be unique — an invoice_no already in use is rejected.',
            'customer_display_name' => "Required. Must match an existing customer's display name exactly (case-insensitive) — add the customer first via the Customers importer if they don't exist yet. If more than one customer shares this name, the row is rejected rather than guessing which one.",
            'customer_po' => "Optional. The customer's own purchase order number.",
            'invoice_date' => 'Required. Any unambiguous date works, e.g. 01-Apr-2026 or 2026-04-01.',
            'due_date' => 'Optional. Same date formats as invoice_date. Left blank, derived from payment_terms if given, else defaults to invoice_date. Must not be before invoice_date.',
            'payment_terms' => "Optional. Must match an existing payment term's name exactly (e.g. 'Net 30'). Ignored if due_date is given directly.",
            'memo' => 'Optional. Same on every line of an invoice — only the first line\'s value is used.',
            'account_code' => 'Required. The code of an existing income account, e.g. 4000 — not the account name.',
            'item_sku' => 'Optional. An existing item\'s SKU, if this line is for a stocked/service item rather than a plain charge.',
            'description' => 'Optional. Line description.',
            'quantity' => 'Required. Must be greater than 0.',
            'unit_price' => 'Required. Plain decimal, e.g. 19.99 — not cents.',
            'tax_code' => 'Optional. Must match an existing tax code by its code (see Settings > Tax Codes).',
            'currency_code' => "Optional. Only needed if the invoice is in a foreign currency the customer doesn't already default to. Same on every line — only the first line's value is used.",
            'fx_rate' => "Optional. The exact rate this invoice was agreed/settled at — set this for historical imports where re-deriving today's rate would misstate what actually happened. Left blank, the rate as of invoice_date is fetched automatically. Same on every line — only the first line's value is used.",
        ];
    }

    public function groupKey(array $row): ?string
    {
        $key = trim((string) ($row['import_ref'] ?? ''));

        return $key !== '' ? $key : null;
    }

    public function validateGroup(array $rows, Company $company): array
    {
        $errors = [];
        $first = $rows[0];

        $header = [
            'invoice_no' => $first['invoice_no'] ?? null,
            'customer_display_name' => $first['customer_display_name'] ?? null,
            'invoice_date' => $first['invoice_date'] ?? null,
            'due_date' => $first['due_date'] ?? null,
            'payment_terms' => $first['payment_terms'] ?? null,
            'currency_code' => $first['currency_code'] ?? null,
            'fx_rate' => $first['fx_rate'] ?? null,
        ];

        $validator = Validator::make($header, [
            'invoice_no' => [
                'nullable', 'string', 'max:40',
                Rule::unique('invoices', 'invoice_no')->where('company_id', $company->id),
            ],
            'customer_display_name' => ['required', 'string'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'payment_terms' => ['nullable', 'string'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            $errors = array_merge($errors, $validator->errors()->all());
        }

        $this->resolveCustomer((string) $header['customer_display_name'], $company, $errors);

        if (filled($header['payment_terms']) && empty($header['due_date'])) {
            $termExists = PaymentTerm::query()
                ->where('company_id', $company->id)
                ->where('name', $header['payment_terms'])
                ->exists();

            if (! $termExists) {
                $errors[] = __("Payment term ':name' not found.", ['name' => $header['payment_terms']]);
            }
        }

        foreach ($rows as $i => $line) {
            $lineNum = $i + 1;
            $lineValidator = Validator::make($line, [
                'account_code' => [
                    'required', 'string',
                    Rule::exists('accounts', 'code')->where('company_id', $company->id),
                ],
                'item_sku' => [
                    'nullable', 'string',
                    Rule::exists('items', 'sku')->where('company_id', $company->id),
                ],
                'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
                'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
                'tax_code' => [
                    'nullable', 'string',
                    Rule::exists('tax_codes', 'code')->where('company_id', $company->id),
                ],
            ]);

            if ($lineValidator->fails()) {
                foreach ($lineValidator->errors()->all() as $message) {
                    $errors[] = __('Line :n: :message', ['n' => $lineNum, 'message' => $message]);
                }
            }
        }

        return $errors;
    }

    public function summarizeGroup(array $rows, Company $company): array
    {
        $first = $rows[0];
        $total = array_reduce($rows, function (float $carry, array $line): float {
            $qty = (float) ($line['quantity'] ?? 0);
            $price = (float) ($line['unit_price'] ?? 0);

            return $carry + ($qty * $price);
        }, 0.0);

        return [
            'Invoice' => filled($first['invoice_no'] ?? null) ? (string) $first['invoice_no'] : __('(auto-numbered)'),
            'Customer' => (string) ($first['customer_display_name'] ?? ''),
            'Date' => (string) ($first['invoice_date'] ?? ''),
            'Lines' => (string) count($rows),
            'Subtotal (excl. tax)' => number_format($total, 2),
        ];
    }

    public function commitGroup(array $rows, Company $company): void
    {
        $first = $rows[0];

        $customer = Contact::query()
            ->where('company_id', $company->id)
            ->where('is_customer', true)
            ->whereRaw('LOWER(display_name) = ?', [mb_strtolower(trim((string) $first['customer_display_name']))])
            ->first();

        $termsId = null;
        if (filled($first['payment_terms'] ?? null) && empty($first['due_date'] ?? null)) {
            $termsId = PaymentTerm::query()
                ->where('company_id', $company->id)
                ->where('name', $first['payment_terms'])
                ->value('id');
        }

        $lines = [];
        foreach ($rows as $line) {
            $accountId = Account::query()
                ->where('company_id', $company->id)
                ->where('code', $line['account_code'])
                ->value('id');

            $itemId = filled($line['item_sku'] ?? null)
                ? Item::query()->where('company_id', $company->id)->where('sku', $line['item_sku'])->value('id')
                : null;

            $taxCodeId = filled($line['tax_code'] ?? null)
                ? TaxCode::query()->where('company_id', $company->id)->where('code', $line['tax_code'])->value('id')
                : null;

            $lines[] = [
                'item_id' => $itemId,
                'account_id' => $accountId,
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'],
                'unit_price_cents' => (int) round(((float) $line['unit_price']) * 100),
                'tax_code_id' => $taxCodeId,
            ];
        }

        $invoice = app(SaveInvoice::class)->handle([
            'contact_id' => $customer->id,
            'invoice_no' => filled($first['invoice_no'] ?? null) ? $first['invoice_no'] : null,
            'customer_po' => $first['customer_po'] ?? null,
            'invoice_date' => $first['invoice_date'],
            'due_date' => $first['due_date'] ?? null,
            'terms_id' => $termsId,
            'memo' => $first['memo'] ?? null,
            'currency_code' => filled($first['currency_code'] ?? null) ? mb_strtoupper($first['currency_code']) : null,
            'lines' => $lines,
        ]);

        if (filled($first['fx_rate'] ?? null)) {
            $invoice->forceFill(['fx_rate' => (string) $first['fx_rate']])->save();
        }

        // A posting failure (e.g. a locked period) still leaves a valid,
        // reviewable draft behind — reported as a distinct message rather
        // than losing the invoice or masking it as a plain save failure.
        try {
            app(InvoicePoster::class)->post($invoice);
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('Invoice :no was saved as a draft but could not be posted: :error', [
                'no' => $invoice->invoice_no,
                'error' => $e->getMessage(),
            ]), previous: $e);
        }
    }

    public function sampleRows(): array
    {
        return [
            [
                'import_ref' => '1',
                'invoice_no' => 'INV-2001',
                'customer_display_name' => 'Northwind Retail',
                'customer_po' => 'PO-8821',
                'invoice_date' => '15-Jan-2026',
                'due_date' => '',
                'payment_terms' => 'Net 30',
                'memo' => '',
                'account_code' => '4000',
                'item_sku' => '',
                'description' => 'Consulting — January',
                'quantity' => '1',
                'unit_price' => '1200.00',
                'tax_code' => 'GST',
                'currency_code' => '',
                'fx_rate' => '',
            ],
            [
                'import_ref' => '1',
                'invoice_no' => 'INV-2001',
                'customer_display_name' => 'Northwind Retail',
                'customer_po' => 'PO-8821',
                'invoice_date' => '15-Jan-2026',
                'due_date' => '',
                'payment_terms' => 'Net 30',
                'memo' => '',
                'account_code' => '4100',
                'item_sku' => '',
                'description' => 'Travel expenses recharged',
                'quantity' => '1',
                'unit_price' => '85.00',
                'tax_code' => 'GST',
                'currency_code' => '',
                'fx_rate' => '',
            ],
            [
                // invoice_no left blank here on purpose — this invoice is
                // numbered automatically instead, same as one entered by hand.
                'import_ref' => '2',
                'invoice_no' => '',
                'customer_display_name' => 'Harbourview Cafe',
                'customer_po' => '',
                'invoice_date' => '16-Jan-2026',
                'due_date' => '15-Feb-2026',
                'payment_terms' => '',
                'memo' => '',
                'account_code' => '4000',
                'item_sku' => 'WIDGET-A',
                'description' => 'Widgets',
                'quantity' => '5',
                'unit_price' => '40.00',
                'tax_code' => 'GST',
                'currency_code' => '',
                'fx_rate' => '',
            ],
        ];
    }

    /**
     * Requires exactly one match against is_customer contacts, mirroring
     * BillImporter's own resolveVendor() (and FindContactTool's ambiguity
     * handling) — refuses to guess between two customers sharing a name
     * rather than silently picking one.
     */
    private function resolveCustomer(string $name, Company $company, array &$errors): ?Contact
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $matches = Contact::query()
            ->where('company_id', $company->id)
            ->where('is_customer', true)
            ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($name)])
            ->get();

        if ($matches->isEmpty()) {
            $errors[] = __("Customer ':name' not found.", ['name' => $name]);

            return null;
        }

        if ($matches->count() > 1) {
            $errors[] = __("More than one customer is named ':name' — rename one of them, or use a different name, before importing.", ['name' => $name]);

            return null;
        }

        return $matches->first();
    }
}
