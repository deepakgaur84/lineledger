<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Models\Company;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChequeRequest extends FormRequest
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
            'post' => ['sometimes', 'boolean'],
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where('company_id', $company->id)
                    ->where('subtype', AccountSubtype::Bank->value),
            ],
            'cheque_no' => ['required', 'string', 'max:40'],
            'cheque_date' => ['required', 'date'],
            'payee_contact_id' => ['nullable', 'integer', $inCompany('contacts')],
            'payee_name' => ['required_without:payee_contact_id', 'nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string'],

            // Snapshotted onto the cheque; omit it and the payee contact's
            // billing address is used.
            'payee_address' => ['sometimes', 'array'],
            'payee_address.line1' => ['nullable', 'string', 'max:255'],
            'payee_address.line2' => ['nullable', 'string', 'max:255'],
            'payee_address.city' => ['nullable', 'string', 'max:255'],
            'payee_address.region' => ['nullable', 'string', 'max:255'],
            'payee_address.postal_code' => ['nullable', 'string', 'max:255'],
            'payee_address.country' => ['nullable', 'string', 'size:2'],

            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.account_id' => ['required', 'integer', $inCompany('accounts')],
            'lines.*.contact_id' => ['nullable', 'integer', $inCompany('contacts')],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.amount_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.tax_code_id' => ['nullable', 'integer', $inCompany('tax_codes')],
            'lines.*.secondary_tax_code_id' => ['nullable', 'integer', $inCompany('tax_codes')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // A draft writes nothing to the ledger, so the Accounts Receivable /
        // Payable contact is only demanded at the post — matching the form.
        if ($this->boolean('post', true) === false) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            ControlAccountLineRules::validateContacts(
                $validator,
                (array) $this->input('lines', []),
                ControlAccountLineRules::hasAmount(...),
            );
        });
    }
}
