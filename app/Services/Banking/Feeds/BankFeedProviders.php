<?php

namespace App\Services\Banking\Feeds;

use App\Models\BankFeedConnection;
use App\Services\Banking\Feeds\ActualBudget\ActualBudgetProvider;
use App\Services\Banking\Feeds\Akahu\AkahuProvider;
use App\Services\Banking\Feeds\Contracts\BankFeedProvider;
use InvalidArgumentException;

/**
 * The list of bank-feed providers LineLedger knows about. Adding a provider is
 * one class implementing {@see BankFeedProvider} and one line here.
 */
final class BankFeedProviders
{
    /**
     * @return array<string, BankFeedProvider> keyed by provider key, in display order
     */
    public static function all(): array
    {
        $providers = [];

        foreach ([new AkahuProvider, new ActualBudgetProvider] as $provider) {
            $providers[$provider->key()] = $provider;
        }

        return $providers;
    }

    public static function get(string $key): BankFeedProvider
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown bank feed provider [{$key}].");
    }

    public static function forConnection(BankFeedConnection $connection): BankFeedProvider
    {
        return self::get((string) $connection->provider);
    }
}
