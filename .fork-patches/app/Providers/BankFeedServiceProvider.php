<?php

namespace App\Providers;

use App\Support\SchedulerFailureAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Fork: registers the scheduled bank-feed sync. Kept in its own provider (rather than
 * routes/console.php) so upstream edits to the scheduler file never conflict.
 */
class BankFeedServiceProvider extends ServiceProvider
{
    public const DEFAULT_EVERY_HOURS = 4;

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('bank-feeds:sync')
                ->cron(self::cronExpression())
                ->withoutOverlapping()
                ->onOneServer()
                ->onFailure(SchedulerFailureAlert::for('bank-feeds:sync'));
        });
    }

    /**
     * BANK_FEEDS_SYNC_EVERY_HOURS: run every N hours, counting from 00:00 in the
     * app time zone (UTC) — 4 gives 00:00, 04:00, 08:00, 12:00, 16:00 and 20:00.
     * A whole number from 1 to 24 (24 = once a day, at 00:00); anything else
     * falls back to every 4 hours.
     */
    public static function everyHours(): int
    {
        $value = config('bank_feeds.sync_every_hours', self::DEFAULT_EVERY_HOURS);
        $hours = filter_var($value, FILTER_VALIDATE_INT);

        return $hours !== false && $hours >= 1 && $hours <= 24 ? $hours : self::DEFAULT_EVERY_HOURS;
    }

    /**
     * The hours of the day the sync runs, counting from 00:00. When N does not
     * divide 24 evenly the last gap of the day is shorter (5 → 0, 5, 10, 15, 20).
     *
     * @return list<int>
     */
    public static function syncHours(): array
    {
        $step = self::everyHours();

        return $step >= 24 ? [0] : range(0, 23, $step);
    }

    public static function cronExpression(): string
    {
        return '0 '.implode(',', self::syncHours()).' * * *';
    }
}
