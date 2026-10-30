<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Company;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJournalEntryRequest extends FormRequest
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
            'entry_no' => ['nullable', 'string', 'max:40'],
            'entry_date' => ['required', 'date'],
            'memo' => ['nullable', 'string'],

            'lines' => ['required', 'array', 'min:2', 'max:1000'],
            'lines.*.account_id' => ['required', 'integer', $inCompany('accounts')],
            'lines.*.debit_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.credit_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.memo' => ['nullable', 'string', 'max:1000'],
            'lines.*.contact_id' => ['nullable', 'integer', $inCompany('contacts')],
            'lines.*.tax_code_id' => ['nullable', 'integer', $inCompany('tax_codes')],

            // A line on a foreign-currency account also records its amount in
            // that currency; SaveJournalEntry works out whichever of home,
            // foreign and rate is missing and checks the rest agree.
            'lines.*.currency_code' => ['nullable', 'string', 'size:3'],
            'lines.*.fx_rate' => ['nullable', 'numeric', 'decimal:0,8', 'gt:0', 'max:9999999999'],
            'lines.*.foreign_debit_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.foreign_credit_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            ControlAccountLineRules::validateContacts(
                $validator,
                (array) $this->input('lines', []),
                ControlAccountLineRules::hasDebitOrCredit(...),
            );
        });
    }
}
