<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\EnsureCompanyMembership;
use App\Http\Middleware\EnsurePortalAudience;
use App\Http\Middleware\EnsureSectionAccess;
use App\Http\Middleware\EnsureSectionEnabled;
use App\Http\Middleware\EnsureSiteAdmin;
use App\Http\Middleware\ResolvePortalCompany;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Security\AccessRevoker;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Features;
use Livewire\Livewire;

/*
 * Livewire's update endpoint does not run a page's route middleware, and a
 * page's component snapshots are checksummed but not tied to a session: whoever
 * holds one can post it back long after the page loaded, including a member who
 * has since been removed or narrowed and simply signs in again as themselves.
 *
 * These tests capture real snapshots and replay them through the real endpoint
 * (Livewire::test() switches route middleware off) to prove every update is
 * re-authorized the way the page load was: membership, minimum role, section,
 * the section kill switch and the company's 2FA policy. They also prove the
 * company a component carries must still be the user's, even on pages with no
 * tenant gate, and that the flows that legitimately cross companies (the
 * portals, the setup wizard) keep working.
 */

beforeEach(function () {
    $this->company = Company::factory()->create();

    $this->owner = User::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);

    $this->member = User::factory()->create();
    $this->company->members()->attach($this->member, ['role' => CompanyRole::Accountant->value]);

    app()->instance('current_company', $this->company);
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->customer = Contact::create(['display_name' => 'Quillfeather Holdings', 'email' => 'quill@example.test', 'is_customer' => true]);
    app()->forgetInstance('current_company');

    $this->accountsUrl = route('accounts.index', ['company' => $this->company->slug]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('re-runs the tenant, portal and site-admin gates on every Livewire update', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(
        EnsureCompanyMembership::class,
        EnsureSectionEnabled::class,
        EnsureSectionAccess::class,
        EnforceTwoFactor::class,
        ResolvePortalCompany::class,
        EnsurePortalAudience::class,
        EnsureSiteAdmin::class,
    );
});

it('refuses a removed member who replays a captured snapshot to change the company', function () {
    $snapshot = livewireReplayCapture($this->member, $this->accountsUrl, 'pages::accounts.index');

    $this->company->members()->detach($this->member->id);
    app(AccessRevoker::class)->revokeForRemoval($this->member, $this->company);

    // Signed in again as themselves, the ex-member is refused the page...
    $this->actingAs($this->member->fresh())->get($this->accountsUrl)->assertForbidden();
    livewireReplayEndRequest();

    // ...and the snapshot they kept.
    $wasActive = $this->bank->fresh()->is_active;

    livewireReplay($snapshot, [['toggleActive', $this->bank->id]])->assertForbidden();

    expect($this->bank->fresh()->is_active)->toBe($wasActive);
});

it('refuses a removed member who replays a captured snapshot to re-read the company', function () {
    $snapshot = livewireReplayCapture($this->member, $this->accountsUrl, 'pages::accounts.index');

    $this->company->members()->detach($this->member->id);
    app(AccessRevoker::class)->revokeForRemoval($this->member, $this->company);
    $this->actingAs($this->member->fresh());

    livewireReplay($snapshot, [['$refresh']])
        ->assertForbidden()
        ->assertDontSee($this->bank->name);
});

it('refuses a member whose sections were narrowed when they replay a page they lost', function () {
    $clerk = User::factory()->create();
    $this->company->members()->attach($clerk, [
        'role' => CompanyRole::Custom->value,
        'sections' => [Section::Accounting->value, Section::Customers->value],
    ]);

    $snapshot = livewireReplayCapture($clerk, $this->accountsUrl, 'pages::accounts.index');

    // Narrowed to Customers only; the real downgrade path also drops their sessions.
    $this->company->memberships()->where('user_id', $clerk->id)->update(['sections' => json_encode([Section::Customers->value])]);
    app(AccessRevoker::class)->revokeForDowngrade($clerk, $this->company);
    $this->actingAs($clerk->fresh());

    $wasActive = $this->bank->fresh()->is_active;

    livewireReplay($snapshot, [['toggleActive', $this->bank->id]])->assertForbidden();

    expect($this->bank->fresh()->is_active)->toBe($wasActive);
});

it('refuses a snapshot whose page URL now names a different company', function () {
    // The page's gates authorize the company in its URL. A rename changes a
    // company's slug and another company can take the old one: a snapshot from
    // the old URL must not borrow that company's authorization.
    $renamed = Company::factory()->create(['name' => 'Harbour Lights']);
    $clerk = User::factory()->create();
    $renamed->members()->attach($clerk, [
        'role' => CompanyRole::Custom->value,
        'sections' => [Section::Accounting->value],
    ]);

    app()->instance('current_company', $renamed);
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    app()->forgetInstance('current_company');

    $oldSlug = $renamed->slug;
    $snapshot = livewireReplayCapture($clerk, route('accounts.index', ['company' => $oldSlug]), 'pages::accounts.index');

    $renamed->update(['name' => 'Lantern Works']);
    $successor = Company::factory()->create(['name' => 'Harbour Lights']);
    $successor->members()->attach($clerk, ['role' => CompanyRole::Owner->value]);

    expect($successor->slug)->toBe($oldSlug);

    // Narrowed where the snapshot came from; still an owner of the successor.
    $renamed->memberships()->where('user_id', $clerk->id)->update(['sections' => json_encode([Section::Customers->value])]);

    $wasActive = $bank->fresh()->is_active;

    livewireReplay($snapshot, [['toggleActive', $bank->id]])->assertForbidden();

    expect($bank->fresh()->is_active)->toBe($wasActive);
});

it('refuses an owner downgraded to admin who replays an owner-only page', function () {
    $url = route('opening-balances.index', ['company' => $this->company->slug]);
    $snapshot = livewireReplayCapture($this->owner, $url, 'pages::opening-balances.index');

    $this->company->memberships()->where('user_id', $this->owner->id)->update(['role' => CompanyRole::Admin->value]);
    app(AccessRevoker::class)->revokeForDowngrade($this->owner, $this->company);
    $this->actingAs($this->owner->fresh());

    livewireReplay($snapshot, [['$refresh']])->assertForbidden();
});

it('refuses a replay once the page’s section is switched off for the platform', function () {
    $snapshot = livewireReplayCapture($this->member, $this->accountsUrl, 'pages::accounts.index');

    SiteSettings::set('disabled_sections', [Section::Accounting->value]);

    $wasActive = $this->bank->fresh()->is_active;

    livewireReplay($snapshot, [['toggleActive', $this->bank->id]])->assertNotFound();

    expect($this->bank->fresh()->is_active)->toBe($wasActive);
});

it('sends an owner without 2FA to enrol once the company starts requiring it', function () {
    if (! Features::enabled(Features::twoFactorAuthentication())) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    $snapshot = livewireReplayCapture($this->owner, $this->accountsUrl, 'pages::accounts.index');

    $this->company->forceFill(['require_two_factor' => true])->save();

    $wasActive = $this->bank->fresh()->is_active;

    livewireReplay($snapshot, [['toggleActive', $this->bank->id]])->assertRedirect(route('security.edit'));

    expect($this->bank->fresh()->is_active)->toBe($wasActive);
});

it('still signs out a disabled account that replays a snapshot', function () {
    $snapshot = livewireReplayCapture($this->member, $this->accountsUrl, 'pages::accounts.index');

    $this->member->forceFill(['disabled_at' => now()])->save();

    $wasActive = $this->bank->fresh()->is_active;

    livewireReplay($snapshot, [['toggleActive', $this->bank->id]])->assertRedirect(route('login'));

    expect($this->bank->fresh()->is_active)->toBe($wasActive);
});

it('refuses a removed member who replays the layout search from a page with no tenant gate', function () {
    // Settings pages sit outside the {company} prefix; the layout's search there
    // carries the user's current company, and nothing on the route checks it.
    $this->member->switchCompany($this->company);

    $snapshot = livewireReplayCapture($this->member, route('profile.edit'), 'global-search');

    $this->company->members()->detach($this->member->id);
    app(AccessRevoker::class)->revokeForRemoval($this->member, $this->company);
    $this->actingAs($this->member->fresh());

    livewireReplay($snapshot, updates: ['query' => 'Quillfeather'])
        ->assertForbidden()
        ->assertDontSee('Quillfeather Holdings');
});

it('keeps working for a member who still has access', function () {
    $snapshot = livewireReplayCapture($this->member, $this->accountsUrl, 'pages::accounts.index');

    $wasActive = $this->bank->fresh()->is_active;

    livewireReplay($snapshot, [['toggleActive', $this->bank->id]])->assertOk();

    expect($this->bank->fresh()->is_active)->toBe(! $wasActive);
});

it('keeps the layout search working for a member on a page with no tenant gate', function () {
    $this->member->switchCompany($this->company);

    $snapshot = livewireReplayCapture($this->member, route('profile.edit'), 'global-search');

    livewireReplay($snapshot, updates: ['query' => 'Quillfeather'])
        ->assertOk()
        ->assertSee('Quillfeather Holdings');
});

it('leaves the most-recently-used company to page loads, not background updates', function () {
    $other = Company::factory()->create();
    $other->members()->attach($this->member, ['role' => CompanyRole::Owner->value]);

    $snapshot = livewireReplayCapture($this->member, $this->accountsUrl, 'pages::accounts.index');

    // A later page load in another tab makes $other the current company...
    $this->get(route('dashboard', ['company' => $other->slug]))->assertOk();
    livewireReplayEndRequest();

    // ...and an action back in the first tab leaves it that way.
    livewireReplay($snapshot, [['toggleActive', $this->bank->id]])->assertOk();

    expect($this->member->fresh()->current_company_id)->toBe($other->id);
});

it('keeps the customer portal working, and re-checks the portal audience on each update', function () {
    $html = $this->withSession([Auth::guard('customer')->getName() => $this->customer->id])
        ->get(route('portal.dashboard', ['company' => $this->company->slug]))
        ->assertOk()
        ->getContent();
    livewireReplayEndRequest();

    $snapshot = livewireReplaySnapshotIn($html, 'pages::portal.dashboard');

    // Each request resolves the portal contact from the session afresh.
    Auth::forgetGuards();
    livewireReplay($snapshot, [['$refresh']])->assertOk();

    $this->customer->forceFill(['is_customer' => false])->save();

    Auth::forgetGuards();
    livewireReplay($snapshot, [['$refresh']])->assertForbidden();
});

it('keeps the setup wizard and the importer it embeds working, for the importer’s company members only', function () {
    $founder = User::factory()->create();

    $wizard = livewireReplayCapture($founder, route('companies.setup'), 'pages::welcome.setup-wizard');

    // Country first: picking one resets the region, as it does in the browser.
    $wizard = livewireReplay($wizard, updates: ['country' => 'CA'])->assertOk()->json('components.0.snapshot');

    $response = livewireReplay($wizard, [['beginImport']], [
        'companyName' => 'Founder Imports',
        'region' => 'BC',
        'startMode' => 'import',
        'importMode' => 'opening_balance',
    ])->assertOk();

    $imported = Company::query()->where('name', 'Founder Imports')->firstOrFail();

    // The importer carries the new company on a page with no tenant gate.
    $importer = livewireReplaySnapshotIn((string) $response->json('components.0.effects.html'), 'pages::migration.import');

    livewireReplay($importer, [['$refresh']])->assertOk();

    // Removed by a co-owner, the founder can no longer drive it.
    $coOwner = User::factory()->create();
    $imported->members()->attach($coOwner, ['role' => CompanyRole::Owner->value]);
    $imported->members()->detach($founder->id);
    app(AccessRevoker::class)->revokeForRemoval($founder, $imported);
    $this->actingAs($founder->fresh());

    livewireReplay($importer, [['$refresh']])->assertForbidden();
});
