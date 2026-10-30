<?php

namespace App\Actions\Banking;

use App\Enums\StatementLineMatchStatus;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Services\Banking\Import\StatementImportCommitter;
use App\Services\Currency\ExchangeRateService;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\ExpensePoster;
use App\Services\Posting\JournalPoster;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Posts a single bank statement line to the general ledger against a chosen
 * contra account — the "Add" action from a feed. Builds the balanced two-leg
 * entry by the line's sign (money in debits the bank; money out credits it),
 * posts it through the audited pipeline, and stamps the line so it reads as
 * Created and links back to its entry.
 *
 * The line's amount is in its account's currency. On a foreign-currency account
 * both legs are booked in home cents at the rate for the transaction date and
 * carry the foreign amount as their memo — the same rate lookup and rounding
 * {@see ExpensePoster} uses, so a line posts the same home value whichever path
 * records it.
 *
 * Extracted from {@see StatementImportCommitter}. Callers should go through
 * {@see RecordStatementLine}, which decides between this plain entry, an
 * Expense to a vendor, or a bill payment, and refuses a category in a third
 * currency; this is the journal-entry primitive.
 */
final class AddStatementLineEntry
{
    public function __construct(
        private readonly JournalPoster $poster,
        private readonly EntryNumberGenerator $entryNumbers,
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    public function handle(BankStatementLine $line, int $contraAccountId, ?int $contactId = null): JournalEntry
    {
        return DB::transaction(function () use ($line, $contraAccountId, $contactId): JournalEntry {
            $account = $line->account()->firstOrFail();
            $company = $account->company;
            $date = CarbonImmutable::parse($line->txn_date);
            $memo = ($line->description !== null && $line->description !== '') ? $line->description : null;

            $isForeign = ! $company->isHomeCurrency($account->currency_code);
            $currency = $isForeign ? mb_strtoupper((string) $account->currency_code) : null;
            $rate = $isForeign ? $this->exchangeRates->rateFor($company, (string) $currency, $date) : '1';

            $foreign = abs((int) $line->amount_cents);
            $home = Currency::toHomeCents($foreign, $rate);

            $entry = JournalEntry::query()->create([
                'company_id' => $account->company_id,
                'entry_no' => $this->entryNumbers->next($company),
                'entry_date' => $date->toDateString(),
                'memo' => $memo ?? __('Bank statement import'),
                'source_type' => BankStatementImport::class,
                'source_id' => $line->bank_statement_import_id,
            ]);

            $bank = ['account_id' => $account->id, 'memo' => $memo, 'line_order' => 0];
            $contra = ['account_id' => $contraAccountId, 'contact_id' => $contactId, 'memo' => $memo, 'line_order' => 1];
            $debit = ['debit_cents' => $home, 'credit_cents' => 0, ...Currency::lineMemo($currency, $rate, $foreign, 0)];
            $credit = ['debit_cents' => 0, 'credit_cents' => $home, ...Currency::lineMemo($currency, $rate, 0, $foreign)];

            if ((int) $line->amount_cents >= 0) {
                // Money in: DR bank, CR the chosen income/contra account.
                $bankLine = $entry->lines()->create($bank + $debit);
                $entry->lines()->create($contra + $credit);
            } else {
                // Money out: DR the chosen expense/contra account, CR bank.
                $entry->lines()->create($contra + $debit);
                $bankLine = $entry->lines()->create($bank + $credit);
            }

            $entry->refresh();
            $this->poster->post($entry);

            $line->forceFill([
                'suggested_account_id' => $contraAccountId,
                'created_journal_entry_id' => $entry->id,
                'matched_journal_line_id' => $bankLine->id,
                'match_status' => StatementLineMatchStatus::Created->value,
            ])->save();

            return $entry;
        });
    }
}
