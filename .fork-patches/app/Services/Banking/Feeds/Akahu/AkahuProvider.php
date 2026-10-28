<?php

namespace App\Services\Banking\Feeds\Akahu;

use App\Models\BankFeedAccount;
use App\Models\BankFeedConnection;
use App\Services\Banking\Feeds\Contracts\BankFeedProvider;
use App\Services\Banking\Feeds\DTO\FeedAccountInfo;
use Carbon\CarbonImmutable;

/**
 * Akahu (NZ open banking): ANZ, ASB, Wise and others, through a personal app's
 * App ID token and user access token.
 */
final class AkahuProvider implements BankFeedProvider
{
    public const KEY = 'akahu';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Akahu';
    }

    public function description(): string
    {
        return __('New Zealand banks (ANZ, ASB, Wise and more) through your own Akahu personal app. Transactions are pulled every day.');
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function connect(int $companyId, array $credentials): BankFeedConnection
    {
        return BankFeedConnection::query()->updateOrCreate(
            ['company_id' => $companyId, 'provider' => self::KEY],
            [
                'app_token' => trim($credentials['app_token'] ?? ''),
                'user_token' => trim($credentials['user_token'] ?? ''),
                'status' => 'active',
                'last_error' => null,
            ],
        );
    }

    public function accounts(BankFeedConnection $connection): array
    {
        $accounts = [];

        foreach (AkahuClient::make($connection->app_token, $connection->user_token)->accounts() as $item) {
            $externalId = $item['_id'] ?? null;

            if (! is_string($externalId) || $externalId === '') {
                continue;
            }

            $balance = $item['balance'] ?? [];
            $currency = is_array($balance) ? ($balance['currency'] ?? null) : null;

            $accounts[] = new FeedAccountInfo(
                externalId: $externalId,
                name: (string) ($item['name'] ?? $externalId),
                institution: is_array($item['connection'] ?? null) && is_string($item['connection']['name'] ?? null) ? $item['connection']['name'] : null,
                accountNumber: is_string($item['formatted_account'] ?? null) ? $item['formatted_account'] : null,
                currency: is_string($currency) && strlen($currency) === 3 ? strtoupper($currency) : null,
            );
        }

        return $accounts;
    }

    public function transactions(
        BankFeedConnection $connection,
        BankFeedAccount $account,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $timezone,
        ?callable $onPage = null,
    ): array {
        $items = AkahuClient::make($connection->app_token, $connection->user_token)
            ->transactions($account->external_id, $start, $end, $onPage);

        $parsed = [];

        foreach ($items as $item) {
            $txn = AkahuTransactionMapper::toParsed($item, $timezone);

            if ($txn !== null) {
                $parsed[] = $txn;
            }
        }

        return $parsed;
    }
}
