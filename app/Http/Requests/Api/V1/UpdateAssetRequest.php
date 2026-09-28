<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Enums\AssetStatus;
use App\Enums\DepreciationMethod;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'asset_category_id' => ['nullable', 'integer', $inCompany('asset_categories')],
            'asset_account_id' => [
                'required',
                'integer',
                $inCompany('accounts')->where('subtype', AccountSubtype::FixedAsset->value),
            ],
            'accumulated_depreciation_account_id' => ['nullable', 'integer', $inCompany('accounts')],
            'depreciation_expense_account_id' => ['nullable', 'integer', $inCompany('accounts')],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'acquired_date' => ['required', 'date'],
            'in_service_date' => ['nullable', 'date'],
            'cost_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'salvage_value_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            // Annual percent for declining_balance, between the enum's MIN_RATE and
            // MAX_RATE (1–100) with up to 3 decimals; nothing below 1 is accepted.
            // Ignored, and not stored, for the other methods. Required whenever that
            // method is chosen, since without it there is nothing to calculate.
            'depreciation_method' => ['sometimes', Rule::enum(DepreciationMethod::class)],
            'depreciation_rate' => [
                'nullable',
                'numeric',
                'between:'.DepreciationMethod::MIN_RATE.','.DepreciationMethod::MAX_RATE,
                'decimal:0,3',
                Rule::requiredIf(fn () => $this->input('depreciation_method') === DepreciationMethod::DecliningBalance->value),
            ],
            // declining_balance with no useful_life_months ends once the balance left
            // would be within this amount; null (or omitted) means 5% of cost.
            'materiality_limit_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ];
    }
}
