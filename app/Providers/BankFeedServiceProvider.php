<?php

namespace App\Providers;

use App\Support\SchedulerFailureAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Fork: registers the scheduled bank-feed sync (several times a day). Kept in its own provider (rather than
 * routes/console.php) so upstream edits to the scheduler file never conflict.
 */
class BankFeedServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            foreach (self::syncTimes() as $time) {
                $schedule->command('bank-feeds:sync')
                    ->dailyAt($time)
                    ->withoutOverlapping()
                    ->onOneServer()
                    ->onFailure(SchedulerFailureAlert::for('bank-feeds:sync'));
            }
        });
    }

    /**
     * The times of day (app time zone) the sync runs: BANK_FEEDS_SYNC_AT holds
     * one or several HH:MM values separated by commas. Anything that is not a
     * valid time is ignored; with nothing valid left the sync runs at 16:30.
     *
     * @return list<string>
     */
    public static function syncTimes(): array
    {
        $times = [];

        foreach (explode(',', (string) config('bank_feeds.sync_at', '16:30')) as $candidate) {
            $candidate = trim($candidate);

            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $candidate, $m) === 1) {
                $times[] = sprintf('%02d:%s', (int) $m[1], $m[2]);
            }
        }

        $times = array_values(array_unique($times));

        return $times === [] ? ['16:30'] : $times;
    }
}
