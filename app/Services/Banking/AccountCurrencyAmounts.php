<?php

namespace App\Services\Banking;

use App\Actions\Banking\AddStatementLineEntry;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\CurrencyRevaluation;
use App\Models\JournalLine;
use App\Services\Banking\Import\StatementMatcher;
use App\Services\Reconciliation\BankReconciliationService;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * The amounts of a bank or credit-card account's journal lines in the
 * account's OWN currency: the currency its statements are in, and so the one
 * its reconciliation ({@see BankReconciliationService}), statement matching
 * ({@see StatementMatcher}) and register work in.
 *
 * On a home-currency account that is the home cents, debit − credit, exactly
 * as before.
 *
 * On a foreign-currency account the home cents carry the exchange rate, so a
 * line's amount is its foreign memo, foreign_debit_cents − foreign_credit_cents,
 * signed as the posters write it (a debit is money in). A line without a memo
 * in the account's currency:
 *
 *  - counts at its home cents when those were the statement's own figure. Until
 *    they converted, the statement feed ({@see AddStatementLineEntry}, source
 *    BankStatementImport) and a reconciliation's service charge or interest
 *    (source BankReconciliation) booked the statement amount as home cents
 *    with no memo. The reversal of such an entry counts the same way.
 *  - counts as zero otherwise, as it does in the account's foreign balance and
 *    in the period-end revaluation. A Home Currency Adjustment line (or the
 *    reversal of one) is zero by design, because it moves only the home
 *    value. Any other such line is flagged as missing its amount (for example
 *    a journal entry or payment recorded before its foreign amount was kept),
 *    so the screens can say why it moves nothing.
 *
 * The rules are SQL expressions, so a running balance or a cleared balance is
 * one query. Lines loaded through {@see withAmounts()} carry the result as
 * `account_amount_cents` and `account_amount_missing`.
 */
final class AccountCurrencyAmounts
{
    /**
     * Entry sources that booked a statement's figure as home cents, with no
     * foreign memo, on a foreign account before they converted it.
     */
    public const STATEMENT_FIGURE_SOURCES = [BankStatementImport::class, BankReconciliation::class];

    private function __construct(
        public readonly string $currencyCode,
        public readonly bool $foreign,
    ) {}

    public static function for(Account $account): self
    {
        $company = $account->company;
        $foreign = ! $company->isHomeCurrency($account->currency_code);

        return new self(
            mb_strtoupper((string) ($foreign ? $account->currency_code : $company->currency_code)),
            $foreign,
        );
    }

    /**
     * SQL for a line's signed amount (money in positive) in the account's
     * currency, with its bindings.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function signedSql(string $table = 'journal_lines'): array
    {
        if (! $this->foreign) {
            return ["({$table}.debit_cents - {$table}.credit_cents)", []];
        }

        [$statementFigure, $bindings] = $this->statementFigureSql($table);

        return [
            '(CASE'
            ." WHEN {$table}.currency_code = ? THEN COALESCE({$table}.foreign_debit_cents, 0) - COALESCE({$table}.foreign_credit_cents, 0)"
            ." WHEN {$table}.currency_code IS NULL AND {$statementFigure} THEN {$table}.debit_cents - {$table}.credit_cents"
            .' ELSE 0 END)',
            [$this->currencyCode, ...$bindings],
        ];
    }

    /**
     * SQL that is 1 for a line with no amount in the account's currency (one
     * that counts as zero for want of it), else 0, with its bindings.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function missingSql(string $table = 'journal_lines'): array
    {
        if (! $this->foreign) {
            return ['0', []];
        }

        [$statementFigure, $bindings] = $this->statementFigureSql($table);

        // A revaluation, or the reversal of one, has no foreign units by design.
        $revaluation = "EXISTS (SELECT 1 FROM journal_entries aca_hca WHERE aca_hca.id = {$table}.journal_entry_id AND (aca_hca.source_type = ?"
            .' OR EXISTS (SELECT 1 FROM journal_entries aca_hca_orig WHERE aca_hca_orig.id = aca_hca.reverses_entry_id AND aca_hca_orig.source_type = ?)))';

        return [
            '(CASE'
            ." WHEN {$table}.currency_code = ? THEN 0"
            ." WHEN {$table}.currency_code IS NULL AND {$statementFigure} THEN 0"
            ." WHEN {$table}.currency_code IS NULL AND {$revaluation} THEN 0"
            .' ELSE 1 END)',
            [$this->currencyCode, ...$bindings, CurrencyRevaluation::class, CurrencyRevaluation::class],
        ];
    }

    /**
     * Select every column of the query's lines plus `account_amount_cents` and
     * `account_amount_missing`. A home-currency account needs neither: its
     * amounts are read straight off the home columns.
     *
     * @param  Builder<JournalLine>  $query
     * @return Builder<JournalLine>
     */
    public function withAmounts(Builder $query): Builder
    {
        if (! $this->foreign) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        if ($query->getQuery()->columns === null) {
            $query->select("{$table}.*");
        }

        [$signed, $signedBindings] = $this->signedSql($table);
        [$missing, $missingBindings] = $this->missingSql($table);

        return $query
            ->selectRaw("{$signed} AS account_amount_cents", $signedBindings)
            ->selectRaw("{$missing} AS account_amount_missing", $missingBindings);
    }

