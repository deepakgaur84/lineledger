<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * The hosts this installation answers on: APP_URL's host, plus any listed in
 * TRUSTED_HOSTS (config('app.trusted_hosts')). bootstrap/app.php hands them to
 * Laravel's TrustHosts middleware, so outside local and testing a request for
 * any other host is refused with 400 before anything reads it, and no page,
 * redirect or email can be built on a forged Host.
 *
 * The sibling deployments in config('app.app_urls') are separate installations
 * with their own databases, not hosts this one serves, so they are not trusted
 * here; an installation that really does answer on several names lists them in
 * TRUSTED_HOSTS.
 */
final class TrustedHosts
{
    /**
     * The health route, which Docker's HEALTHCHECK and platform probes call by
     * the container's own address (localhost:8080, a pod IP) rather than the
     * public host. It renders nothing from the request, so it is exempt.
     */
    public const HEALTH_PATH = 'up';

    /**
     * The trusted hostnames, lowercase and without ports.
     *
     * @return list<string>
     */
    public static function hosts(): array
    {
        $configured = [config('app.url'), ...(array) config('app.trusted_hosts', [])];

        $hosts = array_filter(array_map(self::hostOf(...), $configured));

        return array_values(array_unique($hosts));
    }

    /**
     * Patterns for the TrustHosts middleware on this request: none for the
     * health check, otherwise one exact match per trusted host (no subdomains).
     *
     * @return list<string>
     */
    public static function patternsFor(?Request $request): array
    {
        if ($request?->is(self::HEALTH_PATH)) {
            return [];
        }

        return array_map(fn (string $host): string => '^'.preg_quote($host).'$', self::hosts());
    }

    /**
     * The host of a configured value, which may be a bare host, host:port, or a
     * full URL. Null when there isn't one.
     */
    private static function hostOf(mixed $value): ?string
    {
        $value = trim(is_string($value) ? $value : '');

        if ($value === '') {
            return null;
        }

        $host = parse_url(str_contains($value, '://') ? $value : 'http://'.$value, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
