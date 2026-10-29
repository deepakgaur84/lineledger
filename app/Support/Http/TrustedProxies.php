<?php

namespace App\Support\Http;

/**
 * Reads TRUSTED_PROXIES into the shape Laravel's TrustProxies middleware takes
 * from config('trustedproxy.proxies'): '*' to trust whatever connects, or a list
 * of IPs, CIDR ranges and Symfony's REMOTE_ADDR / PRIVATE_SUBNETS keywords.
 *
 * Unset or empty means an empty list, which trusts no one. (Null would let
 * Laravel trust every peer whenever the request's own Host header ends in
 * .on-forge.com or .on-vapor.com.)
 */
final class TrustedProxies
{
    /**
     * @return list<string>|'*'
     */
    public static function fromEnv(mixed $value): array|string
    {
        $value = trim(is_string($value) ? $value : '');

        if ($value === '*' || $value === '**') {
            return '*';
        }

        $proxies = array_map(trim(...), explode(',', $value));

        return array_values(array_filter($proxies, fn (string $proxy): bool => $proxy !== ''));
    }
}