    /**
     * The net of the query's lines (money in positive) in the account's currency.
     *
     * @param  Builder<JournalLine>  $query
     */
    public function sum(Builder $query): int
    {
        [$signed, $bindings] = $this->signedSql($query->getModel()->getTable());

        return (int) (clone $query)
            ->select([])
            ->reorder()
            ->selectRaw("COALESCE(SUM({$signed}), 0) AS account_amount_sum", $bindings)
            ->value('account_amount_sum');
    }

    /**
     * Keep one side of the reconcile screen: 'payments' (money out) or
     * 'deposits' (money in); any other value keeps both. A foreign line that is
     * zero in the account's currency stays on the side its home cents put it.
     *
     * @param  Builder<JournalLine>  $query
     * @return Builder<JournalLine>
     */
    public function whereSide(Builder $query, string $side): Builder
    {
        if (! in_array($side, ['payments', 'deposits'], true)) {
            return $query;
        }

        $table = $query->getModel()->getTable();
        $homeColumn = $table.'.'.($side === 'payments' ? 'credit_cents' : 'debit_cents');

        if (! $this->foreign) {
            return $query->where($homeColumn, '>', 0);
        }

        [$signed, $bindings] = $this->signedSql($table);
        $operator = $side === 'payments' ? '<' : '>';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw("{$signed} {$operator} 0", $bindings)
            ->orWhere(fn (Builder $q) => $q->whereRaw("{$signed} = 0", $bindings)->where($homeColumn, '>', 0)));
    }

    /**
     * A line's signed amount (money in positive) in the account's currency. On
     * a foreign account the line must have been loaded through {@see withAmounts()}.
     */
    public function signedCents(JournalLine $line): int
    {
        if (! $this->foreign) {
            return (int) $line->debit_cents - (int) $line->credit_cents;
        }

        $cents = $line->getAttribute('account_amount_cents');

        if ($cents === null) {
            throw new LogicException('Load the line through AccountCurrencyAmounts::withAmounts() first.');
        }

        return (int) $cents;
    }

    /**
     * Whether a line counts as zero because it has no amount in the account's
     * currency (never true on a home-currency account).
     */
    public function isMissing(JournalLine $line): bool
    {
        return $this->foreign && (int) $line->getAttribute('account_amount_missing') === 1;
    }

    /**
     * Whether a line belongs on the given side of the reconcile screen, by the
     * same rule as {@see whereSide()}.
     */
    public function isOnSide(JournalLine $line, string $side): bool
    {
        $homeCents = (int) $line->getAttribute($side === 'payments' ? 'credit_cents' : 'debit_cents');

        if (! $this->foreign) {
            return $homeCents > 0;
        }

        $signed = $this->signedCents($line);

        return ($side === 'payments' ? $signed < 0 : $signed > 0) || ($signed === 0 && $homeCents > 0);
    }

    /**
     * The amount a line shows on the given side of the reconcile screen, in
     * the account's currency: what it paid out, or what it brought in.
     */
    public function sideCents(JournalLine $line, string $side): int
    {
        if (! $this->foreign) {
            return (int) $line->getAttribute($side === 'payments' ? 'credit_cents' : 'debit_cents');
        }

        $signed = $this->signedCents($line);

        return $side === 'payments' ? max(-$signed, 0) : max($signed, 0);
    }

    /**
     * EXISTS clause: the line's home cents were the statement's own figure —
     * its entry came from {@see STATEMENT_FIGURE_SOURCES}, or it reverses one
     * whose line on this account had no foreign memo either.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function statementFigureSql(string $table): array
    {
        $sources = implode(', ', array_fill(0, count(self::STATEMENT_FIGURE_SOURCES), '?'));

        return [
            "EXISTS (SELECT 1 FROM journal_entries aca_je WHERE aca_je.id = {$table}.journal_entry_id AND ("
            ."aca_je.source_type IN ({$sources})"
            .' OR EXISTS (SELECT 1 FROM journal_entries aca_orig WHERE aca_orig.id = aca_je.reverses_entry_id'
            ." AND aca_orig.source_type IN ({$sources})"
            .' AND NOT EXISTS (SELECT 1 FROM journal_lines aca_orig_line WHERE aca_orig_line.journal_entry_id = aca_orig.id'
            ." AND aca_orig_line.account_id = {$table}.account_id AND aca_orig_line.currency_code IS NOT NULL))))",
            [...self::STATEMENT_FIGURE_SOURCES, ...self::STATEMENT_FIGURE_SOURCES],
        ];
    }
}
