<?php

namespace App\Support\Accounting;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Services\Posting\ControlAccountResolver;

/**
 * Which of a company's accounts demand a counterparty on every line, and which
 * role that counterparty must hold: a customer on Accounts Receivable, a vendor
 * on Accounts Payable.
 *
 * `journal_lines.contact_id` is the AR/AP sub-ledger — the AR/AP aging reports,
 * the contact statements and the cached `ar_balance_cents` / `ap_balance_cents`
 * all read it and nothing else — so a control-account line without the right
 * contact lands in the reports' "unattributed" catch-all. This map is the single
 * definition the forms and the API both gate on.
 *
 * Matched by subtype rather than `is_system`, so per-currency control accounts
 * (see {@see ControlAccountResolver}) are covered too.
 */
final class ControlAccountRoles
{
    public const CUSTOMER = 'customer';

    public const VENDOR = 'vendor';

    /**
     * Account id => required role, for every AR/AP account of a company. Omit
     * $companyId to lean on CompanyScope (the Livewire forms always have a
     * bound company; the API form requests do too).
     *
     * @return array<int, string>
     */
    public static function map(?int $companyId = null): array
    {
        $roles = [];

        // Queried per subtype rather than compared in PHP: Larastan types
        // Account::$subtype as a plain string, so `=== AccountSubtype::X` reads as
        // always-false and has to be baselined away.
        foreach ([self::CUSTOMER => AccountSubtype::AccountsReceivable, self::VENDOR => AccountSubtype::AccountsPayable] as $role => $subtype) {
            $ids = Account::query()
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->where('subtype', $subtype->value)
                ->pluck('id');

            foreach ($ids as $id) {
                $roles[(int) $id] = $role;
            }
        }

        return $roles;
    }

    /**
     * The `contacts` boolean a contact must have set to be valid in this role.
     */
    public static function roleColumn(string $role): string
    {
        return $role === self::CUSTOMER ? 'is_customer' : 'is_vendor';
    }
}
