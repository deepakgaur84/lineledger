<?php

namespace App\Services\Banking\Feeds\ActualBudget;

use App\Models\BankFeedAccount;
use App\Models\BankFeedConnection;
use App\Services\Banking\Feeds\BankFeedException;
use App\Services\Banking\Feeds\Contracts\BankFeedProvider;
use Carbon\CarbonImmutable;

/**
 * Placeholder for the Actual Budget bridge: a small companion service that logs
 * in to an Actual server and hands LineLedger the bank-synced transactions, which
 * opens banks outside New Zealand (US via Plaid / SimpleFIN, EU via GoCardless).
 * Listed on the Bank feeds page so the plan is visible; it cannot be connected
 * until the bridge ships.
 */
final class ActualBudgetProvider implements BankFeedProvider
{
    public const KEY = 'actual';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Actual Budget';
    }

    public function description(): string
    {
        return __('Banks outside New Zealand — US, UK and Europe — through an Actual Budget server and the LineLedger bridge service.');
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): string
    {
        return __('Coming soon. The bridge service is the next build.');
    }

    public function connect(int $companyId, array $credentials): BankFeedConnection
    {
        throw new BankFeedException($this->unavailableReason());
    }

    public function accounts(BankFeedConnection $connection): array
    {
        throw new BankFeedException($this->unavailableReason());
    }

    public function transactions(
        BankFeedConnection $connection,
        BankFeedAccount $account,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $timezone,
        ?callable $onPage = null,
    ): array {
        throw new BankFeedException($this->unavailableReason());
    }
}
