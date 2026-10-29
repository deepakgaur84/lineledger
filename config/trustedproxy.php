<?php

use App\Support\Http\TrustedProxies;

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | The load balancers and reverse proxies allowed to tell the app who the
    | client is and how it connected (X-Forwarded-For, -Proto and -Port). From
    | any other peer those headers are ignored and the peer itself is the
    | client, so a client can't choose the IP that the sign-in throttle, the
    | security monitor and the security log see.
    |
    | TRUSTED_PROXIES is a comma-separated list of IPs and CIDR ranges (Symfony's
    | REMOTE_ADDR and PRIVATE_SUBNETS keywords work too); the client is then the
    | last X-Forwarded-For entry that isn't one of them. * trusts every address,
    | so the first entry wins: use it only where the platform's balancer is the
    | only way in and its range isn't known, as on DigitalOcean App Platform.
    | Left empty, no proxy is trusted, which is right when the app is reached
    | directly: Forge's own nginx, the one-click Droplet, Docker publishing
    | 80/443.
    |
    | Laravel's TrustProxies middleware reads this key on every request.
    | bootstrap/app.php picks the headers it honours: never X-Forwarded-Host, so
    | a proxy in front of the app must pass the original Host header through.
    |
    */

    'proxies' => TrustedProxies::fromEnv(env('TRUSTED_PROXIES')),

];
