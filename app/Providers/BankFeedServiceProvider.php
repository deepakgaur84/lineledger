<?php

namespace App\Providers;

use App\Support\SchedulerFailureAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Fork: registers the daily bank-feed sync. Kept in its own provider (rather than
 * routes/console.php) so upstream edits to the scheduler file never conflict.
 */
class BankFeedServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('bank-feeds:sync')
                ->dailyAt((string) config('bank_feeds.sync_at', '16:30'))
                ->withoutOverlapping()
                ->onOneServer()
                ->onFailure(SchedulerFailureAlert::for('bank-feeds:sync'));
        });
    }
}
