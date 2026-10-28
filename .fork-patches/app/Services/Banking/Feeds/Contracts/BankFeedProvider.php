<?php

namespace App\Services\Banking\Feeds\Contracts;

use App\Models\BankFeedAccount;
use App\Models\BankFeedConnection;
use App\Services\Banking\Feeds\BankFeedException;
use App\Services\Banking\Feeds\DTO\FeedAccountInfo;
use App\Services\Banking\Import\DTO\ParsedTransaction;
use Carbon\CarbonImmutable;

/**
 * A source of bank transactions: Akahu today, Actual Budget (through the
 * bridge service) next. The syncer and the Bank feeds page only talk to this
 * interface, so adding a provider never touches the matching, For Review or
 * posting code.
 *
 * Sign convention every provider must meet: money into the account is
 * positive, money out is negative, in the account's own currency.
 */
interface BankFeedProvider
{
    /** Stable id stored on the connection (`bank_feed_connections.provider`). */
    public function key(): string;

    /** Name shown to people. */
    public function label(): string;

    /** One or two sentences for the provider card on the Bank feeds page. */
    public function description(): string;

    /**
     * False while a provider is announced but cannot be used yet; the page shows
     * it greyed out with {@see unavailableReason()}.
     */
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;

    /**
     * Store (or replace) this company's credentials for the provider and return
     * the connection. Nothing is contacted yet.
     *
     * @param  array<string, string>  $credentials
     */
    public function connect(int $companyId, array $credentials): BankFeedConnection;

    /**
     * Every bank account the connection can see.
     *
     * @return list<FeedAccountInfo>
     *
     * @throws BankFeedException
     */
    public function accounts(BankFeedConnection $connection): array;

    /**
     * Settled transactions for one account between `$start` (exclusive) and
     * `$end` (inclusive), already in the importer's normalized shape.
     * `$onPage` is told the running total, for progress display.
     *
     * @param  (callable(int): mixed)|null  $onPage
     * @return list<ParsedTransaction>
     *
     * @throws BankFeedException
     */
    public function transactions(
        BankFeedConnection $connection,
        BankFeedAccount $account,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $timezone,
        ?callable $onPage = null,
    ): array;
}
