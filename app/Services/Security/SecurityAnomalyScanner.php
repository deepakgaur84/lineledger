<?php

namespace App\Services\Security;

use App\Console\Commands\MonitorSecurityEvents;
use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\SecurityLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Detects anomalies in the immutable security log over a window. Shared by the
 * scheduled {@see MonitorSecurityEvents} (which alerts ops)
 * and the site-admin security dashboard (which shows the same findings live), so
 * the two surfaces can't drift and the thresholds live in one place.
 *
 * Detectors: failed-login spike per IP and per attempted email address, account
 * lockout, mass API-key revocation, and privilege escalation via a role change
 * that raised a member's level.
 */
class SecurityAnomalyScanner
{
    /**
     * @return list<string>
     */
    public function scan(CarbonInterface $since): array
    {
        return [
            ...$this->failedLoginSpikes($since),
            ...$this->failedLoginSpikesByEmail($since),
            ...$this->lockouts($since),
            ...$this->apiKeyRevocationBursts($since),
            ...$this->privilegeEscalations($since),
        ];
    }

    /**
     * @return list<string>
     */
    public function failedLoginSpikes(CarbonInterface $since): array
    {
        $threshold = (int) config('services.security_alerts.failed_login_threshold', 10);

        return SecurityLog::query()
            ->selectRaw('ip_address, COUNT(*) as hits')
            ->where('event', SecurityEvent::LoginFailed->value)
            ->where('recorded_at', '>=', $since)
            ->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->get()
            ->map(function (SecurityLog $row) {
                $hits = (int) $row->getAttribute('hits');

                return "Failed-login spike from {$row->ip_address}: {$hits} attempts in window.";
            })
            ->all();
    }

    /**
     * Failed logins grouped by the address they tried, so guessing at one
     * account spread across many IPs (each one under the per-IP threshold above)
     * still adds up to an alert. Addresses are compared case-insensitively.
     *
     * @return list<string>
     */
    public function failedLoginSpikesByEmail(CarbonInterface $since): array
    {
        $threshold = (int) config('services.security_alerts.failed_login_threshold', 10);

        return SecurityLog::query()
            ->selectRaw('LOWER(attempted_email) as email, COUNT(*) as hits, COUNT(DISTINCT ip_address) as ips')
            ->where('event', SecurityEvent::LoginFailed->value)
            ->where('recorded_at', '>=', $since)
            ->whereNotNull('attempted_email')
            ->groupByRaw('LOWER(attempted_email)')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->orderBy('email')
            ->get()
            ->map(function (SecurityLog $row) {
                $email = $this->displayableEmail((string) $row->getAttribute('email'));
                $hits = (int) $row->getAttribute('hits');
                $ips = (int) $row->getAttribute('ips');

                return sprintf(
                    'Failed-login spike for %s: %d attempts from %d IP address%s in window.',
                    $email,
                    $hits,
                    $ips,
                    $ips === 1 ? '' : 'es',
                );
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function lockouts(CarbonInterface $since): array
    {
        return SecurityLog::query()
            ->where('event', SecurityEvent::LoginLockout->value)
            ->where('recorded_at', '>=', $since)
            ->get()
            ->map(function (SecurityLog $row) {
                $who = $row->attempted_email !== null
                    ? $this->displayableEmail($row->attempted_email)
                    : 'user '.($row->user_id ?? '?');
                $from = $row->ip_address ? " from {$row->ip_address}" : '';

                return "Account lockout for {$who}{$from}.";
            })
            ->all();
    }

    /**
     * @return list<string>
     */
    public function apiKeyRevocationBursts(CarbonInterface $since): array
    {
        $threshold = (int) config('services.security_alerts.api_key_revocation_threshold', 5);

        $count = SecurityLog::query()
            ->where('event', SecurityEvent::ApiKeyRevoked->value)
            ->where('recorded_at', '>=', $since)
            ->count();

        return $count >= $threshold
            ? ["Mass API-key revocation: {$count} keys revoked in window."]
            : [];
    }

    /**
     * @return list<string>
     */
    public function privilegeEscalations(CarbonInterface $since): array
    {
        return SecurityLog::query()
            ->where('event', SecurityEvent::CompanyMemberRoleChanged->value)
            ->where('recorded_at', '>=', $since)
            ->get()
            ->filter(function (SecurityLog $row) {
                $from = $this->roleLevel($row->metadata['from_role'] ?? null);
                $to = $this->roleLevel($row->metadata['to_role'] ?? null);

                return $from !== null && $to !== null && $to > $from;
            })
            ->map(fn (SecurityLog $row) => sprintf(
                'Privilege escalation: user %s %s → %s (company %s).',
                $row->metadata['target_user_id'] ?? '?',
                $row->metadata['from_role'] ?? '?',
                $row->metadata['to_role'] ?? '?',
                $row->metadata['company_id'] ?? '?',
            ))
            ->values()
            ->all();
    }

    protected function roleLevel(mixed $role): ?int
    {
        return is_string($role) ? CompanyRole::tryFrom($role)?->level() : null;
    }

    /**
     * An attempted address is whatever the sign-in form was sent, and the
     * findings are emailed as Markdown, so anything outside the characters of an
     * ordinary address becomes "?" (no links, no formatting) and length is capped.
     */
    protected function displayableEmail(string $email): string
    {
        return Str::limit(preg_replace('/[^A-Za-z0-9.@+_\-]/', '?', $email) ?? '', 120);
    }
}
