<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\Sales\SaveCreditMemo;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\TaxCode;
use App\Services\BulkImport\GroupedImporterDefinition;
use App\Services\Posting\CreditMemoPoster;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Credit Memos — the customer-side mirror of VendorCreditImporter; see that
 * class and BillImporter for the fuller shared design notes (import_ref
 * grouping, optional auto-numbered document number, auto-posting with a
 * draft fallback on a posting failure).
 *
 * Unlike vendor_credits, the credit_memos table DOES have currency_code/
 * fx_rate (confirmed against the shared add_currency_to_documents
 * migration, which covers invoices, bills and credit_memos), and
 * CreditMemoPoster respects an already-set fx_rate exactly like
 * BillPoster/InvoicePoster do — so, unlike VendorCreditImporter, this one
 * carries the same currency_code/fx_rate columns InvoiceImporter has.
 */
class CreditMemoImporter implements GroupedImporterDefinition
{
    public function key(): string
    {
        return 'credit-memos';
    }

    public function label(): string
    {
        return 'Credit Memos';
    }

    public function csvColumns(): array
    {
        return [
            'import_ref' => 'Required. Groups rows into one credit memo — every line of the same credit memo repeats the same import_ref. Only used to group rows in this file; never stored, and unrelated to credit_memo_no.',
            'credit_memo_no' => 'Optional. Left blank, the credit memo is numbered automatically the same way one entered by hand would be. Given, must be unique — a credit_memo_no already in use is rejected.',
            'customer_display_name' => "Required. Must match an existing customer's display name exactly (case-insensitive) — add the customer first via the Customers importer if they don't exist yet. If more than one customer shares this name, the row is rejected rather than guessing which one.",
            'credit_memo_date' => 'Required. Any unambiguous date works, e.g. 01-Apr-2026 or 2026-04-01.',
            'memo' => 'Optional. Same on every line of a credit memo — only the first line\'s value is used.',
            'account_code' => 'Required. The code of an existing income account, e.g. 4000 — not the account name.',
            'item_sku' => 'Optional. An existing item\'s SKU, if this line is for a stocked/service item rather than a plain charge.',
            'description' => 'Optional. Line description.',
            'quantity' => 'Required. Must be greater than 0.',
            'unit_price' => 'Required. Plain decimal, e.g. 19.99 — not cents.',
            'tax_code' => 'Optional. Must match an existing tax code by its code (see Settings > Tax Codes).',
            'currency_code' => "Optional. Only needed if the credit memo is in a foreign currency the customer doesn't already default to. Same on every line — only the first line's value is used.",
            'fx_rate' => "Optional. The exact rate this credit memo was agreed at — set this for historical imports where re-deriving today's rate would misstate what actually happened. Left blank, the rate as of credit_memo_date is fetched automatically. Same on every line — only the first line's value is used.",
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
            'credit_memo_no' => $first['credit_memo_no'] ?? null,
            'customer_display_name' => $first['customer_display_name'] ?? null,
            'credit_memo_date' => $first['credit_memo_date'] ?? null,
            'currency_code' => $first['currency_code'] ?? null,
            'fx_rate' => $first['fx_rate'] ?? null,
        ];

        $validator = Validator::make($header, [
            'credit_memo_no' => [
                'nullable', 'string', 'max:40',
                Rule::unique('credit_memos', 'credit_memo_no')->where('company_id', $company->id),
            ],
            'customer_display_name' => ['required', 'string'],
            'credit_memo_date' => ['required', 'date'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            $errors = array_merge($errors, $validator->errors()->all());
        }

        $this->resolveCustomer((string) $header['customer_display_name'], $company, $errors);

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
            'Credit Memo' => filled($first['credit_memo_no'] ?? null) ? (string) $first['credit_memo_no'] : __('(auto-numbered)'),
            'Customer' => (string) ($first['customer_display_name'] ?? ''),
            'Date' => (string) ($first['credit_memo_date'] ?? ''),
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

        $memo = app(SaveCreditMemo::class)->handle([
            'contact_id' => $customer->id,
            'credit_memo_no' => filled($first['credit_memo_no'] ?? null) ? $first['credit_memo_no'] : null,
            'credit_memo_date' => $first['credit_memo_date'],
            'memo' => $first['memo'] ?? null,
            'currency_code' => filled($first['currency_code'] ?? null) ? mb_strtoupper($first['currency_code']) : null,
            'lines' => $lines,
        ]);

        if (filled($first['fx_rate'] ?? null)) {
            $memo->forceFill(['fx_rate' => (string) $first['fx_rate']])->save();
        }

        // A posting failure (e.g. a locked period) still leaves a valid,
        // reviewable draft behind — reported as a distinct message rather
        // than losing the credit memo or masking it as a plain save
        // failure.
        try {
            app(CreditMemoPoster::class)->post($memo);
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('Credit memo :no was saved as a draft but could not be posted: :error', [
                'no' => $memo->credit_memo_no,
                'error' => $e->getMessage(),
            ]), previous: $e);
        }
    }

    public function sampleRows(): array
    {
        return [
            [
                'import_ref' => '1',
                'credit_memo_no' => 'CM-4001',
                'customer_display_name' => 'Northwind Retail',
                'credit_memo_date' => '20-Jan-2026',
                'memo' => 'Goodwill discount',
                'account_code' => '4000',
                'item_sku' => '',
                'description' => 'Consulting adjustment',
                'quantity' => '1',
                'unit_price' => '150.00',
                'tax_code' => 'GST',
                'currency_code' => '',
                'fx_rate' => '',
            ],
            [
                // credit_memo_no left blank here on purpose — this credit
                // memo is numbered automatically instead, same as one
                // entered by hand.
                'import_ref' => '2',
                'credit_memo_no' => '',
                'customer_display_name' => 'Harbourview Cafe',
                'credit_memo_date' => '21-Jan-2026',
                'memo' => '',
                'account_code' => '4000',
                'item_sku' => 'WIDGET-A',
                'description' => 'Returned widgets',
                'quantity' => '2',
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
