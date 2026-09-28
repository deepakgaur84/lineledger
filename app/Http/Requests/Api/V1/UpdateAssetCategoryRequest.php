<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\DepreciationMethod;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app()->bound('current_api_key');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = app('current_company');
        assert($company instanceof Company);

        $inCompany = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $company->id);

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('asset_categories', 'name')
                    ->where('company_id', $company->id)
                    ->ignore($this->route('assetCategory'))
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string'],
            'default_asset_account_id' => ['nullable', 'integer', $inCompany('accounts')->where('subtype', AccountSubtype::FixedAsset->value)],
            'default_accumulated_depreciation_account_id' => ['nullable', 'integer', $inCompany('accounts')->where('subtype', AccountSubtype::FixedAsset->value)],
            'default_depreciation_expense_account_id' => ['nullable', 'integer', $inCompany('accounts')->where('type', AccountType::Expense->value)],
            'default_useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'default_depreciation_method' => ['sometimes', Rule::enum(DepreciationMethod::class)],
            // Annual percent, 1–100 with up to 3 decimals; only kept for declining_balance, where
            // leaving it blank means the suggested 20%.
            'default_depreciation_rate' => ['nullable', 'numeric', 'between:'.DepreciationMethod::MIN_RATE.','.DepreciationMethod::MAX_RATE, 'decimal:0,3'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
