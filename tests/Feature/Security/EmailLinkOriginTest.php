<?php

use App\Actions\Portal\RequestEmployeePortalLoginLink;
use App\Actions\Portal\RequestPortalLoginLink;
use App\Enums\PayBasis;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Notifications\Companies\CompanyInvitation as CompanyInvitationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Links in email are built on APP_URL, never on the request's host
|--------------------------------------------------------------------------
| A password-reset token, a signed verification URL, an invitation code and a
| portal sign-in token all travel as links. Each one is built on the configured
| origin (APP_URL: http://localhost in phpunit.xml), so a forged Host or
| X-Forwarded-Host on the request that sent it can't point it at another site.
| Outside local and testing, TrustHosts refuses unknown hosts before any of this
| runs (TrustedHostsTest); these tests cover the email side on its own.
*/

afterEach(function () {
    // Symfony keeps proxy trust in statics that outlive this test's app.
    Request::setTrustedProxies([], -1);
});

// Requests through the HTTP kernel: [origin, headers, whether the test client's
// address (127.0.0.1) is a configured trusted proxy].
$forgedHttpRequests = [
    'a forged Host' => ['http://attacker.example', [], false],
    'a forged X-Forwarded-Host' => ['http://localhost', ['X-Forwarded-Host' => 'attacker.example'], false],
    'a forged X-Forwarded-Host through a trusted proxy' => ['http://localhost', ['X-Forwarded-Host' => 'attacker.example'], true],
];

// The current request, for code that sends mail outside a plain HTTP request (a
// Livewire action, a queued job running inline). The second case is the worst
// one: a proxy trusted with X-Forwarded-Host, which the app never configures.
$forgedCurrentRequests = [
    'a forged Host' => fn () => Request::create('http://attacker.example/'),
    'a forged X-Forwarded-Host that a proxy vouches for' => function () {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_HOST);

        return Request::create('http://localhost/', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
        ]);
    },
];

it('builds the password-reset link on APP_URL', function (string $origin, array $headers, bool $viaProxy) {
    config(['trustedproxy.proxies' => $viaProxy ? ['127.0.0.1'] : []]);
    User::factory()->create(['email' => 'owner@example.com']);

    $this->withHeaders($headers)
        ->post($origin.'/forgot-password', ['email' => 'owner@example.com'])
        ->assertSessionHasNoErrors();

    expect(sentMailText())
        ->toContain('http://localhost/reset-password/')
        ->not->toContain('attacker.example');
})->with($forgedHttpRequests);

it('emails a password-reset link that opens the reset form', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $this->post('http://attacker.example/forgot-password', ['email' => 'owner@example.com']);

    expect(preg_match('~http://localhost/reset-password/[^\s"\'<>()\[\]]+~', sentMailText(), $link))->toBe(1);

    $this->get($link[0])->assertOk();
});

it('builds the email-verification link on APP_URL', function (string $origin, array $headers, bool $viaProxy) {
    config(['trustedproxy.proxies' => $viaProxy ? ['127.0.0.1'] : []]);
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->withHeaders($headers)
        ->post($origin.'/email/verification-notification')
        ->assertSessionHasNoErrors();

    expect(sentMailText())
        ->toContain('http://localhost/email/verify/')
        ->not->toContain('attacker.example');
})->with($forgedHttpRequests);

it('emails a verification link whose signature holds on APP_URL', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post('http://attacker.example/email/verification-notification');

    expect(preg_match('~http://localhost/email/verify/[^\s"\'<>()\[\]]+~', sentMailText(), $link))->toBe(1);

    $this->actingAs($user)->get($link[0]);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('builds the invitation link on APP_URL', function (Request $forged) {
    $invitation = CompanyInvitation::factory()->create(['email' => 'invitee@example.com']);

    $this->app->instance('request', $forged);
    expect(url('/'))->toContain('attacker.example'); // the forgery took

    Notification::route('mail', 'invitee@example.com')->notify(new CompanyInvitationNotification($invitation));

    expect(sentMailText())
        ->toContain("http://localhost/invitations/{$invitation->code}/accept")
        ->not->toContain('attacker.example');
})->with($forgedCurrentRequests);

it('builds the customer portal sign-in link on APP_URL', function (Request $forged) {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);
    Contact::create(['display_name' => 'Acme Co', 'email' => 'buyer@acme.test', 'is_customer' => true, 'is_active' => true]);

    $this->app->instance('request', $forged);
    expect(url('/'))->toContain('attacker.example');

    app(RequestPortalLoginLink::class)->handle($company, 'buyer@acme.test');

    expect(sentMailText())
        ->toContain("http://localhost/pay/{$company->slug}/login/")
        ->not->toContain('attacker.example');
})->with($forgedCurrentRequests);

it('builds the employee portal sign-in link on APP_URL', function (Request $forged) {
    $company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $company);
    $employee = Contact::create(['display_name' => 'Alice Employee', 'email' => 'alice@emp.test', 'is_employee' => true, 'is_active' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => PayrollSchedule::factory()->create()->id,
        'is_active' => true,
    ]);

    $this->app->instance('request', $forged);
    expect(url('/'))->toContain('attacker.example');

    app(RequestEmployeePortalLoginLink::class)->handle($company, 'alice@emp.test');

    expect(sentMailText())
        ->toContain("http://localhost/my-pay/{$company->slug}/login/")
        ->not->toContain('attacker.example');
})->with($forgedCurrentRequests);

it('builds every notification link with CanonicalUrl', function () {
    // route(), url() and asset() take their host from the current request, which
    // is the triggering request whenever a notification is sent inline. Links in
    // notifications and mail views go through App\Support\Http\CanonicalUrl.
    $offenders = collect([...File::allFiles(app_path('Notifications')), ...File::allFiles(resource_path('views/emails'))])
        ->filter(fn (SplFileInfo $file): bool => preg_match(
            '/(?<![\w>:$\\\\])(route|url|asset|secure_url|secure_asset|action)\s*\(/',
            (string) file_get_contents($file->getPathname()),
        ) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
