<?php

namespace App\Services\Banking;

use App\Actions\Banking\RecordStatementLine;
use App\Actions\Banking\SplitStatementLine;
use App\Actions\Documents\MatchInboxItemToStatementLine;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;

/**
 * A statement line is in its account's currency, and on a foreign-currency
 * account its posting carries that amount as the foreign memo on every leg, as
 * the Expense and Deposit posters do. A category that is itself an account in a
 * third currency can't take that memo: its own amount isn't on the statement, so
 * posting there would leave its foreign balance wrong. Such a line is refused,
 * and the movement is recorded as a transfer, which takes both amounts.
 *
 * Used by {@see RecordStatementLine}, {@see SplitStatementLine} and
 * {@see MatchInboxItemToStatementLine}.
 */
final class StatementLineCurrencyGuard
{
    /**
     * @param  list<int>  $categoryAccountIds
     *
     * @throws PostingValidationException
     */
    public function assertCategoriesFit(Account $bank, array $categoryAccountIds): void
    {
        $company = $bank->company;

        if ($company->isHomeCurrency($bank->currency_code) || $categoryAccountIds === []) {
            return;
        }

        $bankCurrency = mb_strtoupper((string) $bank->currency_code);

        $mismatch = Account::query()
            ->where('company_id', $bank->company_id)
            ->whereIn('id', $categoryAccountIds)
            ->whereNotNull('currency_code')
            ->pluck('currency_code')
            ->contains(fn (?string $code): bool => ! $company->isHomeCurrency($code)
                && mb_strtoupper((string) $code) !== $bankCurrency);

        if ($mismatch) {
            throw new PostingValidationException(__('That category is an account in another currency — record a transfer between the two accounts instead.'));
        }
    }
}
