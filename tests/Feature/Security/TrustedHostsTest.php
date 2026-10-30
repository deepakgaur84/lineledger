<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| The app answers only on the hosts it is configured for
|--------------------------------------------------------------------------
| APP_URL's host, plus any listed in TRUSTED_HOSTS. Any other Host is refused
| with 400 before a page, a redirect or an email can be built on it. Laravel's
| TrustHosts middleware stands down in local and testing, so these tests run
| the app as production.
*/

beforeEach(function () {
    $this->app['env'] = 'production';
});

afterEach(function () {
    // The allowed hosts live in Symfony statics that outlive this test's app.
    Request::setTrustedHosts([]);
});

it('answers on the APP_URL host', function () {
    $this->get('http://localhost/login')->assertOk();
});

it('refuses a host it is not configured for', function () {
    $this->get('http://attacker.example/login')->assertBadRequest();
});

it('refuses a subdomain of the APP_URL host', function () {
    $this->get('http://evil.localhost/login')->assertBadRequest();
});

it('refuses an unknown host on the API too', function () {
    $this->getJson('http://attacker.example/api/v1/accounts')->assertBadRequest();
});

it('answers on the extra hosts in TRUSTED_HOSTS', function () {
    config(['app.trusted_hosts' => ['books.example.com', ' HTTPS://Alias.Example.org:8443/ledger ', '']]);

    $this->get('http://books.example.com/login')->assertOk();
    $this->get('http://alias.example.org/login')->assertOk();
    $this->get('http://attacker.example/login')->assertBadRequest();
});

it('sends no password-reset email for a refused host', function () {
    Notification::fake();
    User::factory()->create(['email' => 'owner@example.com']);

    $this->post('http://attacker.example/forgot-password', ['email' => 'owner@example.com'])->assertBadRequest();

    Notification::assertNothingSent();
});

it('does not let X-Forwarded-Host stand in for the Host, even from a trusted proxy', function () {
    config(['trustedproxy.proxies' => ['127.0.0.1']]);

    // Honoured, this header would make the host attacker.example, and a 400.
    $this->get('http://localhost/login', ['X-Forwarded-Host' => 'attacker.example'])->assertOk();
});

it('answers the health check whatever the host', function (string $url) {
    // Docker's HEALTHCHECK curls localhost:8080 inside the container, and App
    // Platform probes the container's own address; neither sends the public host.
    config(['app.url' => 'https://books.example.com']);

    $this->get($url)->assertOk();
    $this->get('http://localhost:8080/login')->assertBadRequest();
})->with([
    'Docker' => 'http://localhost:8080/up',
    'App Platform' => 'http://10.244.3.7:8080/up',
]);
