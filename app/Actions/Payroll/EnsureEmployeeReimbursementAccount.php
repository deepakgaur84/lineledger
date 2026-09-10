<?php

namespace App\Actions\Payroll;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;

/**
 * Ensures a company has the system Employee Reimbursements Payable account that
 * reimbursement-type bills post through (see
 * Account::scopeEmployeeReimbursementsPayable). The setup wizard seeds this only
 * when the employees feature is selected (ChartTemplateBuilder::FEATURE_GATED_CORE);
 * this backfills it when employees is enabled later on a company that lacks it —
 * mirrors {@see \App\Actions\Inventory\EnsureInventoryAccounts}. Idempotent:
 * matches by name + subtype before creating (the same identity
 * scopeEmployeeReimbursementsPayable looks up by), so calling this on a company
 * that already has the account is a harmless no-op. Returns the number of
 * accounts created (0 or 1).
 */
final class EnsureEmployeeReimbursementAccount
{
    private const CODE = '2300';

    private const NAME = 'Employee Reimbursements Payable';

    private const SUBTYPE = AccountSubtype::CurrentLiability;

    public function handle(Company $company): int
    {
        $exists = Account::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->employeeReimbursementsPayable()
            ->exists();

        if ($exists) {
            return 0;
        }

        // A non-system account with this exact name may already exist (e.g. a
        // manually-created stand-in, same situation this action exists to
        // prevent going forward) — promote it in place rather than creating a
        // duplicate with a clashing code.
        $existingByName = Account::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('name', self::NAME)
            ->first();

        if ($existingByName !== null) {
            $existingByName->forceFill([
                'subtype' => self::SUBTYPE,
                'is_system' => true,
            ])->save();

            return 0;
        }

        Account::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => self::CODE,
            'name' => self::NAME,
            'type' => self::SUBTYPE->type(),
            'subtype' => self::SUBTYPE,
            'normal_balance' => self::SUBTYPE->type()->normalBalance(),
            'is_system' => true,
            'is_active' => true,
        ]);

        return 1;
    }
}
