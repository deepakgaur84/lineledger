<?php

namespace App\Services\Banking\Feeds\Akahu;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Akahu REST API (https://developers.akahu.nz). Authenticates
 * with an app token and a user token — for a personal app both come from the
 * Akahu developer dashboard. Only settled transactions are read: pending ones
 * have no stable id and are rebuilt by Akahu on every query.
 */
class AkahuClient
{
    public function __construct(
        private readonly string $appToken,
        private readonly string $userToken,
        private readonly string $baseUrl = 'https://api.akahu.io/v1',
        private readonly int $timeout = 30,
    ) {}

    public static function make(string $appToken, string $userToken): self
    {
        return new self(
            $appToken,
            $userToken,
            rtrim((string) config('bank_feeds.akahu.base_url', 'https://api.akahu.io/v1'), '/'),
            (int) config('bank_feeds.akahu.timeout', 30),
        );
    }

    /**
     * Every account the user token can see.
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        $body = $this->get('/accounts');

        return $this->items($body);
    }

    /**
     * Settled transactions for one account. `start` is exclusive and `end`
     * inclusive; both are repeated on every page, as Akahu requires. `$onPage`
     * is told the running total after each page, for progress display.
     *
     * @return list<array<string, mixed>>
     */
    public function transactions(string $externalAccountId, CarbonInterface $start, CarbonInterface $end, ?callable $onPage = null): array
    {
        $all = [];
        $cursor = null;

        do {
            $query = [
                'start' => $start->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'end' => $end->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ];

            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }

            $body = $this->get('/accounts/'.rawurlencode($externalAccountId).'/transactions', $query);

            foreach ($this->items($body) as $item) {
                $all[] = $item;
            }

            if ($onPage !== null) {
                $onPage(count($all));
            }

            $next = $body['cursor']['next'] ?? null;
            $cursor = is_string($next) && $next !== '' ? $next : null;
        } while ($cursor !== null);

        return $all;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->request()->get($this->baseUrl.$path, $query);
        } catch (ConnectionException $e) {
            throw new AkahuException(__('Could not reach Akahu. Try again shortly.'), 0, $e);
        }

        if ($response->failed()) {
            throw new AkahuException($this->describeFailure($response));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$this->userToken,
            'X-Akahu-Id' => $this->appToken,
        ])->acceptJson()->timeout($this->timeout);
    }

    private function describeFailure(Response $response): string
    {
        return match ($response->status()) {
            401, 403 => __('Akahu rejected the tokens. Check the app token and user token, and that access has not been revoked.'),
            429 => __('Akahu is rate limiting requests. Try again later.'),
            default => __('Akahu returned an error (HTTP :status).', ['status' => $response->status()]),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function items(array $body): array
    {
        $items = $body['items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, 'is_array'));
    }
}
