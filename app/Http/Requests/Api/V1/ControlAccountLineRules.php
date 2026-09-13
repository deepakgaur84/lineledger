<?php

namespace App\Http\Requests\Api\V1;

use App\Livewire\Concerns\ManagesLineContacts;
use App\Models\Contact;
use App\Support\Accounting\ControlAccountRoles;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared cross-field validation for document lines: Accounts Receivable lines
 * require a customer contact and Accounts Payable lines require a vendor
 * contact. Mirrors the Livewire forms' validateLineContacts()
 * ({@see ManagesLineContacts}).
 *
 * Each caller supplies $hasAmount because the payloads differ — journal entries
 * carry debit_cents/credit_cents, cheques a single amount_cents. Lines with no
 * amount are ignored (the Actions drop them).
 */
final class ControlAccountLineRules
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  callable(array<string, mixed>): bool  $hasAmount
     */
    public static function validateContacts(Validator $validator, array $lines, callable $hasAmount): void
    {
        $roles = ControlAccountRoles::map();

        foreach ($lines as $i => $line) {
            if (! $hasAmount($line)) {
                continue;
            }

            $role = $roles[(int) ($line['account_id'] ?? 0)] ?? null;

            if ($role === null) {
                continue;
            }

            $contactId = $line['contact_id'] ?? null;

            $valid = $contactId !== null && Contact::query()
                ->whereKey($contactId)
                ->where(ControlAccountRoles::roleColumn($role), true)
                ->exists();

            if (! $valid) {
                $validator->errors()->add(
                    "lines.{$i}.contact_id",
                    $role === ControlAccountRoles::CUSTOMER
                        ? 'Select a valid customer for the Accounts Receivable line.'
                        : 'Select a valid vendor for the Accounts Payable line.',
                );
            }
        }
    }

    /**
     * Journal-entry payload: a line carries money when either side is non-zero.
     *
     * @param  array<string, mixed>  $line
     */
    public static function hasDebitOrCredit(array $line): bool
    {
        return (int) ($line['debit_cents'] ?? 0) !== 0 || (int) ($line['credit_cents'] ?? 0) !== 0;
    }

    /**
     * Single-amount payload (cheques, expenses).
     *
     * @param  array<string, mixed>  $line
     */
    public static function hasAmount(array $line): bool
    {
        return (int) ($line['amount_cents'] ?? 0) !== 0;
    }
}
