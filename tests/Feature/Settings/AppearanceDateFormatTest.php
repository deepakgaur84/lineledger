<?php

use App\Enums\DateFormat;
use App\Models\User;
use App\Support\DateDisplay;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('new users default to 01-Apr-26 style dates', function () {
    $user = User::factory()->create();

    expect($user->refresh()->date_format)->toBe(DateFormat::DayMonthShortYear);

    $this->actingAs($user);

    expect(Carbon::create(2026, 4, 1)->displayDate())->toBe('01-Apr-26');
});

test('every seeded format renders the sample date as advertised', function () {
    expect(DateFormat::DayMonthShortYear->example())->toBe('01-Apr-26')
        ->and(DateFormat::DayMonthYear->example())->toBe('01-Apr-2026')
        ->and(DateFormat::Iso->example())->toBe('2026-04-01')
        ->and(DateFormat::DayMonthNameYear->example())->toBe('1 April 2026')
        ->and(DateFormat::MonthDayYear->example())->toBe('Apr 1, 2026')
        ->and(DateFormat::DaySlash->example())->toBe('01/04/2026')
        ->and(DateFormat::MonthSlash->example())->toBe('04/01/2026')
        ->and(DateFormat::MonthSlashShort->example())->toBe('4/1/2026')
        ->and(DateFormat::DayDot->example())->toBe('01.04.2026');
});

test('the appearance page shows the saved date format', function () {
    $this->actingAs(User::factory()->create(['date_format' => DateFormat::Iso->value]));

    Livewire::test('pages::settings.appearance')
        ->assertSet('dateFormat', 'yyyy_mm_dd')
        ->assertSeeHtml('data-test="date-format-group"')
        ->assertSee('01-Apr-26');
});

test('choosing a date format persists it and changes how dates display', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('dateFormat', DateFormat::MonthDayYear->value)
        ->call('updateDateFormat')
        ->assertHasNoErrors();

    expect($user->refresh()->date_format)->toBe(DateFormat::MonthDayYear);

    $this->actingAs($user);

    expect(Carbon::create(2026, 4, 1)->displayDate())->toBe('Apr 1, 2026')
        ->and(DateDisplay::format('2026-04-01'))->toBe('Apr 1, 2026')
        ->and(DateDisplay::format(null, '—'))->toBe('—');
});

test('an unknown date format is rejected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('dateFormat', 'nonsense')
        ->call('updateDateFormat')
        ->assertHasErrors(['dateFormat']);

    expect($user->refresh()->date_format)->toBe(DateFormat::DayMonthShortYear);
});

test('without a signed-in user dates use the default format', function () {
    expect(Carbon::create(2026, 4, 1)->displayDate())->toBe('01-Apr-26');
});
