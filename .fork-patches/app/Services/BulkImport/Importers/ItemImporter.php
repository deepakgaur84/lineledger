<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\MasterData\SaveItem;
use App\Actions\MasterData\SaveItemCategory;
use App\Enums\ItemType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\TaxCode;
use App\Services\BulkImport\ImporterDefinition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Items — column set, order and category auto-create behaviour deliberately
 * match Settings > Items' own "Import" button (App\Services\Migration\
 * Importers\ItemsImporter), so the two feel like one consistent feature
 * rather than two different importers that happen to both exist. The one
 * deliberate difference: this importer commits through the real SaveItem
 * action (same validation and business rules as the UI), not a raw
 * Item::create() — see the class docblock on AbstractContactImporter for
 * why that matters. Bundle items are still excluded — see below.
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
            'sku' => 'Optional.',
            'name' => 'Required.',
            'description' => 'Optional.',
            'type' => "Optional. One of: service, non_inventory, other_charge, inventory. Left blank, follows is_inventory. (Bundle items aren't supported by this importer — they reference other items as components, which a flat CSV row can't represent.)",
            'item_category' => 'Optional. A new category is created automatically if it doesn\'t exist yet.',
            'is_inventory' => "Optional, 'yes'/'no'. Sets the default type when type is left blank.",
            'income_account_code' => 'Required. The code of an existing income account, e.g. 4000 — not the account name.',
            'expense_account_code' => 'Optional. Same as above, for the purchase/expense side. Falls back to the income account if left blank.',
            'inventory_asset_account_code' => 'Required if inventory-tracked (see type / is_inventory above).',
            'cogs_account_code' => 'Required if inventory-tracked (see type / is_inventory above).',
            'default_price' => 'Optional. Plain decimal, e.g. 19.99 — not cents.',
            'default_tax_code' => 'Optional. Must match an existing tax code by its code (see Settings > Tax Codes).',
            'reorder_point' => 'Optional. Only meaningful if inventory-tracked.',
        ];
    }

    public function validate(array $row, Company $company): array
    {
        $isInventory = $this->tracksInventory($row);

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string', Rule::in(self::ALLOWED_TYPES)],
            'is_inventory' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '' && ! in_array(mb_strtolower(trim((string) $value)), ['yes', 'no', 'true', 'false', '1', '0'], true)) {
                        $fail(__('The :attribute must be yes or no.', ['attribute' => $attribute]));
                    }
                },
            ],
            // item_category is deliberately NOT validated against existing
            // categories — an unrecognised name is valid input here, same as
            // Settings > Items' own importer: it gets created automatically
            // on commit rather than rejected at preview time.
            'item_category' => ['nullable', 'string', 'max:255'],
            'income_account_code' => [
                'required', 'string',
                Rule::exists('accounts', 'code')->where('company_id', $company->id),
            ],
            'expense_account_code' => [
                'nullable', 'string',
                Rule::exists('accounts', 'code')->where('company_id', $company->id),
            ],
            'inventory_asset_account_code' => [
                $isInventory ? 'required' : 'nullable', 'string',
                Rule::exists('accounts', 'code')->where('company_id', $company->id),
            ],
            'cogs_account_code' => [
                $isInventory ? 'required' : 'nullable', 'string',
                Rule::exists('accounts', 'code')->where('company_id', $company->id),
            ],
            'default_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'default_tax_code' => [
                'nullable', 'string',
                Rule::exists('tax_codes', 'code')->where('company_id', $company->id),
            ],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
        ];

        $validator = Validator::make($row, $rules);

        return $validator->fails() ? $validator->errors()->all() : [];
    }

    public function summarize(array $row, Company $company): array
    {
        $summary = [
            'Name' => (string) ($row['name'] ?? ''),
            'Type' => $row['type'] ?: ($this->tracksInventory($row) ? 'inventory' : 'service'),
            'Income account' => (string) ($row['income_account_code'] ?? ''),
            'Price' => $row['default_price'] !== null && $row['default_price'] !== ''
                ? number_format((float) $row['default_price'], 2)
                : '—',
        ];

        if (filled($row['item_category'] ?? null) && ! $this->categoryExists((string) $row['item_category'], $company)) {
            $summary['Category'] = __(':name (new)', ['name' => $row['item_category']]);
        }

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
        $isInventory = $this->tracksInventory($row);
        $type = $row['type']
            ? ItemType::from(mb_strtolower(trim($row['type'])))
            : ($isInventory ? ItemType::Inventory : ItemType::Service);

        app(SaveItem::class)->handle([
            'name' => $row['name'],
            'sku' => $row['sku'] ?: null,
            'description' => $row['description'] ?: null,
            'type' => $type,
            'item_category_id' => filled($row['item_category'] ?? null)
                ? $this->resolveOrCreateCategory((string) $row['item_category'], $company)
                : null,
            'income_account_id' => $this->resolveAccountId((string) $row['income_account_code'], $company),
            'expense_account_id' => filled($row['expense_account_code'] ?? null)
                ? $this->resolveAccountId((string) $row['expense_account_code'], $company)
                : null,
            'default_price_cents' => filled($row['default_price'] ?? null)
                ? (int) round(((float) $row['default_price']) * 100)
                : 0,
            'is_active' => true,
            'inventory_asset_account_id' => $isInventory && filled($row['inventory_asset_account_code'] ?? null)
                ? $this->resolveAccountId((string) $row['inventory_asset_account_code'], $company)
                : null,
            'cogs_account_id' => $isInventory && filled($row['cogs_account_code'] ?? null)
                ? $this->resolveAccountId((string) $row['cogs_account_code'], $company)
                : null,
            'default_tax_code_id' => filled($row['default_tax_code'] ?? null)
                ? TaxCode::query()->where('company_id', $company->id)->where('code', $row['default_tax_code'])->value('id')
                : null,
            'reorder_point' => $isInventory ? ($row['reorder_point'] ?: null) : null,
            'opening_qty' => 0,
            'opening_cost_cents' => 0,
        ]);
    }

    /** is_inventory sets the default; an explicit type overrides it — matches ItemsImporter (Migration) exactly. */
    private function tracksInventory(array $row): bool
    {
        $isInventoryFlag = in_array(mb_strtolower(trim((string) ($row['is_inventory'] ?? ''))), ['yes', 'true', '1'], true);
        $type = $row['type'] ? mb_strtolower(trim($row['type'])) : null;

        return $isInventoryFlag || $type === 'inventory';
    }

    private function categoryExists(string $name, Company $company): bool
    {
        return ItemCategory::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->exists();
    }

    private function resolveOrCreateCategory(string $name, Company $company): int
    {
        $existing = ItemCategory::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->value('id');

        if ($existing !== null) {
            return $existing;
        }

        return app(SaveItemCategory::class)->handle([
            'name' => $name,
            'parent_id' => null,
            'is_active' => true,
        ])->id;
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
}
