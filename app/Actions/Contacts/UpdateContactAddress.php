<?php

namespace App\Actions\Contacts;

use App\Models\Contact;

/**
 * Patches ONLY a contact's billing address, leaving every other column alone.
 *
 * Deliberately not {@see SaveContact}: that rebuilds the whole profile from its
 * payload, so an absent key becomes null and a partial call would wipe the
 * contact's email, phone, notes, shipping address and default accounts. The same
 * hazard is documented on {@see SaveOtherName}.
 *
 * Used when an operator edits the payee address on a cheque and chooses to save
 * it back to the payee's record. Blank input clears the field (the operator
 * emptying a line means "this is no longer part of the address"), and the
 * country is normalised to the upper-case two-letter code the column holds.
 */
final class UpdateContactAddress
{
    /**
     * Payload keys, mapping onto the contact's billing_* columns.
     */
    private const FIELDS = [
        'line1' => 'billing_line1',
        'line2' => 'billing_line2',
        'city' => 'billing_city',
        'region' => 'billing_region',
        'postal_code' => 'billing_postal_code',
        'country' => 'billing_country',
    ];

    /**
     * @param  array<string, ?string>  $address  keys: line1, line2, city, region, postal_code, country
     */
    public function handle(Contact $contact, array $address): Contact
    {
        $attributes = [];

        foreach (self::FIELDS as $key => $column) {
            // An absent key means "leave this field alone"; a present blank one
            // means "clear it".
            if (! array_key_exists($key, $address)) {
                continue;
            }

            $value = trim((string) $address[$key]);

            if ($key === 'country') {
                $value = mb_strtoupper($value);
            }

            $attributes[$column] = $value !== '' ? $value : null;
        }

        if ($attributes !== []) {
            $contact->update($attributes);
        }

        return $contact;
    }
}
