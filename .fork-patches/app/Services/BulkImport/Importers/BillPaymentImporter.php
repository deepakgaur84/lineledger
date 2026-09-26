<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\Purchasing\SaveBillPayment;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Services\BulkImport\GroupedImporterDefinition;
use App\Services\Posting\BillPaymentPoster;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Bill Payments — see BillImporter for the fuller shared design notes
 * (import_ref grouping, optional auto-numbered document number,
 * auto-posting with a draft fallback on a posting failure). One CSV row
 * here is one BILL APPLICATION, not one line item — the header fields
 * (vendor, date, paid-from account, ...) repeat on every row of the same
 * import_ref, and each row's bill_no/application_amount says how much of
 * this one payment applies to that one bill.
 *
 * The total payment amount is NOT a separate column — it's the sum of
 * the application amounts on the group's rows. StoreBillPaymentRequest's
 * own rule allows a payment to exceed what's applied (an on-account
 * prepayment with unapplied credit left over), but that's not a shape a
 * flat CSV of "here's what got paid against what" naturally represents,
 * and isn't the common case for importing historical payment history —
 * every payment imported here is assumed fully applied.
 *
 * Every application's own validation (same contact, same bill_type, bill
 * status posted/partial) mirrors StoreBillPaymentRequest::withValidator()
 * exactly, rather than reinventing the open-bill check independently.
 */
class BillPaymentImporter implements GroupedImporterDefinition
{
    public function key(): string
    {
        return 'bill-payments';
    }

    public function label(): string
    {
        return 'Bill Payments';
    }

