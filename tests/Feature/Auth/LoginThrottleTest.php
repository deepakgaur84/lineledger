<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Sign-in throttle: per client, and per account
|--------------------------------------------------------------------------
| POST /login allows five attempts a minute for one email address from one
| client IP, and ten a minute for one email address from anywhere. The second
| bucket caps guessing that is spread across many IPs (a botnet, or forwarded
| addresses from a misconfigured proxy), which the first can't see.
*/

beforeEach(function () {
    User::factory()->create(['email' => 'owner@example.com']);
});

it('throttles one address from one client after five attempts', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
    }

    $this->post('/login', ['email' => 'owner@example.com', 'password' => 'wrong-password'])
        ->assertTooManyRequests();
});

it('throttles one address after ten attempts, whatever IPs they come from', function () {
    foreach (range(1, 10) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])
            ->post('/login', ['email' => 'owner@example.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
    }

    // A new IP, and the address in another case: still the same account.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
        ->post('/login', ['email' => 'Owner@Example.com', 'password' => 'wrong-password'])
        ->assertTooManyRequests();
});

it('keeps one account at its limit from throttling another', function () {
    User::factory()->create(['email' => 'colleague@example.com']);

    foreach (range(1, 10) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])
            ->post('/login', ['email' => 'owner@example.com', 'password' => 'wrong-password']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->post('/login', ['email' => 'colleague@example.com', 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');
});

it('throttles a malformed sign-in without failing on it', function () {
    $this->post('/login', ['email' => ['owner@example.com'], 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');
});
