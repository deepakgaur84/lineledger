<?php

use App\Providers\BankFeedServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

it('runs every four hours from 00:00 by default', function () {
    config(['bank_feeds.sync_every_hours' => 4]);

    expect(BankFeedServiceProvider::syncHours())->toBe([0, 4, 8, 12, 16, 20])
        ->and(BankFeedServiceProvider::cronExpression())->toBe('0 0,4,8,12,16,20 * * *');
});

it('counts the configured number of hours from 00:00', function () {
    config(['bank_feeds.sync_every_hours' => '6']);
    expect(BankFeedServiceProvider::syncHours())->toBe([0, 6, 12, 18]);

    config(['bank_feeds.sync_every_hours' => 1]);
    expect(BankFeedServiceProvider::syncHours())->toHaveCount(24);

    config(['bank_feeds.sync_every_hours' => 24]);
    expect(BankFeedServiceProvider::syncHours())->toBe([0]);

    config(['bank_feeds.sync_every_hours' => 5]);
    expect(BankFeedServiceProvider::syncHours())->toBe([0, 5, 10, 15, 20]);
});

it('falls back to every four hours for a value that is not 1 to 24', function () {
    foreach (['0', '25', '-3', 'abc', '', '2.5', null] as $bad) {
        config(['bank_feeds.sync_every_hours' => $bad]);

        expect(BankFeedServiceProvider::everyHours())->toBe(4);
    }
});

it('registers a single scheduler event for the bank-feed sync', function () {
    config(['bank_feeds.sync_every_hours' => 4]);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'bank-feeds:sync'));

    expect($events)->toHaveCount(1);
});
