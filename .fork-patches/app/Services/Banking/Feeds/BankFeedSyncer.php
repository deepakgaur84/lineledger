<?php

namespace App\Services\Banking\Feeds;

use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Enums\StatementLineMatchStatus;
use App\Models\BankFeedAccount;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Services\Banking\Feeds\Contracts\BankFeedProvider;
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
     * @param  (callable(string): mixed)|null  $progress  told what the sync is doing, for the page
     * @return array{added: int, updated: int, skipped: int, import_id: int|null}
     */
    public function sync(BankFeedAccount $feedAccount, ?CarbonImmutable $now = null, ?callable $progress = null): array
    {
        $now ??= CarbonImmutable::now();
        $report = $progress ?? static function (string $message): void {};

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

        $this->resumeUnfinishedMatching($feedAccount, $report);

        $provider = BankFeedProviders::forConnection($connection);

        $report(__('Fetching transactions from :provider…', ['provider' => $provider->label()]));

        try {
            $parsed = $provider->transactions(
                $connection,
                $feedAccount,
                $start,
                $now,
                $timezone,
                fn (int $total) => $report(__('Fetched :count transactions from :provider…', ['count' => $total, 'provider' => $provider->label()])),
            );
        } catch (BankFeedException $e) {
            $feedAccount->forceFill(['last_error' => $e->getMessage()])->save();
            $connection->forceFill(['last_error' => $e->getMessage()])->save();

            throw $e;
        }

        try {
            $result = $this->apply($feedAccount, (int) $ledgerAccount->id, $parsed, $now, $timezone, $report, $provider);
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
     * Active vendors keyed by normalized name. Names shared by two vendors are
     * left out: guessing between them would be worse than asking.
     *
     * @return array<string, int>
     */
    private function vendorIdsByName(int $companyId): array
    {
        $map = [];
        $ambiguous = [];

        $vendors = Contact::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_vendor', true)
            ->get(['id', 'display_name']);

        foreach ($vendors as $vendor) {
            $key = $this->normalizeName((string) $vendor->display_name);

            if ($key === '') {
                continue;
            }

            if (isset($map[$key])) {
                $ambiguous[$key] = true;

                continue;
            }

            $map[$key] = (int) $vendor->id;
        }

        return array_diff_key($map, $ambiguous);
    }

    /**
     * @param  array<string, int>  $vendors
     */
    private function vendorFor(ParsedTransaction $txn, array $vendors): ?int
    {
        $merchant = is_array($txn->raw['merchant'] ?? null) ? ($txn->raw['merchant']['name'] ?? null) : null;

        if (! is_string($merchant)) {
            return null;
        }

        return $vendors[$this->normalizeName($merchant)] ?? null;
    }

    private function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name)));
    }

    /**
     * A sync that died part-way (a timeout, a restart) can leave its import in
     * "matching" with the lines saved but never matched. Their ids are already
     * known, so later syncs would skip them — finish that matching here instead.
     *
     * @param  callable(string): mixed  $report
     */
    private function resumeUnfinishedMatching(BankFeedAccount $feedAccount, callable $report): void
    {
        $stuck = BankStatementImport::query()
            ->where('company_id', $feedAccount->company_id)
            ->where('bank_feed_account_id', $feedAccount->id)
            ->where('status', BankStatementImportStatus::Matching->value)
            ->get();

        foreach ($stuck as $import) {
            $report(__('Finishing matching from an earlier sync…'));

            // Matching rewrites the status of every line in the import, which would
            // bring back lines the person already excluded and unlink ones they
            // posted. Remember those decisions and put them back afterwards.
            $protected = BankStatementLine::query()
                ->where('bank_statement_import_id', $import->id)
                ->where(fn ($q) => $q
                    ->where('match_status', StatementLineMatchStatus::Ignored->value)
                    ->orWhereNotNull('created_journal_entry_id'))
                ->get(['id', 'match_status', 'matched_journal_line_id', 'match_confidence', 'match_reason'])
                ->keyBy('id');

            try {
                $this->matcher->match($import);
            } catch (Throwable $e) {
                report($e);

                // Without this the import stays "matching" and every later sync
                // runs the same failing pass again. Its lines are already in For
                // Review; they just carry no suggestions.
                $import->forceFill(['status' => BankStatementImportStatus::Ready->value])->save();
            } finally {
                foreach ($protected as $line) {
                    BankStatementLine::query()->whereKey($line->id)->update([
                        'match_status' => $line->getRawOriginal('match_status'),
                        'matched_journal_line_id' => $line->matched_journal_line_id,
                        'match_confidence' => $line->match_confidence,
                        'match_reason' => $line->match_reason,
                    ]);
                }
            }
        }
    }

    /**
     * @param  list<ParsedTransaction>  $parsed
     * @param  callable(string): mixed  $report
     * @return array{added: int, updated: int, skipped: int, import_id: int|null}
     */
    private function apply(BankFeedAccount $feedAccount, int $ledgerAccountId, array $parsed, CarbonImmutable $now, string $timezone, callable $report, BankFeedProvider $provider): array
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
            $report(__('Saving :count new transactions…', ['count' => count($new)]));

            $vendors = $this->vendorIdsByName($companyId);

            $import = DB::transaction(function () use ($feedAccount, $ledgerAccountId, $new, $now, $timezone, $companyId, $vendors, $provider): BankStatementImport {
                $dates = array_map(fn (ParsedTransaction $t): string => $t->date->toDateString(), $new);
                sort($dates);

                $last = $new[array_key_last($new)];

                $import = BankStatementImport::query()->create([
                    'company_id' => $companyId,
                    'account_id' => $ledgerAccountId,
                    'bank_feed_account_id' => $feedAccount->id,
                    'source_format' => BankStatementFormat::Feed->value,
                    'original_filename' => $provider->label().' — '.$feedAccount->name.' — '.$now->setTimezone($timezone)->toDateString(),
                    'status' => BankStatementImportStatus::Matching->value,
                    'statement_begin_date' => $dates[0],
                    'statement_end_date' => $dates[count($dates) - 1],
                    'statement_end_balance_cents' => $last->balanceCents,
                    'parse_meta' => ['source' => $provider->key(), 'external_account_id' => $feedAccount->external_id],
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
                        // The bank's own merchant name, when it matches a vendor we already have.
                        'suggested_contact_id' => $txn->amountCents < 0 ? $this->vendorFor($txn, $vendors) : null,
                    ]);
                }

                return $import;
            });

            // Match against the ledger, apply bank rules and suggestions; leaves the
            // import "ready" and the unresolved lines in the For Review queue.
            $report(__('Matching :count transactions against your books and bank rules…', ['count' => count($new)]));
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
