<?php

use App\Enums\SecurityEvent;
use App\Models\SecurityLog;
use App\Notifications\SecurityAnomalyAlert;
use App\Services\Security\SecurityAnomalyScanner;
use Illuminate\Support\Facades\Notification;

/**
 * security:monitor scans the immutable security log for anomalies over a recent
 * window and alerts ops (SOC 2 CC7.2/CC7.3). It must stay quiet on normal
 * activity and fail loudly (non-zero exit + alert) on a spike, lockout, mass
 * key revocation, or privilege escalation.
 */
beforeEach(function () {
    config([
        'services.security_alerts.failed_login_threshold' => 3,
        'services.security_alerts.api_key_revocation_threshold' => 2,
        'services.security_alerts.window_minutes' => 60,
    ]);
});

function logEvent(SecurityEvent $event, array $attrs = []): SecurityLog
{
    return SecurityLog::create(array_merge([
        'recorded_at' => now(),
        'event' => $event,
    ], $attrs));
}

it('passes when there are no anomalies', function () {
    logEvent(SecurityEvent::LoginSucceeded, ['ip_address' => '203.0.113.1']);

    $this->artisan('security:monitor')->assertExitCode(0);
});

it('flags and alerts on a failed-login spike from one IP', function () {
    Notification::fake();

    foreach (range(1, 3) as $i) {
        logEvent(SecurityEvent::LoginFailed, ['ip_address' => '203.0.113.9', 'attempted_email' => 'a@example.com']);
    }

    $this->artisan('security:monitor')->assertExitCode(1);

    Notification::assertSentOnDemand(SecurityAnomalyAlert::class);
});

it('does not flag failed logins below the threshold', function () {
    logEvent(SecurityEvent::LoginFailed, ['ip_address' => '203.0.113.9']);
    logEvent(SecurityEvent::LoginFailed, ['ip_address' => '203.0.113.9']);

    $this->artisan('security:monitor', ['--no-alert' => true])->assertExitCode(0);
});

it('flags an account lockout', function () {
    logEvent(SecurityEvent::LoginLockout, ['attempted_email' => 'b@example.com', 'ip_address' => '203.0.113.4']);

    $this->artisan('security:monitor', ['--no-alert' => true])->assertExitCode(1);
});

it('flags a mass API-key revocation burst', function () {
    logEvent(SecurityEvent::ApiKeyRevoked);
    logEvent(SecurityEvent::ApiKeyRevoked);

    $this->artisan('security:monitor', ['--no-alert' => true])->assertExitCode(1);
});

it('flags a privilege escalation', function () {
    logEvent(SecurityEvent::CompanyMemberRoleChanged, [
        'metadata' => ['target_user_id' => 5, 'company_id' => 1, 'from_role' => 'accountant', 'to_role' => 'admin'],
    ]);

    $this->artisan('security:monitor', ['--no-alert' => true])->assertExitCode(1);
});

it('ignores a role downgrade', function () {
    logEvent(SecurityEvent::CompanyMemberRoleChanged, [
        'metadata' => ['target_user_id' => 5, 'company_id' => 1, 'from_role' => 'admin', 'to_role' => 'accountant'],
    ]);

    $this->artisan('security:monitor', ['--no-alert' => true])->assertExitCode(0);
});

it('ignores events outside the window', function () {
    foreach (range(1, 3) as $i) {
        logEvent(SecurityEvent::LoginFailed, ['ip_address' => '203.0.113.9', 'recorded_at' => now()->subMinutes(120)]);
    }

    $this->artisan('security:monitor', ['--no-alert' => true])->assertExitCode(0);
});

it('does not email when --no-alert is set', function () {
    Notification::fake();

    logEvent(SecurityEvent::LoginLockout, ['attempted_email' => 'c@example.com']);

    $this->artisan('security:monitor', ['--no-alert' => true])->assertExitCode(1);

    Notification::assertNothingSent();
});

it('flags failed logins against one account spread across many IPs', function () {
    Notification::fake();

    foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
        logEvent(SecurityEvent::LoginFailed, ['ip_address' => $ip, 'attempted_email' => 'victim@example.com']);
    }

    $this->artisan('security:monitor')->assertExitCode(1);

    Notification::assertSentOnDemand(SecurityAnomalyAlert::class, fn (SecurityAnomalyAlert $alert): bool => $alert->anomalies === [
        'Failed-login spike for victim@example.com: 3 attempts from 3 IP addresses in window.',
    ]);
});

it('groups an account\'s failed logins whatever the case of the address', function () {
    foreach (['Victim@Example.com', 'victim@example.com', 'VICTIM@EXAMPLE.COM'] as $i => $email) {
        logEvent(SecurityEvent::LoginFailed, ['ip_address' => "203.0.113.{$i}", 'attempted_email' => $email]);
    }

    expect(app(SecurityAnomalyScanner::class)->failedLoginSpikesByEmail(now()->subHour()))
        ->toBe(['Failed-login spike for victim@example.com: 3 attempts from 3 IP addresses in window.']);
});

it('does not let an attempted address put a link or formatting in the alert', function () {
    foreach (range(1, 3) as $i) {
        logEvent(SecurityEvent::LoginFailed, ['ip_address' => "203.0.113.{$i}", 'attempted_email' => '[Reset your password](https://evil.example/reset) **now**']);
    }

    $findings = app(SecurityAnomalyScanner::class)->failedLoginSpikesByEmail(now()->subHour());

    expect($findings)->toHaveCount(1)
        ->and($findings[0])->not->toContain('[')
        ->not->toContain('](')
        ->not->toContain('https://')
        ->not->toContain('**');
});
