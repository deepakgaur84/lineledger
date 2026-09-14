<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\MasterData\SaveItem;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Services\BulkImport\ImporterDefinition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Items — the first importer with a reference to resolve: an income
 * account, identified in the CSV by its code (not a database id, which a
 * spreadsheet author has no reason to know). Deliberately scoped down from
 * what the real API/UI support (see SaveItem's own docblock for the full
 * shape): no inventory tracking (adds two more required accounts and an
 * opening-balance stock adjustment — real additional complexity, not this
 * importer's job yet), no default tax code. Every item this creates is a
 * plain, non-tracked service/product item.
 */
class ItemImporter implements ImporterDefinition
{
    public function key(): string
    {
        return 'items';
    }

    public function label(): string
    {
        return 'Items';
    }

    public function csvColumns(): array
    {
        return [
            'name' => 'Required.',
            'sku' => 'Optional.',
            'description' => 'Optional.',
            'income_account_code' => 'Required. The code of an existing income account (see your Chart of Accounts), e.g. 4000 — not the account name.',
            'expense_account_code' => 'Optional. Same as above, for the purchase/expense side. Falls back to the income account if left blank.',
            'default_price' => 'Optional. Plain decimal, e.g. 19.99 — not cents.',
            'is_active' => "Optional. 'true'/'false', defaults to true.",
        ];
    }

    public function validate(array $row, Company $company): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'income_account_code' => [
                'required', 'string',
                Rule::exists('accounts', 'code')->where('company_id', $company->id),
            ],
            'expense_account_code' => [
                'nullable', 'string',
                Rule::exists('accounts', 'code')->where('company_id', $company->id),
            ],
            'default_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'is_active' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
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
        $summary = [
            'Name' => (string) ($row['name'] ?? ''),
            'Income account' => (string) ($row['income_account_code'] ?? ''),
            'Price' => $row['default_price'] !== null && $row['default_price'] !== ''
                ? number_format((float) $row['default_price'], 2)
                : '—',
        ];

        if (($duplicateOf = $this->findLikelyDuplicate($row, $company)) !== null) {
            // Same reasoning as AbstractContactImporter's own duplicate
            // check: a warning, not a validation error — the app enforces
            // no uniqueness on item names, so this only makes an
            // accidental re-import visible, never blocks an intentional one.
            $summary['⚠ Possible duplicate'] = __('Matches existing item #:id (:name)', [
                'id' => $duplicateOf->id,
                'name' => $duplicateOf->name,
            ]);
        }

        return $summary;
    }

    public function commit(array $row, Company $company): void
    {
        $incomeAccountId = $this->resolveAccountId((string) $row['income_account_code'], $company);
        $expenseAccountId = filled($row['expense_account_code'] ?? null)
            ? $this->resolveAccountId((string) $row['expense_account_code'], $company)
            : null;

        app(SaveItem::class)->handle([
            'name' => $row['name'],
            'sku' => $row['sku'] ?: null,
            'description' => $row['description'] ?: null,
            'income_account_id' => $incomeAccountId,
            'expense_account_id' => $expenseAccountId,
            'default_price_cents' => filled($row['default_price'] ?? null)
                ? (int) round(((float) $row['default_price']) * 100)
                : 0,
            'is_active' => $this->normalizedBoolean($row['is_active'] ?? null) ?? true,
            'track_inventory' => false,
        ]);
    }

    private function resolveAccountId(string $code, Company $company): ?int
    {
        return Account::query()
            ->where('company_id', $company->id)
            ->where('code', $code)
            ->value('id');
    }

    private function findLikelyDuplicate(array $row, Company $company): ?Item
    {
        $name = trim((string) ($row['name'] ?? ''));
        $sku = trim((string) ($row['sku'] ?? ''));

        if ($name === '' && $sku === '') {
            return null;
        }

        return Item::query()
            ->where('company_id', $company->id)
            ->where(function ($query) use ($name, $sku): void {
                if ($name !== '') {
                    $query->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
                }
                if ($sku !== '') {
                    $query->orWhere('sku', $sku);
                }
            })
            ->first();
    }

    private function normalizedBoolean(?string $value): ?bool
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return in_array(mb_strtolower(trim($value)), ['true', '1'], true);
    }
}
