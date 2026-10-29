<?php

namespace App\Services\Banking;

use App\Actions\Banking\CreateBankRuleFromLine;
use App\Actions\Banking\RecordStatementLine;
use App\Actions\Banking\SplitStatementLine;
use App\Enums\AccountSubtype;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementLine;

/**
 * The category a bank statement line is recorded against arrives from the
 * browser — the For Review row, a bulk pick, a split part, the import wizard —
 * so it is checked here rather than trusted. It must be an account of the
 * line's own organization, and not one of the AR / AP / Undeposited Funds
 * control accounts: a bank line recorded straight to one of those would put an
 * amount on the subledger with no invoice, bill or payment behind it (the
 * category picker leaves them out, {@see Account::scopeSelectableForItemAccount()}).
 *
 * Used by {@see RecordStatementLine}, {@see SplitStatementLine},
 * {@see CreateBankRuleFromLine} and the import wizard.
 */
final class StatementLineCategoryGuard
{
    private const CONTROL_SUBTYPES = [
        AccountSubtype::AccountsReceivable,
        AccountSubtype::AccountsPayable,
        AccountSubtype::UndepositedFunds,
    ];

    /**
     * @param  list<int>  $accountIds
     *
     * @throws PostingValidationException
     */
    public function assertUsable(BankStatementLine $line, array $accountIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $accountIds)));

        if ($ids === []) {
            return;
        }

        $found = Account::query()
            ->where('company_id', $line->company_id)
            ->whereNotIn('subtype', array_map(fn (AccountSubtype $subtype): string => $subtype->value, self::CONTROL_SUBTYPES))
            ->whereIn('id', $ids)
            ->count();

        if ($found !== count($ids)) {
            throw new PostingValidationException(__('That category is not available — choose another.'));
        }
    }
}
