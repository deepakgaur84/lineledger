<?php

namespace App\Services\Banking\Feeds;

use Illuminate\Support\Facades\Cache;

/**
 * Where a running (or just-finished) feed sync reports its progress, so the
 * Bank feeds page can show it while the work happens after the response.
 * Kept in the cache: it is only ever a progress display, never the record of
 * what was imported.
 */
final class BankFeedSyncStatus
{
    /** A "running" status that has not moved for this long is treated as dead. */
    private const STALE_AFTER_MINUTES = 15;

    public static function put(int $feedAccountId, string $state, string $message): void
    {
        Cache::put(self::key($feedAccountId), [
            'state' => $state,
            'message' => $message,
            'at' => now()->getTimestamp(),
        ], now()->addHour());
    }

    /**
     * @return array{state: string, message: string, at: int}|null
     */
    public static function get(int $feedAccountId): ?array
    {
        $value = Cache::get(self::key($feedAccountId));

        if (! is_array($value)) {
            return null;
        }

        $at = (int) ($value['at'] ?? 0);
        $state = (string) ($value['state'] ?? '');

        if ($state === 'running' && $at < now()->subMinutes(self::STALE_AFTER_MINUTES)->getTimestamp()) {
            return [
                'state' => 'error',
                'message' => __('The last sync stopped before it finished. Press Sync now to continue.'),
                'at' => $at,
            ];
        }

        return ['state' => $state, 'message' => (string) ($value['message'] ?? ''), 'at' => $at];
    }

    public static function isRunning(int $feedAccountId): bool
    {
        return (self::get($feedAccountId)['state'] ?? null) === 'running';
    }

    private static function key(int $feedAccountId): string
    {
        return 'bank-feed-sync:'.$feedAccountId;
    }
}
