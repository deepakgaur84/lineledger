<?php

namespace App\Support\Http;

use DateInterval;
use DateTimeInterface;
use Illuminate\Routing\UrlGenerator;

/**
 * Absolute URLs on the configured origin (APP_URL), whatever host the current
 * request arrived on. route(), url() and their signed variants build on the
 * request's host, which is the triggering request whenever an email is sent
 * inline; a link that carries a secret (a password-reset token, a signed
 * verification URL, an invitation code, a portal sign-in token) must not
 * follow it to a forged host. Every link in a notification goes through here.
 */
final class CanonicalUrl
{
    public static function route(string $name, mixed $parameters = []): string
    {
        return self::generator()->route($name, $parameters);
    }

    public static function to(string $path): string
    {
        return self::generator()->to($path);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function temporarySignedRoute(string $name, DateTimeInterface|DateInterval|int $expiration, array $parameters = []): string
    {
        return self::generator()->temporarySignedRoute($name, $expiration, $parameters);
    }

    /**
     * A copy of the app's URL generator rooted at APP_URL. setRequest() drops
     * the root it cached and the route-URL builder it would otherwise share with
     * the original (keeping URL::defaults()), so the copy builds on its own root.
     */
    private static function generator(): UrlGenerator
    {
        $origin = rtrim((string) config('app.url'), '/');

        $generator = clone app('url');
        $generator->setRequest(app('request'));
        $generator->useOrigin($origin);

        $scheme = parse_url($origin, PHP_URL_SCHEME);

        if (is_string($scheme)) {
            $generator->forceScheme($scheme);
        }

        return $generator;
    }
}
