<?php

use App\Enums\SecurityEvent;
use App\Models\SecurityLog;
use App\Support\Http\TrustedProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Only configured proxies may say who the client is
|--------------------------------------------------------------------------
| TRUSTED_PROXIES (config/trustedproxy.php) names the balancers and reverse
| proxies whose X-Forwarded-For, -Proto and -Port headers count. With none, the
| connecting peer is the client, so a client of a directly exposed install can't
| choose the IP that the sign-in throttle, the anomaly scanner and the security
| log see. X-Forwarded-Host never counts, from anyone.
*/

beforeEach(function () {
    Route::get('/__proxy-probe', fn (Request $request): array => [
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
        'host' => $request->getHost(),
    ]);
});

it('ignores forwarding headers when no proxy is configured', function () {
    config(['trustedproxy.proxies' => []]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->get('/__proxy-probe', [
            'X-Forwarded-For' => '198.51.100.66',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'attacker.example',
        ])
        ->assertExactJson(['ip' => '203.0.113.10', 'secure' => false, 'host' => 'localhost']);
});

it('takes the client and scheme from a configured proxy', function (array|string $proxies) {
    config(['trustedproxy.proxies' => $proxies]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->get('/__proxy-probe', ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
        ->assertExactJson(['ip' => '203.0.113.7', 'secure' => true, 'host' => 'localhost']);
})->with([
    'by address' => [['10.0.0.5']],
    'by range' => [['10.0.0.0/8']],
    'as a private subnet' => [['PRIVATE_SUBNETS']],
    'as every peer' => ['*'],
]);

it('keeps the address the proxy saw, not one the client wrote before it', function () {
    config(['trustedproxy.proxies' => ['10.0.0.5']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->get('/__proxy-probe', ['X-Forwarded-For' => '198.51.100.1, 203.0.113.7'])
        ->assertJson(['ip' => '203.0.113.7']);
});

it('ignores forwarding headers from a peer that is not the proxy', function () {
    config(['trustedproxy.proxies' => ['10.0.0.5']]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('/__proxy-probe', ['X-Forwarded-For' => '198.51.100.1', 'X-Forwarded-Proto' => 'https'])
        ->assertExactJson(['ip' => '203.0.113.9', 'secure' => false, 'host' => 'localhost']);
});

it('never takes the host from X-Forwarded-Host, even from the proxy', function () {
    config(['trustedproxy.proxies' => ['10.0.0.5']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->get('/__proxy-probe', ['X-Forwarded-Host' => 'attacker.example'])
        ->assertJson(['host' => 'localhost']);
});

it('logs a failed sign-in against the client the proxy settings allow', function (array $proxies, string $loggedIp) {
    config(['trustedproxy.proxies' => $proxies]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->post('/login', ['email' => 'someone@example.com', 'password' => 'wrong-password'], ['X-Forwarded-For' => '198.51.100.66'])
        ->assertSessionHasErrors('email');

    expect(SecurityLog::query()->where('event', SecurityEvent::LoginFailed)->sole()->ip_address)->toBe($loggedIp);
})->with([
    'no proxy: the peer, whatever it claims' => [[], '203.0.113.10'],
    'the peer is the proxy: the client it forwards' => [['203.0.113.10'], '198.51.100.66'],
]);

it('reads TRUSTED_PROXIES as a list, a wildcard, or nothing', function (?string $value, array|string $expected) {
    expect(TrustedProxies::fromEnv($value))->toBe($expected);
})->with([
    'unset' => [null, []],
    'empty' => ['', []],
    'one address' => ['10.0.0.5', ['10.0.0.5']],
    'a list, loosely typed' => [' 10.0.0.5 , 10.1.0.0/16 ,, ', ['10.0.0.5', '10.1.0.0/16']],
    'every peer' => [' * ', '*'],
    "Symfony's keywords" => ['REMOTE_ADDR,PRIVATE_SUBNETS', ['REMOTE_ADDR', 'PRIVATE_SUBNETS']],
]);

it('feeds TRUSTED_PROXIES to the TrustProxies middleware', function () {
    // An empty list, never null: null would let Laravel trust every peer of a
    // request whose own Host header claims an .on-forge.com address.
    expect(config('trustedproxy.proxies'))->toBe(TrustedProxies::fromEnv(env('TRUSTED_PROXIES')));

    config(['trustedproxy.proxies' => TrustedProxies::fromEnv(null)]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->get('http://books.on-forge.com/__proxy-probe', ['X-Forwarded-For' => '198.51.100.66'])
        ->assertJson(['ip' => '203.0.113.10']);
});