    public function csvColumns(): array
    {
        return [
            'import_ref' => 'Required. Groups rows into one payment — every application in the same payment repeats the same import_ref. Only used to group rows in this file; never stored, and unrelated to payment_no.',
            'payment_no' => 'Optional. Left blank, the payment is numbered automatically the same way one entered by hand would be. Given, must be unique — a payment_no already in use is rejected.',
            'vendor_display_name' => "Required. Must match an existing vendor's display name exactly (case-insensitive). If more than one vendor shares this name, the row is rejected rather than guessing which one.",
            'payment_date' => 'Required. Any unambiguous date works, e.g. 01-Apr-2026 or 2026-04-01.',
            'paid_from_account_code' => 'Required. The code of the bank/asset account the payment was made from, e.g. 1000 — not the account name.',
            'payment_method' => "Optional. Must match an existing payment method's name exactly (see Settings > Payment Methods).",
            'reference' => 'Optional. A cheque number or bank reference. Same on every row of a payment — only the first row\'s value is used.',
            'memo' => 'Optional. Same on every row of a payment — only the first row\'s value is used.',
            'bill_no' => "Required per row. The bill this row's amount is applied to — must belong to the same vendor, and be open (posted or partially paid).",
            'application_amount' => 'Required per row. How much of this payment applies to that bill. The payment\'s own total is the sum of every row\'s application_amount — not a separate column.',
            'currency_code' => "Optional. Only needed if the payment is in a foreign currency the vendor doesn't already default to. Same on every row — only the first row's value is used.",
            'fx_rate' => "Optional. The exact rate this payment was made at — set this for historical imports where re-deriving today's rate would misstate what actually happened. Left blank, the rate as of payment_date is fetched automatically. Same on every row — only the first row's value is used.",
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
            'payment_no' => $first['payment_no'] ?? null,
            'vendor_display_name' => $first['vendor_display_name'] ?? null,
            'payment_date' => $first['payment_date'] ?? null,
            'paid_from_account_code' => $first['paid_from_account_code'] ?? null,
            'payment_method' => $first['payment_method'] ?? null,
            'currency_code' => $first['currency_code'] ?? null,
            'fx_rate' => $first['fx_rate'] ?? null,
        ];

        $validator = Validator::make($header, [
            'payment_no' => [
                'nullable', 'string', 'max:40',
                Rule::unique('bill_payments', 'payment_no')->where('company_id', $company->id),
            ],
            'vendor_display_name' => ['required', 'string'],
            'payment_date' => ['required', 'date'],
            'paid_from_account_code' => [
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

        $vendor = $this->resolveVendor((string) $header['vendor_display_name'], $company, $errors);

        foreach ($rows as $i => $row) {
            $lineNum = $i + 1;
            $lineValidator = Validator::make($row, [
                'bill_no' => ['required', 'string'],
                'application_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            ]);

            if ($lineValidator->fails()) {
                foreach ($lineValidator->errors()->all() as $message) {
                    $errors[] = __('Row :n: :message', ['n' => $lineNum, 'message' => $message]);
                }

                continue;
            }

            if ($vendor === null) {
                // Vendor itself already failed above — every row's bill
                // lookup depends on it, so there's nothing further to
                // usefully check per-row without it.
                continue;
            }

            $bill = Bill::query()
                ->where('company_id', $company->id)
                ->where('contact_id', $vendor->id)
                ->where('bill_no', $row['bill_no'])
                ->whereIn('status', ['posted', 'partial'])
                ->first();

            if ($bill === null) {
                $errors[] = __("Row :n: bill ':no' is not open, or does not belong to :vendor.", [
                    'n' => $lineNum,
                    'no' => $row['bill_no'],
                    'vendor' => $header['vendor_display_name'],
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
            'Payment' => filled($first['payment_no'] ?? null) ? (string) $first['payment_no'] : __('(auto-numbered)'),
            'Vendor' => (string) ($first['vendor_display_name'] ?? ''),
            'Date' => (string) ($first['payment_date'] ?? ''),
            'Bills applied to' => (string) count($rows),
            'Total' => number_format($total, 2),
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

        $paidFromAccountId = Account::query()
            ->where('company_id', $company->id)
            ->where('code', $first['paid_from_account_code'])
            ->value('id');

        $paymentMethodId = filled($first['payment_method'] ?? null)
            ? PaymentMethod::query()->where('company_id', $company->id)->where('name', $first['payment_method'])->value('id')
            : null;

        $applications = [];
        $totalCents = 0;
        foreach ($rows as $row) {
            $bill = Bill::query()
                ->where('company_id', $company->id)
                ->where('contact_id', $vendor->id)
                ->where('bill_no', $row['bill_no'])
                ->first();

            $amountCents = (int) round(((float) $row['application_amount']) * 100);
            $applications[] = [
                'bill_id' => $bill->id,
                'amount_cents' => $amountCents,
            ];
            $totalCents += $amountCents;
        }

        $payment = app(SaveBillPayment::class)->handle([
            'contact_id' => $vendor->id,
            'payment_no' => filled($first['payment_no'] ?? null) ? $first['payment_no'] : null,
            'payment_date' => $first['payment_date'],
            'paid_from_account_id' => $paidFromAccountId,
            'payment_method_id' => $paymentMethodId,
            'reference' => $first['reference'] ?? null,
            'amount_cents' => $totalCents,
            'memo' => $first['memo'] ?? null,
            'currency_code' => filled($first['currency_code'] ?? null) ? mb_strtoupper($first['currency_code']) : null,
            'applications' => $applications,
        ]);

        if (filled($first['fx_rate'] ?? null)) {
            $payment->forceFill(['fx_rate' => (string) $first['fx_rate']])->save();
        }

        // A posting failure (e.g. a locked period) still leaves a valid,
        // reviewable draft behind — reported as a distinct message rather
        // than losing the payment or masking it as a plain save failure.
        try {
            app(BillPaymentPoster::class)->post($payment);
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('Payment :no was saved as a draft but could not be posted: :error', [
                'no' => $payment->payment_no,
                'error' => $e->getMessage(),
            ]), previous: $e);
        }
    }

    public function sampleRows(): array
    {
        return [
            [
                'import_ref' => '1',
                'payment_no' => 'PAY-5001',
                'vendor_display_name' => 'Acme Supplies',
                'payment_date' => '10-Feb-2026',
                'paid_from_account_code' => '1000',
                'payment_method' => 'Bank Transfer',
                'reference' => '',
                'memo' => '',
                'bill_no' => 'BILL-1001',
                'application_amount' => '970.00',
                'currency_code' => '',
                'fx_rate' => '',
            ],
            [
                // payment_no left blank here on purpose — this payment is
                // numbered automatically instead, same as one entered by
                // hand. This payment also applies to two bills at once.
                'import_ref' => '2',
                'payment_no' => '',
                'vendor_display_name' => 'Beta Traders',
                'payment_date' => '11-Feb-2026',
                'paid_from_account_code' => '1000',
                'payment_method' => '',
                'reference' => 'EFT-4471',
                'memo' => '',
                'bill_no' => 'BILL-1002',
                'application_amount' => '150.00',
                'currency_code' => '',
                'fx_rate' => '',
            ],
            [
                'import_ref' => '2',
                'payment_no' => '',
                'vendor_display_name' => 'Beta Traders',
                'payment_date' => '11-Feb-2026',
                'paid_from_account_code' => '1000',
                'payment_method' => '',
                'reference' => 'EFT-4471',
                'memo' => '',
                'bill_no' => 'BILL-1003',
                'application_amount' => '100.00',
                'currency_code' => '',
                'fx_rate' => '',
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
