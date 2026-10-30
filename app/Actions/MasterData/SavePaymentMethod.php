<?php

namespace App\Actions\MasterData;

use App\Models\PaymentMethod;

/**
 * Creates or updates a payment method. Shared by the Livewire settings page
 * and the API.
 *
 * Expected $data shape:
 *   name:      string
 *   is_cheque: ?bool
 *   is_card:   ?bool  (a card or payment app; the US 1099 Summary leaves its payments out)
 *   is_active: ?bool
 */
final class SavePaymentMethod
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?PaymentMethod $method = null): PaymentMethod
    {
        $attributes = [
            'name' => $data['name'],
        ];

        if (array_key_exists('is_cheque', $data)) {
            $attributes['is_cheque'] = (bool) $data['is_cheque'];
        }

        if (array_key_exists('is_card', $data)) {
            $attributes['is_card'] = (bool) $data['is_card'];
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($method && $method->exists) {
            $method->update($attributes);

            return $method;
        }

        return PaymentMethod::create($attributes + [
            'is_cheque' => $data['is_cheque'] ?? false,
            'is_card' => $data['is_card'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }
}
