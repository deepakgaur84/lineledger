<?php

use App\Models\Company;
use App\Models\User;
use App\Support\MailSettings;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Cache;

/*
 * The site-admin portal checks site_admin when a page loads (EnsureSiteAdmin,
 * then each page's mount()). Livewire's update endpoint skips that route
 * middleware, so these tests replay a captured admin snapshot through the real
 * endpoint after the user stops being a site admin (or turns 2FA off) and prove
 * the update is refused and changes nothing. A current site admin, who is
 * usually not a member of the companies they manage, keeps working.
 */

beforeEach(function () {
    Cache::forget('site_settings');

    $this->admin = User::factory()->siteAdmin()->withTwoFactor()->create();
    $this->passwordConfirmed = ['auth.password_confirmed_at' => time()];

    $this->mailForm = [
        'host' => 'smtp.replay.invalid',
        'port' => '2525',
        'fromAddress' => 'replay@replay.invalid',
        'fromName' => 'Replay',
    ];
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('refuses mail-settings writes from a user whose site-admin role was revoked', function () {
    $snapshot = livewireReplayCapture($this->admin, route('admin.mail'), 'pages::admin.mail', $this->passwordConfirmed);

    $this->admin->forceFill(['site_admin' => false])->save();

    $before = MailSettings::saved();

    livewireReplay($snapshot, [['save']], $this->mailForm)->assertNotFound();

    Cache::forget('site_settings');

    expect(MailSettings::saved())->toBe($before);
});

it('refuses the maintenance-mode toggle from a user whose site-admin role was revoked', function () {
    $snapshot = livewireReplayCapture($this->admin, route('admin.settings'), 'pages::admin.settings', $this->passwordConfirmed);

    $this->admin->forceFill(['site_admin' => false])->save();

    livewireReplay($snapshot, updates: ['maintenanceMode' => true])->assertNotFound();

    Cache::forget('site_settings');

    expect(SiteSettings::maintenanceMode())->toBeFalse();
});

it('sends a site admin who turned 2FA off to re-enrol before an admin update runs', function () {
    $snapshot = livewireReplayCapture($this->admin, route('admin.settings'), 'pages::admin.settings', $this->passwordConfirmed);

    $this->admin->forceFill([
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ])->save();

    livewireReplay($snapshot, updates: ['maintenanceMode' => true])->assertRedirect(route('security.edit'));

    Cache::forget('site_settings');

    expect(SiteSettings::maintenanceMode())->toBeFalse();
});

it('refuses the company page to a user whose site-admin role was revoked', function () {
    $company = Company::factory()->create();

    $snapshot = livewireReplayCapture($this->admin, route('admin.companies.show', $company), 'pages::admin.company-show', $this->passwordConfirmed);

    $this->admin->forceFill(['site_admin' => false])->save();

    livewireReplay($snapshot, [['togglePayrollOverride']])->assertNotFound();

    expect($company->fresh()->payroll_admin_enabled_at)->toBeNull();
});

it('keeps the mail settings working for a current site admin', function () {
    $snapshot = livewireReplayCapture($this->admin, route('admin.mail'), 'pages::admin.mail', $this->passwordConfirmed);

    livewireReplay($snapshot, [['save']], $this->mailForm)->assertOk();

    Cache::forget('site_settings');

    expect(MailSettings::saved()['host'] ?? null)->toBe('smtp.replay.invalid');
});

it('lets a site admin drive the page of a company they are not a member of', function () {
    // The company page mounts the company it shows, so updates bind it as the
    // current company even though the admin has no membership there.
    $company = Company::factory()->create();

    expect($this->admin->belongsToCompany($company))->toBeFalse();

    $snapshot = livewireReplayCapture($this->admin, route('admin.companies.show', $company), 'pages::admin.company-show', $this->passwordConfirmed);

    livewireReplay($snapshot, [['togglePayrollOverride']])->assertOk();

    expect($company->fresh()->payroll_admin_enabled_at)->not->toBeNull();
});
