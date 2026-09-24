<?php

use App\Models\User;
use Livewire\Livewire;

test('new users have escape goes back switched on', function () {
    expect(User::factory()->create()->escape_goes_back)->toBeTrue();
});

test('the appearance page shows the switch in its saved state', function () {
    $user = User::factory()->create(['escape_goes_back' => false]);

    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->assertSet('escapeGoesBack', false)
        ->assertSeeHtml('data-test="escape-goes-back-switch"');
});

test('switching escape goes back off persists it and tells the page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('escapeGoesBack', false)
        ->assertDispatched('escape-back-changed', enabled: false);

    expect($user->refresh()->escape_goes_back)->toBeFalse();
});

test('switching escape goes back on again persists it', function () {
    $user = User::factory()->create(['escape_goes_back' => false]);

    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('escapeGoesBack', true)
        ->assertDispatched('escape-back-changed', enabled: true);

    expect($user->refresh()->escape_goes_back)->toBeTrue();
});

test('the app layout turns escape goes back on for users who have it on', function () {
    $this->actingAs(User::factory()->create());

    $body = escapeBackBodyTag($this->get(route('appearance.edit'))->assertOk()->getContent());

    expect($body)
        ->toMatch('/\sdata-escape-back[\s>]/')
        ->toContain('data-escape-back-confirm=');
});

test('the app layout leaves escape goes back off for users who switched it off', function () {
    $this->actingAs(User::factory()->create(['escape_goes_back' => false]));

    $body = escapeBackBodyTag($this->get(route('appearance.edit'))->assertOk()->getContent());

    expect($body)
        ->not->toMatch('/\sdata-escape-back[\s>]/')
        ->toContain('data-escape-back-confirm=');
});
