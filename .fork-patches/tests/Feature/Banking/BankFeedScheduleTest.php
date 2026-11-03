<?php

use App\Providers\BankFeedServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

it('schedules the bank-feed sync at every configured time', function () {
    config(['bank_feeds.sync_at' => '06:30, 12:30 ,18:30']);

    expect(BankFeedServiceProvider::syncTimes())->toBe(['06:30', '12:30', '18:30']);
});

it('ignores invalid times and falls back to 16:30 when none are valid', function () {
    config(['bank_feeds.sync_at' => '6:5,25:00,07:15,07:15']);
    expect(BankFeedServiceProvider::syncTimes())->toBe(['07:15']);

    config(['bank_feeds.sync_at' => 'nonsense']);
    expect(BankFeedServiceProvider::syncTimes())->toBe(['16:30']);

    config(['bank_feeds.sync_at' => '7:30']);
    expect(BankFeedServiceProvider::syncTimes())->toBe(['07:30']);
});

it('registers one scheduler event per time by default', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'bank-feeds:sync'));

    expect($events)->toHaveCount(count(BankFeedServiceProvider::syncTimes()))
        ->and(count(BankFeedServiceProvider::syncTimes()))->toBeGreaterThan(1);
});
