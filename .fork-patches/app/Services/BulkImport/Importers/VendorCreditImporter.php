<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\Purchasing\SaveVendorCredit;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\TaxCode;
use App\Services\BulkImport\GroupedImporterDefinition;
use App\Services\Posting\VendorCreditPoster;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Vendor Credits — see BillImporter for the fuller design notes shared with
 * every grouped importer here (import_ref grouping, optional auto-numbered
 * document number, auto-posting with a draft fallback on a posting failure).
 *
 * Deliberately no currency_code/fx_rate columns, unlike Bills/Invoices/
 * Credit Memos: the vendor_credits table itself has no currency_code or
 * fx_rate column at all (confirmed directly against the schema and the
 * shared add_currency_to_documents migration, which covers invoices, bills
 * and credit_memos but not vendor_credits) — a real, pre-existing gap in
 * the app itself, not an oversight here. Offering the columns anyway would
 * promise something the app can't actually do.
 */
class VendorCreditImporter implements GroupedImporterDefinition
{
    public function key(): string
    {
        return 'vendor-credits';
    }

    public function label(): string
    {
        return 'Vendor Credits';
    }

    public function csvColumns(): array
    {
        return [
            'import_ref' => 'Required. Groups rows into one vendor credit — every line of the same credit repeats the same import_ref. Only used to group rows in this file; never stored, and unrelated to vendor_credit_no.',
            'vendor_credit_no' => 'Optional. Left blank, the credit is numbered automatically the same way one entered by hand would be. Given, must be unique — a vendor_credit_no already in use is rejected.',
            'vendor_display_name' => "Required. Must match an existing vendor's display name exactly (case-insensitive) — add the vendor first via the Vendors importer if they don't exist yet. If more than one vendor shares this name, the row is rejected rather than guessing which one.",
            'vendor_credit_date' => 'Required. Any unambiguous date works, e.g. 01-Apr-2026 or 2026-04-01.',
            'memo' => 'Optional. Same on every line of a credit — only the first line\'s value is used.',
            'account_code' => 'Required. The code of an existing expense/asset account, e.g. 6100 — not the account name.',
            'item_sku' => 'Optional. An existing item\'s SKU, if this line is for a stocked/service item rather than a plain expense.',
            'description' => 'Optional. Line description.',
            'quantity' => 'Required. Must be greater than 0.',
            'unit_price' => 'Required. Plain decimal, e.g. 19.99 — not cents.',
            'tax_code' => 'Optional. Must match an existing tax code by its code (see Settings > Tax Codes).',
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
            'vendor_credit_no' => $first['vendor_credit_no'] ?? null,
            'vendor_display_name' => $first['vendor_display_name'] ?? null,
            'vendor_credit_date' => $first['vendor_credit_date'] ?? null,
        ];

        $validator = Validator::make($header, [
            'vendor_credit_no' => [
                'nullable', 'string', 'max:40',
                Rule::unique('vendor_credits', 'vendor_credit_no')->where('company_id', $company->id),
            ],
            'vendor_display_name' => ['required', 'string'],
            'vendor_credit_date' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            $errors = array_merge($errors, $validator->errors()->all());
        }

        $this->resolveVendor((string) $header['vendor_display_name'], $company, $errors);

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
            'Vendor Credit' => filled($first['vendor_credit_no'] ?? null) ? (string) $first['vendor_credit_no'] : __('(auto-numbered)'),
            'Vendor' => (string) ($first['vendor_display_name'] ?? ''),
            'Date' => (string) ($first['vendor_credit_date'] ?? ''),
            'Lines' => (string) count($rows),
            'Subtotal (excl. tax)' => number_format($total, 2),
        ];
    }

    public function commitGroup(array $rows, Company $company): void
    {
        $first = $rows[0];

        $vendor = Contact::query()
            ->where('company_id', $company->id)
            ->where('is_vendor', true)
            ->whereRaw('LOWER(display_name) = ?', [mb_strtolower(trim((string) $first['vendor_display_name']))])
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

        $credit = app(SaveVendorCredit::class)->handle([
            'contact_id' => $vendor->id,
            'vendor_credit_no' => filled($first['vendor_credit_no'] ?? null) ? $first['vendor_credit_no'] : null,
            'vendor_credit_date' => $first['vendor_credit_date'],
            'memo' => $first['memo'] ?? null,
            'lines' => $lines,
        ]);

        // A posting failure (e.g. a locked period) still leaves a valid,
        // reviewable draft behind — reported as a distinct message rather
        // than losing the credit or masking it as a plain save failure.
        try {
            app(VendorCreditPoster::class)->post($credit);
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('Vendor credit :no was saved as a draft but could not be posted: :error', [
                'no' => $credit->vendor_credit_no,
                'error' => $e->getMessage(),
            ]), previous: $e);
        }
    }

    public function sampleRows(): array
    {
        return [
            [
                'import_ref' => '1',
                'vendor_credit_no' => 'VC-3001',
                'vendor_display_name' => 'Acme Supplies',
                'vendor_credit_date' => '20-Jan-2026',
                'memo' => 'Returned damaged stock',
                'account_code' => '6100',
                'item_sku' => '',
                'description' => 'Office chair — returned',
                'quantity' => '1',
                'unit_price' => '250.00',
                'tax_code' => 'GST',
            ],
            [
                // vendor_credit_no left blank here on purpose — this credit
                // is numbered automatically instead, same as one entered by
                // hand.
                'import_ref' => '2',
                'vendor_credit_no' => '',
                'vendor_display_name' => 'Beta Traders',
                'vendor_credit_date' => '21-Jan-2026',
                'memo' => '',
                'account_code' => '5200',
                'item_sku' => 'WIDGET-A',
                'description' => 'Defective widgets returned',
                'quantity' => '2',
                'unit_price' => '25.00',
                'tax_code' => 'GST',
            ],
        ];
    }

    /**
     * Requires exactly one match against is_vendor contacts, mirroring
     * BillImporter's own resolveVendor() (and FindContactTool's ambiguity
     * handling) — refuses to guess between two vendors sharing a name
     * rather than silently picking one.
     */
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
