<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\Contacts\SaveContact;
use App\Models\Company;
use App\Models\Contact;
use App\Services\BulkImport\ImporterDefinition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Shared logic for Vendors and Customers — same fields, same validation,
 * differing only in which Contact role gets set. Calls SaveContact::handle()
 * directly (the same action StoreContactRequest's controller calls), so a
 * bulk-imported vendor/customer gets identical validation and currency
 * handling to one created through the UI or API.
 */
abstract class AbstractContactImporter implements ImporterDefinition
{
    /** @return 'is_vendor'|'is_customer' */
    abstract protected function role(): string;

    public function csvColumns(): array
    {
        return [
            'display_name' => 'Required. The name shown throughout the app.',
            'company_name' => 'Optional.',
            'email' => 'Optional.',
            'phone' => 'Optional.',
            'account_no' => 'Optional. Your own reference number for this contact.',
            'tax_number' => 'Optional.',
            'currency_code' => 'Optional. 3-letter code (e.g. USD). Must already be enabled under Settings > Currencies. Leave blank for home currency — this cannot be changed later once the contact has any transaction posted.',
            'is_active' => "Optional. 'true'/'false', defaults to true.",
        ];
    }

    public function validate(array $row, Company $company): array
    {
        $rules = [
            'display_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'account_no' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', Rule::in($this->enabledForeignCurrencyCodes($company))],
            'is_active' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // Case-insensitive on purpose: Excel silently capitalizes
                    // 'false' to 'FALSE' on some locale/autocorrect settings,
                    // and a strict in:true,false,1,0 rule was rejecting an
                    // otherwise-valid row over that alone.
                    if ($value !== null && $value !== '' && ! in_array(mb_strtolower(trim((string) $value)), ['true', 'false', '1', '0'], true)) {
                        $fail(__('The :attribute must be true, false, 1, or 0.', ['attribute' => $attribute]));
                    }
                },
            ],
        ];

        $validator = Validator::make($row, $rules);

        return $validator->fails() ? $validator->errors()->all() : [];
    }

    public function summarize(array $row, Company $company): array
    {
        $currency = $this->normalizedCurrency($row);

        $summary = [
            'Name' => (string) ($row['display_name'] ?? ''),
            'Email' => (string) ($row['email'] ?? '—'),
            'Currency' => $currency ?? 'Home',
        ];

        if (($duplicateOf = $this->findLikelyDuplicate($row, $company)) !== null) {
            // A warning, not a validation error: the app itself enforces no
            // uniqueness on contact names or account numbers, so a genuine
            // intentional duplicate is allowed — this just makes an
            // accidental one (e.g. re-uploading the same file) visible
            // before committing, instead of silently creating a second copy.
            $summary['⚠ Possible duplicate'] = __('Matches existing contact #:id (:name)', [
                'id' => $duplicateOf->id,
                'name' => $duplicateOf->display_name,
            ]);
        }

        return $summary;
    }

    private function findLikelyDuplicate(array $row, Company $company): ?Contact
    {
        $name = trim((string) ($row['display_name'] ?? ''));
        $accountNo = trim((string) ($row['account_no'] ?? ''));

        if ($name === '' && $accountNo === '') {
            return null;
        }

        return Contact::query()
            ->where('company_id', $company->id)
            ->where($this->role(), true)
            ->where(function ($query) use ($name, $accountNo): void {
                if ($name !== '') {
                    $query->orWhereRaw('LOWER(display_name) = ?', [mb_strtolower($name)]);
                }
                if ($accountNo !== '') {
                    $query->orWhere('account_no', $accountNo);
                }
            })
            ->first();
    }

    public function commit(array $row, Company $company): void
    {
        app(SaveContact::class)->handle([
            'display_name' => $row['display_name'],
            'company_name' => $row['company_name'] ?: null,
            'email' => $row['email'] ?: null,
            'phone' => $row['phone'] ?: null,
            'account_no' => $row['account_no'] ?: null,
            'tax_number' => $row['tax_number'] ?: null,
            'currency_code' => $this->normalizedCurrency($row),
            'is_active' => $this->normalizedBoolean($row['is_active'] ?? null) ?? true,
        ], $this->role());
    }

    private function normalizedCurrency(array $row): ?string
    {
        $value = trim((string) ($row['currency_code'] ?? ''));

        return $value === '' ? null : mb_strtoupper($value);
    }

    private function normalizedBoolean(?string $value): ?bool
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return in_array(mb_strtolower(trim($value)), ['true', '1'], true);
    }

    /** @return array<int, string> */
    private function enabledForeignCurrencyCodes(Company $company): array
    {
        return $company->currencies()
            ->where('is_home', false)
            ->where('is_active', true)
            ->pluck('currency_code')
            ->map(fn ($c) => mb_strtoupper((string) $c))
            ->all();
    }
}
