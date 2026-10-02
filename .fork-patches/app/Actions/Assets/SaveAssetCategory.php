<?php

namespace App\Actions\Assets;

use App\Enums\DepreciationMethod;
use App\Models\AssetCategory;

/**
 * Creates or updates an asset category — a grouping of fixed assets with
 * default GL accounts that pre-fill on new asset records. Shared by the
 * Livewire settings page and the API.
 *
 * Expected $data shape:
 *   name: string  description: ?string
 *   default_asset_account_id: ?int
 *   default_accumulated_depreciation_account_id: ?int
 *   default_depreciation_expense_account_id: ?int
 *   default_useful_life_months: ?int
 *   default_depreciation_method: string|DepreciationMethod|null (default straight_line; absent on
 *       an update → the category's current method is kept)
 *   default_depreciation_rate: ?numeric  annual percent 1–100; declining_balance defaults a blank
 *       value to 20, straight_line's is optional and kept exactly as given (never invented
 *       from nothing), immediate ignores it entirely (absent on an update → current rate kept)
 *   is_active: ?bool
 */
final class SaveAssetCategory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?AssetCategory $category = null): AssetCategory
    {
        // A category only keeps the rate its method uses, and a declining-balance one
        // always has one — the suggested 20% when none was given.
        $method = array_key_exists('default_depreciation_method', $data)
            ? $this->methodFrom($data['default_depreciation_method'])
            : ($category?->defaultDepreciationMethod() ?? DepreciationMethod::StraightLine);

        $rate = array_key_exists('default_depreciation_rate', $data)
            ? $data['default_depreciation_rate']
            : $category?->default_depreciation_rate;

        $attributes = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'default_asset_account_id' => $data['default_asset_account_id'] ?? null,
            'default_accumulated_depreciation_account_id' => $data['default_accumulated_depreciation_account_id'] ?? null,
            'default_depreciation_expense_account_id' => $data['default_depreciation_expense_account_id'] ?? null,
            'default_useful_life_months' => $data['default_useful_life_months'] ?? null,
            'default_depreciation_method' => $method->value,
            // Declining balance defaults a blank rate to the suggested 20% — it always
            // has one. Straight-line's rate is optional and never invented from nothing:
            // given, it is kept exactly as given; blank, it stays null rather than
            // silently acquiring a rate the category was never actually given.
            'default_depreciation_rate' => match (true) {
                $method === DepreciationMethod::DecliningBalance => filled($rate) ? $rate : DepreciationMethod::DEFAULT_RATE,
                $method === DepreciationMethod::StraightLine => filled($rate) ? $rate : null,
                default => null,
            },
        ];

        if (array_key_exists('cca_class', $data)) {
            $attributes['cca_class'] = $data['cca_class'] ?: null;
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($category && $category->exists) {
            $category->update($attributes);

            return $category;
        }

        return AssetCategory::create($attributes + [
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    private function methodFrom(mixed $value): DepreciationMethod
    {
        return $value instanceof DepreciationMethod
            ? $value
            : (DepreciationMethod::tryFrom((string) $value) ?? DepreciationMethod::StraightLine);
    }
}
