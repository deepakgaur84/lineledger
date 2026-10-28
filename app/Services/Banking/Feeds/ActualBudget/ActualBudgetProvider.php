<?php

namespace App\Services\Banking\Feeds\ActualBudget;

use App\Models\BankFeedAccount;
use App\Models\BankFeedConnection;
use App\Services\Banking\Feeds\BankFeedException;
use App\Services\Banking\Feeds\Contracts\BankFeedProvider;
use App\Services\Banking\Feeds\DTO\FeedAccountInfo;
use App\Services\Banking\Import\DTO\ParsedTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Actual Budget through the bank bridge service (docker-compose `actual-bank-bridge`).
 * Actual does the bank syncing (Akahu, SimpleFIN, Enable Banking…); LineLedger asks
 * the bridge to run it and reads back the transactions Actual imported from the bank.
 *
 * Per company the connection stores the Actual server address, its password, the
 * budget's Sync ID and, for an encrypted budget, the file password. The bridge
 * address and its shared secret are server-wide settings (config/bank_feeds.php).
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
        return __('Any bank Actual can sync — New Zealand (Akahu), the US (SimpleFIN), Europe and more — through your Actual Budget server and the LineLedger bank bridge.');
    }

    public function isAvailable(): bool
    {
        return $this->bridgeUrl() !== '' && $this->bridgeToken() !== '';
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable()
            ? null
            : __('The bank bridge is not set up. Set ACTUAL_BRIDGE_URL and ACTUAL_BRIDGE_TOKEN in .env and start the actual-bank-bridge service.');
    }

    public function connect(int $companyId, array $credentials): BankFeedConnection
    {
        $stored = [
            'server_url' => trim($credentials['server_url'] ?? ''),
            'password' => $credentials['password'] ?? '',
            'sync_id' => trim($credentials['sync_id'] ?? ''),
            'file_password' => $credentials['file_password'] ?? '',
        ];

        foreach (['server_url', 'password', 'sync_id'] as $required) {
            if ($stored[$required] === '') {
                throw new BankFeedException(__('Enter the Actual server address, its password and the budget Sync ID.'));
            }
        }

        return BankFeedConnection::query()->updateOrCreate(
            ['company_id' => $companyId, 'provider' => self::KEY],
            [
                // Akahu's token columns are required by the table; unused here.
                'app_token' => '',
                'user_token' => '',
                'credentials' => $stored,
                'status' => 'active',
                'last_error' => null,
            ],
        );
    }

    public function accounts(BankFeedConnection $connection): array
    {
        $body = $this->call('/v1/accounts', $this->credentialsPayload($connection));
        $accounts = [];

        foreach ((array) ($body['accounts'] ?? []) as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '') {
                continue;
            }

            $accounts[] = new FeedAccountInfo(
                externalId: $item['id'],
                name: (string) ($item['name'] ?? $item['id']),
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
        $body = $this->call('/v1/transactions', $this->credentialsPayload($connection) + [
            'accountId' => $account->external_id,
            'start' => $start->setTimezone($timezone)->toDateString(),
            'end' => $end->setTimezone($timezone)->toDateString(),
            'sync' => true,
        ]);

        $parsed = [];

        foreach ((array) ($body['transactions'] ?? []) as $item) {
            $txn = is_array($item) ? $this->toParsed($item, $timezone) : null;

            if ($txn !== null) {
                $parsed[] = $txn;
            }
        }

        if ($onPage !== null) {
            $onPage(count($parsed));
        }

        return $parsed;
    }

    /**
     * Only transactions Actual imported from the bank carry an `importedId`; ones
     * typed into Actual by hand have none and are not bank data, so they are skipped.
     *
     * @param  array<string, mixed>  $item
     */
    private function toParsed(array $item, string $timezone): ?ParsedTransaction
    {
        $importedId = $item['importedId'] ?? null;
        $date = $item['date'] ?? null;
        $amount = $item['amount'] ?? null;

        if (! is_string($importedId) || $importedId === '' || ! is_string($date) || ! is_numeric($amount)) {
            return null;
        }

        $payee = is_string($item['payeeName'] ?? null) && trim($item['payeeName']) !== '' ? trim($item['payeeName']) : null;
        $imported = is_string($item['importedPayee'] ?? null) && trim($item['importedPayee']) !== '' ? trim($item['importedPayee']) : null;
        $notes = is_string($item['notes'] ?? null) && trim($item['notes']) !== '' ? trim($item['notes']) : null;

        // The payee Actual settled on doubles as the "merchant" the For Review
        // vendor prefill looks for.
        if ($payee !== null) {
            $item['merchant'] = ['name' => $payee];
        }

        return new ParsedTransaction(
            date: CarbonImmutable::parse($date, $timezone)->startOfDay(),
            amountCents: (int) $amount,
            description: $imported ?? $payee ?? $notes ?? '',
            externalId: $importedId,
            balanceCents: null,
            raw: $item,
        );
    }

    /**
     * @return array<string, string>
     */
    private function credentialsPayload(BankFeedConnection $connection): array
    {
        $stored = $connection->credentials ?? [];

        return [
            'serverUrl' => (string) ($stored['server_url'] ?? ''),
            'password' => (string) ($stored['password'] ?? ''),
            'syncId' => (string) ($stored['sync_id'] ?? ''),
            'filePassword' => (string) ($stored['file_password'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws BankFeedException
     */
    private function call(string $path, array $payload): array
    {
        if (! $this->isAvailable()) {
            throw new BankFeedException((string) $this->unavailableReason());
        }

        try {
            $response = Http::withToken($this->bridgeToken())
                ->acceptJson()
                ->timeout((int) config('bank_feeds.actual.timeout', 600))
                ->post($this->bridgeUrl().$path, $payload);
        } catch (ConnectionException $e) {
            throw new BankFeedException(__('Could not reach the bank bridge. Check that the actual-bank-bridge service is running.'), 0, $e);
        }

        if ($response->failed()) {
            $message = $response->json('error');

            throw new BankFeedException(is_string($message) && $message !== ''
                ? $message
                : __('The bank bridge returned an error (HTTP :status).', ['status' => $response->status()]));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function bridgeUrl(): string
    {
        return rtrim((string) config('bank_feeds.actual.bridge_url', ''), '/');
    }

    private function bridgeToken(): string
    {
        return (string) config('bank_feeds.actual.bridge_token', '');
    }
}
