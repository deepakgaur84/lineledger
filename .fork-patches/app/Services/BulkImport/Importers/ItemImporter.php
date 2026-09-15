<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\MasterData\SaveItem;
use App\Enums\ItemType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\BulkImport\ImporterDefinition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Items — matches the field set on the Settings > Items page (SaveItem),
 * with one deliberate exclusion: Bundle items, which reference OTHER items
 * as components. A flat CSV row has no clean way to represent "this item is
 * made of these N other items and quantities" — same reasoning as Bills
 * being excluded until there's a convention for multi-row nested structures
 * (see the README). Every other type — Service, Non-inventory, Other
 * charge, Inventory (with full opening-balance support) — is supported.
 */
class ItemImporter implements ImporterDefinition
{
    /** Types this importer accepts — Bundle is deliberately excluded, see class docblock. */
    private const ALLOWED_TYPES = ['service', 'non_inventory', 'other_charge', 'inventory'];

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
            'type' => "Optional, defaults to 'service'. One of: service, non_inventory, other_charge, inventory. (Bundle items aren't supported by this importer — they reference other items as components, which a flat CSV row can't represent.)",
            'item_category_name' => 'Optional. Must match an existing category exactly (see Settings > Item Categories) — not created automatically.',
            'income_account_code' => 'Required. The code of an existing income account, e.g. 4000 — not the account name.',
            'expense_account_code' => 'Optional. Same as above, for the purchase/expense side. Falls back to the income account if left blank.',
            'default_price' => 'Optional. Plain decimal, e.g. 19.99 — not cents.',
            'is_active' => "Optional. 'true'/'false', defaults to true.",
            'inventory_asset_account_code' => "Required if type is 'inventory'.",
            'cogs_account_code' => "Required if type is 'inventory'.",
            'reorder_point' => "Optional. Only meaningful if type is 'inventory'.",
            'opening_qty' => "Optional. Only meaningful if type is 'inventory' — posts a one-time opening-balance stock adjustment, same as the Settings page does.",
            'opening_cost' => "Optional. Plain decimal per unit — pairs with opening_qty.",
        ];
    }

    public function validate(array $row, Company $company): array
    {
        $isInventory = mb_strtolower(trim((string) ($row['type'] ?? ''))) === 'inventory';

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string', Rule::in(self::ALLOWED_TYPES)],
            'item_category_name' => [
                'nullable', 'string',
                Rule::exists('item_categories', 'name')->where('company_id', $company->id),
            ],
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
            'inventory_asset_account_code' => [
                $isInventory ? 'required' : 'nullable', 'string',
                Rule::exists('accounts', 'code')->where('company_id', $company->id),
            ],
            'cogs_account_code' => [
                $isInventory ? 'required' : 'nullable', 'string',
                Rule::exists('accounts', 'code')->where('company_id', $company->id),
            ],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'opening_qty' => ['nullable', 'numeric'],
            'opening_cost' => ['nullable', 'numeric', 'min:0'],
        ];

        $validator = Validator::make($row, $rules);

        return $validator->fails() ? $validator->errors()->all() : [];
    }

    public function summarize(array $row, Company $company): array
    {
        $summary = [
            'Name' => (string) ($row['name'] ?? ''),
            'Type' => $row['type'] ?: 'service',
            'Income account' => (string) ($row['income_account_code'] ?? ''),
            'Price' => $row['default_price'] !== null && $row['default_price'] !== ''
                ? number_format((float) $row['default_price'], 2)
                : '—',
        ];

        if (($duplicateOf = $this->findLikelyDuplicate($row, $company)) !== null) {
            $summary['⚠ Possible duplicate'] = __('Matches existing item #:id (:name)', [
                'id' => $duplicateOf->id,
                'name' => $duplicateOf->name,
            ]);
        }

        return $summary;
    }

    public function commit(array $row, Company $company): void
    {
        $type = mb_strtolower(trim((string) ($row['type'] ?? ''))) ?: 'service';
        $isInventory = $type === 'inventory';

        $categoryId = filled($row['item_category_name'] ?? null)
            ? ItemCategory::query()->where('company_id', $company->id)->where('name', $row['item_category_name'])->value('id')
            : null;

        app(SaveItem::class)->handle([
            'name' => $row['name'],
            'sku' => $row['sku'] ?: null,
            'description' => $row['description'] ?: null,
            'type' => ItemType::from($type),
            'item_category_id' => $categoryId,
            'income_account_id' => $this->resolveAccountId((string) $row['income_account_code'], $company),
            'expense_account_id' => filled($row['expense_account_code'] ?? null)
                ? $this->resolveAccountId((string) $row['expense_account_code'], $company)
                : null,
            'default_price_cents' => filled($row['default_price'] ?? null)
                ? (int) round(((float) $row['default_price']) * 100)
                : 0,
            'is_active' => $this->normalizedBoolean($row['is_active'] ?? null) ?? true,
            'inventory_asset_account_id' => $isInventory && filled($row['inventory_asset_account_code'] ?? null)
                ? $this->resolveAccountId((string) $row['inventory_asset_account_code'], $company)
                : null,
            'cogs_account_id' => $isInventory && filled($row['cogs_account_code'] ?? null)
                ? $this->resolveAccountId((string) $row['cogs_account_code'], $company)
                : null,
            'reorder_point' => $isInventory ? ($row['reorder_point'] ?: null) : null,
            'opening_qty' => $isInventory ? (float) ($row['opening_qty'] ?? 0) : 0,
            'opening_cost_cents' => $isInventory && filled($row['opening_cost'] ?? null)
                ? (int) round(((float) $row['opening_cost']) * 100)
                : 0,
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
