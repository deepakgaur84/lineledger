<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\Sales\SaveReceipt;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Services\BulkImport\GroupedImporterDefinition;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Receipts — the customer-side mirror of BillPaymentImporter; see that
 * class and BillImporter for the fuller shared design notes (import_ref
 * grouping, one row per application rather than per line, optional
 * auto-numbered document number, the total being the sum of application
 * amounts rather than its own column, auto-posting with a draft fallback
 * on a posting failure).
 *
 * One real, deliberate difference from Bill Payments, confirmed directly
 * against StoreReceiptRequest rather than assumed: it checks an
 * application's invoice belongs to the same customer, but — unlike
 * StoreBillPaymentRequest's equivalent check — does NOT require the
 * invoice to still be open (posted/partial). Mirrored here exactly as
 * the API itself validates it, not "corrected" to match Bill Payments'
 * stricter rule.
 */
class CustomerReceiptImporter implements GroupedImporterDefinition
{
    public function key(): string
    {
        return 'receipts';
    }

    public function label(): string
    {
        return 'Receipts';
    }

    public function csvColumns(): array
    {
        return [
            'import_ref' => 'Required. Groups rows into one receipt — every application in the same receipt repeats the same import_ref. Only used to group rows in this file; never stored, and unrelated to receipt_no.',
            'receipt_no' => 'Optional. Left blank, the receipt is numbered automatically the same way one entered by hand would be. Given, must be unique — a receipt_no already in use is rejected.',
            'customer_display_name' => "Required. Must match an existing customer's display name exactly (case-insensitive). If more than one customer shares this name, the row is rejected rather than guessing which one.",
            'receipt_date' => 'Required. Any unambiguous date works, e.g. 01-Apr-2026 or 2026-04-01.',
            'deposit_to_account_code' => 'Required. The code of the bank/asset account the money was deposited into, e.g. 1000 — not the account name.',
            'payment_method' => "Optional. Must match an existing payment method's name exactly (see Settings > Payment Methods).",
            'reference' => 'Optional. A cheque number or bank reference. Same on every row of a receipt — only the first row\'s value is used.',
            'memo' => 'Optional. Same on every row of a receipt — only the first row\'s value is used.',
            'invoice_no' => "Required per row. The invoice this row's amount is applied to — must belong to the same customer.",
            'application_amount' => 'Required per row. How much of this receipt applies to that invoice. The receipt\'s own total is the sum of every row\'s application_amount — not a separate column.',
            'currency_code' => "Optional. Only needed if the receipt is in a foreign currency the customer doesn't already default to. Same on every row — only the first row's value is used.",
            'fx_rate' => "Optional. The exact rate this receipt was recorded at — set this for historical imports where re-deriving today's rate would misstate what actually happened. Left blank, the rate as of receipt_date is fetched automatically. Same on every row — only the first row's value is used.",
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
            'receipt_no' => $first['receipt_no'] ?? null,
            'customer_display_name' => $first['customer_display_name'] ?? null,
            'receipt_date' => $first['receipt_date'] ?? null,
            'deposit_to_account_code' => $first['deposit_to_account_code'] ?? null,
            'payment_method' => $first['payment_method'] ?? null,
            'currency_code' => $first['currency_code'] ?? null,
            'fx_rate' => $first['fx_rate'] ?? null,
        ];

        $validator = Validator::make($header, [
            'receipt_no' => [
                'nullable', 'string', 'max:40',
                Rule::unique('customer_receipts', 'receipt_no')->where('company_id', $company->id),
            ],
            'customer_display_name' => ['required', 'string'],
            'receipt_date' => ['required', 'date'],
            'deposit_to_account_code' => [
                'required', 'string',
                Rule::exists('accounts', 'code')->where('company_id', $company->id),
            ],
            'payment_method' => [
                'nullable', 'string',
                Rule::exists('payment_methods', 'name')->where('company_id', $company->id),
            ],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            $errors = array_merge($errors, $validator->errors()->all());
        }

        $customer = $this->resolveCustomer((string) $header['customer_display_name'], $company, $errors);

        foreach ($rows as $i => $row) {
            $lineNum = $i + 1;
            $lineValidator = Validator::make($row, [
                'invoice_no' => ['required', 'string'],
                'application_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            ]);

            if ($lineValidator->fails()) {
                foreach ($lineValidator->errors()->all() as $message) {
                    $errors[] = __('Row :n: :message', ['n' => $lineNum, 'message' => $message]);
                }

                continue;
            }

            if ($customer === null) {
                continue;
            }

            $invoice = Invoice::query()
                ->where('company_id', $company->id)
                ->where('contact_id', $customer->id)
                ->where('invoice_no', $row['invoice_no'])
                ->first();

            if ($invoice === null) {
                $errors[] = __("Row :n: invoice ':no' does not belong to :customer, or does not exist.", [
                    'n' => $lineNum,
                    'no' => $row['invoice_no'],
                    'customer' => $header['customer_display_name'],
                ]);
            }
        }

        return $errors;
    }

    public function summarizeGroup(array $rows, Company $company): array
    {
        $first = $rows[0];
        $total = array_reduce($rows, fn (float $carry, array $row): float => $carry + (float) ($row['application_amount'] ?? 0), 0.0);

        return [
            'Receipt' => filled($first['receipt_no'] ?? null) ? (string) $first['receipt_no'] : __('(auto-numbered)'),
            'Customer' => (string) ($first['customer_display_name'] ?? ''),
            'Date' => (string) ($first['receipt_date'] ?? ''),
            'Invoices applied to' => (string) count($rows),
            'Total' => number_format($total, 2),
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

        $depositToAccountId = Account::query()
            ->where('company_id', $company->id)
            ->where('code', $first['deposit_to_account_code'])
            ->value('id');

        $paymentMethodId = filled($first['payment_method'] ?? null)
            ? PaymentMethod::query()->where('company_id', $company->id)->where('name', $first['payment_method'])->value('id')
            : null;

        $applications = [];
        $totalCents = 0;
        foreach ($rows as $row) {
            $invoice = Invoice::query()
                ->where('company_id', $company->id)
                ->where('contact_id', $customer->id)
                ->where('invoice_no', $row['invoice_no'])
                ->first();

            $amountCents = (int) round(((float) $row['application_amount']) * 100);
            $applications[] = [
                'invoice_id' => $invoice->id,
                'amount_cents' => $amountCents,
            ];
            $totalCents += $amountCents;
        }

        $receipt = app(SaveReceipt::class)->handle([
            'contact_id' => $customer->id,
            'receipt_no' => filled($first['receipt_no'] ?? null) ? $first['receipt_no'] : null,
            'receipt_date' => $first['receipt_date'],
            'deposit_to_account_id' => $depositToAccountId,
            'payment_method_id' => $paymentMethodId,
            'reference' => $first['reference'] ?? null,
            'amount_cents' => $totalCents,
            'memo' => $first['memo'] ?? null,
            'currency_code' => filled($first['currency_code'] ?? null) ? mb_strtoupper($first['currency_code']) : null,
            'applications' => $applications,
        ]);

        if (filled($first['fx_rate'] ?? null)) {
            $receipt->forceFill(['fx_rate' => (string) $first['fx_rate']])->save();
        }

        // A posting failure (e.g. a locked period) still leaves a valid,
        // reviewable draft behind — reported as a distinct message rather
        // than losing the receipt or masking it as a plain save failure.
        try {
            app(ReceiptPoster::class)->post($receipt);
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('Receipt :no was saved as a draft but could not be posted: :error', [
                'no' => $receipt->receipt_no,
                'error' => $e->getMessage(),
            ]), previous: $e);
        }
    }

    public function sampleRows(): array
    {
        return [
            [
                'import_ref' => '1',
                'receipt_no' => 'RCT-6001',
                'customer_display_name' => 'Northwind Retail',
                'receipt_date' => '12-Feb-2026',
                'deposit_to_account_code' => '1000',
                'payment_method' => 'Bank Transfer',
                'reference' => '',
                'memo' => '',
                'invoice_no' => 'INV-2001',
                'application_amount' => '1350.00',
                'currency_code' => '',
                'fx_rate' => '',
            ],
            [
                // receipt_no left blank here on purpose — this receipt is
                // numbered automatically instead, same as one entered by
                // hand.
                'import_ref' => '2',
                'receipt_no' => '',
                'customer_display_name' => 'Harbourview Cafe',
                'receipt_date' => '13-Feb-2026',
                'deposit_to_account_code' => '1000',
                'payment_method' => '',
                'reference' => 'DEP-9931',
                'memo' => '',
                'invoice_no' => 'INV-2002',
                'application_amount' => '200.00',
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
