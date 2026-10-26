<?php

namespace App\Services\Banking\Feeds;

use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Enums\StatementLineMatchStatus;
use App\Models\BankFeedAccount;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Services\Banking\Feeds\Akahu\AkahuClient;
use App\Services\Banking\Feeds\Akahu\AkahuException;
use App\Services\Banking\Feeds\Akahu\AkahuTransactionMapper;
use App\Services\Banking\Import\DTO\ParsedTransaction;
use App\Services\Banking\Import\StatementMatcher;
use App\Services\Banking\Import\Support\StatementFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Pulls new transactions for one linked feed account into the existing statement
 * pipeline: each sync that finds something new becomes an ordinary
 * BankStatementImport (format "feed"), its lines are matched against the ledger
 * and rule-categorized by the same matcher a file import uses, and whatever is
 * left lands in the standing For Review queue.
 *
 * De-duplication is by Akahu's transaction id. A transaction that is already
 * known is updated only while it is still unreviewed; once the user has matched
 * or posted it, a later change at the bank is recorded on the line (raw.feed_changed)
 * but never rewrites what was reviewed.
 */
class BankFeedSyncer
{
    public function __construct(private readonly StatementMatcher $matcher) {}

    /**
     * @return array{added: int, updated: int, skipped: int, import_id: int|null}
     */
    public function sync(BankFeedAccount $feedAccount, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $feedAccount->loadMissing('connection', 'account');
        $ledgerAccount = $feedAccount->account;
        $connection = $feedAccount->connection;

        if ($ledgerAccount === null || ! $feedAccount->is_active) {
            throw new RuntimeException(__('Link this feed account to a bank account first.'));
        }

        if ($feedAccount->currency !== null && $ledgerAccount->currency_code !== null
            && strtoupper($feedAccount->currency) !== strtoupper((string) $ledgerAccount->currency_code)) {
            $message = __('Currency mismatch: the feed account is :feed but the ledger account is :ledger.', [
                'feed' => $feedAccount->currency,
                'ledger' => $ledgerAccount->currency_code,
            ]);
            $feedAccount->forceFill(['last_error' => $message])->save();

            throw new RuntimeException($message);
        }

        $timezone = $feedAccount->company->timezone ?: 'Pacific/Auckland';
        $start = $this->startFor($feedAccount, $now, $timezone);

        try {
            $client = AkahuClient::make($connection->app_token, $connection->user_token);
            $items = $client->transactions($feedAccount->external_id, $start, $now);
        } catch (AkahuException $e) {
            $feedAccount->forceFill(['last_error' => $e->getMessage()])->save();
            $connection->forceFill(['last_error' => $e->getMessage()])->save();

            throw $e;
        }

        $parsed = [];
        foreach ($items as $item) {
            $txn = AkahuTransactionMapper::toParsed($item, $timezone);

            if ($txn !== null) {
                $parsed[] = $txn;
            }
        }

        try {
            $result = $this->apply($feedAccount, (int) $ledgerAccount->id, $parsed, $now, $timezone);
        } catch (Throwable $e) {
            $feedAccount->forceFill(['last_error' => $e->getMessage()])->save();

            throw $e;
        }

        $feedAccount->forceFill(['last_synced_at' => $now, 'last_error' => null])->save();
        $connection->forceFill(['last_synced_at' => $now, 'last_error' => null, 'status' => 'active'])->save();

        return $result;
    }

    /**
     * Akahu's `start` is exclusive, so step back a second from the day we want.
     */
    private function startFor(BankFeedAccount $feedAccount, CarbonImmutable $now, string $timezone): CarbonImmutable
    {
        if ($feedAccount->last_synced_at !== null) {
            $days = max(1, (int) config('bank_feeds.overlap_days', 7));

            return CarbonImmutable::instance($feedAccount->last_synced_at)->subDays($days);
        }

        $from = $feedAccount->sync_from !== null
            ? CarbonImmutable::parse($feedAccount->sync_from->toDateString(), $timezone)->startOfDay()
            : $now->setTimezone($timezone)->subDays(max(1, (int) config('bank_feeds.default_history_days', 30)))->startOfDay();

        return $from->subSecond();
    }

