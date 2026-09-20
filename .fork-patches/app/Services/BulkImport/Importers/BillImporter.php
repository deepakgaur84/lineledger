<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\Purchasing\SaveBill;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Services\BulkImport\GroupedImporterDefinition;
use App\Services\Posting\BillPoster;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Bills — one CSV row is one LINE, not one bill; rows sharing the same
 * bill_no become one bill with multiple lines. Column set/validation
 * deliberately mirrors StoreBillRequest (the API's own rules), so an
 * imported bill is held to exactly the same standard as one entered via
 * the API. There's no CSV importer to mimic here — Migration's own
 * OpenBillsImporter is an opening-balance stub (a single synthetic line
 * to Opening Balance Equity), not a real multi-line bill importer.
 *
 * Unlike every other importer here, this one POSTS the bill after saving
 * it (SaveBill only creates a draft — see App\Services\Posting\BillPoster).
 * A bulk historical import left entirely as unposted drafts would need
 * clicking into every single one to post manually; auto-posting matches
 * what a migration tool should do. A posting failure still leaves a valid
 * draft behind (caught separately from the save itself — see commitGroup)
 * rather than losing the row's data.
 */
class BillImporter implements GroupedImporterDefinition
{
    public function key(): string
    {
        return 'bills';
    }

    public function label(): string
    {
        return 'Bills';
    }

    public function csvColumns(): array
    {
        return [
            'bill_no' => 'Required. Groups rows into one bill — every line of the same bill repeats the same bill_no. Must be unique; a bill_no already in use is rejected.',
            'vendor_display_name' => "Required. Must match an existing vendor's display name exactly (case-insensitive) — add the vendor first via the Vendors importer if they don't exist yet. If more than one vendor shares this name, the row is rejected rather than guessing which one.",
            'vendor_reference' => "Optional. The vendor's own invoice/reference number.",
            'bill_date' => 'Required. YYYY-MM-DD.',
            'due_date' => 'Optional. YYYY-MM-DD. Left blank, derived from payment_terms if given, else defaults to bill_date. Must not be before bill_date.',
            'payment_terms' => "Optional. Must match an existing payment term's name exactly (e.g. 'Net 30'). Ignored if due_date is given directly.",
            'memo' => 'Optional. Same on every line of a bill — only the first line\'s value is used.',
            'account_code' => 'Required. The code of an existing expense/asset account, e.g. 6100 — not the account name.',
            'item_sku' => 'Optional. An existing item\'s SKU, if this line is for a stocked/service item rather than a plain expense.',
            'description' => 'Optional. Line description.',
            'quantity' => 'Required. Must be greater than 0.',
            'unit_price' => 'Required. Plain decimal, e.g. 19.99 — not cents.',
            'tax_code' => 'Optional. Must match an existing tax code by its code (see Settings > Tax Codes).',
            'currency_code' => "Optional. Only needed if the bill is in a foreign currency the vendor doesn't already default to. Same on every line — only the first line's value is used.",
            'fx_rate' => "Optional. The exact rate this bill was agreed/paid at — set this for historical imports where re-deriving today's rate would misstate what actually happened. Left blank, the rate as of bill_date is fetched automatically. Same on every line — only the first line's value is used.",
        ];
    }

    public function groupKey(array $row): ?string
    {
        $key = trim((string) ($row['bill_no'] ?? ''));

        return $key !== '' ? $key : null;
    }

    public function validateGroup(array $rows, Company $company): array
    {
        $errors = [];
        $first = $rows[0];

        $header = [
            'bill_no' => $first['bill_no'] ?? null,
            'vendor_display_name' => $first['vendor_display_name'] ?? null,
            'bill_date' => $first['bill_date'] ?? null,
            'due_date' => $first['due_date'] ?? null,
            'payment_terms' => $first['payment_terms'] ?? null,
            'currency_code' => $first['currency_code'] ?? null,
            'fx_rate' => $first['fx_rate'] ?? null,
        ];

        $validator = Validator::make($header, [
            'bill_no' => [
                'required', 'string', 'max:40',
                Rule::unique('bills', 'bill_no')->where('company_id', $company->id),
            ],
            'vendor_display_name' => ['required', 'string'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'payment_terms' => ['nullable', 'string'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            $errors = array_merge($errors, $validator->errors()->all());
        }

        $this->resolveVendor((string) $header['vendor_display_name'], $company, $errors);

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

        $summary = [
            'Bill' => (string) ($first['bill_no'] ?? ''),
            'Vendor' => (string) ($first['vendor_display_name'] ?? ''),
            'Date' => (string) ($first['bill_date'] ?? ''),
            'Lines' => (string) count($rows),
            'Subtotal (excl. tax)' => number_format($total, 2),
        ];

        return $summary;
    }

    public function commitGroup(array $rows, Company $company): void
    {
        $first = $rows[0];

        $vendor = Contact::query()
            ->where('company_id', $company->id)
            ->where('is_vendor', true)
            ->whereRaw('LOWER(display_name) = ?', [mb_strtolower(trim((string) $first['vendor_display_name']))])
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
                ? \App\Models\Item::query()->where('company_id', $company->id)->where('sku', $line['item_sku'])->value('id')
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

        $bill = app(SaveBill::class)->handle([
            'contact_id' => $vendor->id,
            'bill_no' => $first['bill_no'],
            'vendor_reference' => $first['vendor_reference'] ?? null,
            'bill_date' => $first['bill_date'],
            'due_date' => $first['due_date'] ?? null,
            'terms_id' => $termsId,
            'memo' => $first['memo'] ?? null,
            'currency_code' => filled($first['currency_code'] ?? null) ? mb_strtoupper($first['currency_code']) : null,
            'lines' => $lines,
        ]);

        if (filled($first['fx_rate'] ?? null)) {
            $bill->forceFill(['fx_rate' => (string) $first['fx_rate']])->save();
        }

        // A posting failure (e.g. a locked period) still leaves a valid,
        // reviewable draft behind — reported as a distinct message rather
        // than losing the bill or masking it as a plain save failure.
        try {
            app(BillPoster::class)->post($bill);
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('Bill :no was saved as a draft but could not be posted: :error', [
                'no' => $bill->bill_no,
                'error' => $e->getMessage(),
            ]), previous: $e);
        }
    }

    public function sampleRows(): array
    {
        return [
            [
                'bill_no' => 'BILL-1001',
                'vendor_display_name' => 'Acme Supplies',
                'vendor_reference' => 'INV-4471',
                'bill_date' => '2026-01-15',
                'due_date' => '',
                'payment_terms' => 'Net 30',
                'memo' => '',
                'account_code' => '6100',
                'item_sku' => '',
                'description' => 'Office rent — January',
                'quantity' => '1',
                'unit_price' => '850.00',
                'tax_code' => 'GST',
                'currency_code' => '',
                'fx_rate' => '',
            ],
            [
                'bill_no' => 'BILL-1001',
                'vendor_display_name' => 'Acme Supplies',
                'vendor_reference' => 'INV-4471',
                'bill_date' => '2026-01-15',
                'due_date' => '',
                'payment_terms' => 'Net 30',
                'memo' => '',
                'account_code' => '6300',
                'item_sku' => '',
                'description' => 'Internet',
                'quantity' => '1',
                'unit_price' => '120.00',
                'tax_code' => 'GST',
                'currency_code' => '',
                'fx_rate' => '',
            ],
            [
                'bill_no' => 'BILL-1002',
                'vendor_display_name' => 'Beta Traders',
                'vendor_reference' => '',
                'bill_date' => '2026-01-16',
                'due_date' => '2026-02-15',
                'payment_terms' => '',
                'memo' => '',
                'account_code' => '5200',
                'item_sku' => 'WIDGET-A',
                'description' => 'Widgets',
                'quantity' => '10',
                'unit_price' => '25.00',
                'tax_code' => 'GST',
                'currency_code' => '',
                'fx_rate' => '',
            ],
        ];
    }

    private function resolveVendor(string $name, Company $company, array &$errors): ?Contact
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $matches = Contact::query()
            ->where('company_id', $company->id)
            ->where('is_vendor', true)
            ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($name)])
            ->get();

        if ($matches->isEmpty()) {
            $errors[] = __("Vendor ':name' not found.", ['name' => $name]);

            return null;
        }

        if ($matches->count() > 1) {
            $errors[] = __("More than one vendor is named ':name' — rename one of them, or use a different name, before importing.", ['name' => $name]);

            return null;
        }

        return $matches->first();
    }
}