    /**
     * @param  list<ParsedTransaction>  $parsed
     * @return array{added: int, updated: int, skipped: int, import_id: int|null}
     */
    private function apply(BankFeedAccount $feedAccount, int $ledgerAccountId, array $parsed, CarbonImmutable $now, string $timezone): array
    {
        $companyId = (int) $feedAccount->company_id;
        $existing = BankStatementLine::query()
            ->where('company_id', $companyId)
            ->where('account_id', $ledgerAccountId)
            ->whereIn('external_id', array_map(fn (ParsedTransaction $t): string => (string) $t->externalId, $parsed))
            ->get()
            ->keyBy('external_id');

        $new = [];
        $updated = 0;
        $skipped = 0;

        foreach ($parsed as $txn) {
            $line = $existing->get($txn->externalId);

            if ($line === null) {
                $new[] = $txn;

                continue;
            }

            if ($this->updateUnreviewed($line, $txn, $ledgerAccountId)) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        $importId = null;

        if ($new !== []) {
            $import = DB::transaction(function () use ($feedAccount, $ledgerAccountId, $new, $now, $timezone, $companyId): BankStatementImport {
                $dates = array_map(fn (ParsedTransaction $t): string => $t->date->toDateString(), $new);
                sort($dates);

                $last = $new[array_key_last($new)];

                $import = BankStatementImport::query()->create([
                    'company_id' => $companyId,
                    'account_id' => $ledgerAccountId,
                    'bank_feed_account_id' => $feedAccount->id,
                    'source_format' => BankStatementFormat::Feed->value,
                    'original_filename' => 'Akahu — '.$feedAccount->name.' — '.$now->setTimezone($timezone)->toDateString(),
                    'status' => BankStatementImportStatus::Matching->value,
                    'statement_begin_date' => $dates[0],
                    'statement_end_date' => $dates[count($dates) - 1],
                    'statement_end_balance_cents' => $last->balanceCents,
                    'parse_meta' => ['source' => 'akahu', 'external_account_id' => $feedAccount->external_id],
                    'line_count' => count($new),
                ]);

                foreach ($new as $txn) {
                    BankStatementLine::query()->create([
                        'company_id' => $companyId,
                        'bank_statement_import_id' => $import->id,
                        'account_id' => $ledgerAccountId,
                        'txn_date' => $txn->date->toDateString(),
                        'amount_cents' => $txn->amountCents,
                        'description' => $txn->description,
                        'external_id' => $txn->externalId,
                        'fingerprint' => $this->fingerprint($ledgerAccountId, $txn),
                        'balance_cents' => $txn->balanceCents,
                        'raw' => $txn->raw,
                        'match_status' => StatementLineMatchStatus::Unmatched->value,
                    ]);
                }

                return $import;
            });

            // Match against the ledger, apply bank rules and suggestions; leaves the
            // import "ready" and the unresolved lines in the For Review queue.
            $this->matcher->match($import);
            $importId = (int) $import->id;
        }

        return ['added' => count($new), 'updated' => $updated, 'skipped' => $skipped, 'import_id' => $importId];
    }

    /**
     * Apply a bank-side change to a line the user has not touched yet. Returns
     * true when the line was rewritten.
     */
    private function updateUnreviewed(BankStatementLine $line, ParsedTransaction $txn, int $ledgerAccountId): bool
    {
        $changed = CarbonImmutable::parse($line->txn_date)->toDateString() !== $txn->date->toDateString()
            || (int) $line->amount_cents !== $txn->amountCents
            || (string) $line->description !== $txn->description;

        if (! $changed) {
            return false;
        }

        $unreviewed = in_array($line->match_status, [StatementLineMatchStatus::Unmatched, StatementLineMatchStatus::Suggested], true)
            && $line->created_journal_entry_id === null;

        if (! $unreviewed) {
            // Reviewed, matched or posted: keep the user's decision, note the change.
            $raw = (array) $line->raw;
            $raw['feed_changed'] = $txn->raw;
            $line->forceFill(['raw' => $raw])->save();

            return false;
        }

        $line->forceFill([
            'txn_date' => $txn->date->toDateString(),
            'amount_cents' => $txn->amountCents,
            'description' => $txn->description,
            'balance_cents' => $txn->balanceCents,
            'raw' => $txn->raw,
            'fingerprint' => $this->fingerprint($ledgerAccountId, $txn),
        ])->save();

        return true;
    }

    private function fingerprint(int $ledgerAccountId, ParsedTransaction $txn): string
    {
        return StatementFingerprint::for(
            $ledgerAccountId,
            $txn->date->toDateString(),
            $txn->amountCents,
            $txn->description,
            $txn->externalId,
        );
    }
}
