<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\EditLock;
use App\Services\EditLocks\EditLockManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->jane = editLockMember($this->company, CompanyRole::Custom);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    $this->invoice = editLockDraftInvoice($this->company);
    app()->forgetInstance('current_company');

    $this->lease = app(EditLockManager::class)->acquire($this->invoice, $this->jane);
});

it('requires a signed-in user', function () {
    $this->postJson(route('edit-locks.heartbeat'), ['token' => $this->lease->token, 'active' => true])
        ->assertUnauthorized();
});

it('renews the lease for its holder, even a custom-role member with no sections', function () {
    $this->actingAs($this->jane)
        ->postJson(route('edit-locks.heartbeat'), ['token' => $this->lease->token, 'active' => true])
        ->assertOk()
        ->assertExactJson(['status' => 'held', 'version' => $this->lease->version]);
});

it('reports lost for another user\'s token and leaves the lease alone', function () {
    $before = EditLock::query()->sole()->expires_at_ms;

    $this->travel(10)->seconds();

    $this->actingAs($this->bob)
        ->postJson(route('edit-locks.heartbeat'), ['token' => $this->lease->token, 'active' => true])
        ->assertOk()
        ->assertExactJson(['status' => 'lost']);

    expect(EditLock::query()->sole()->expires_at_ms)->toBe($before);
});

it('reports lost once the holder is removed from the company', function () {
    $this->company->members()->detach($this->jane);

    $this->actingAs($this->jane)
        ->postJson(route('edit-locks.heartbeat'), ['token' => $this->lease->token, 'active' => true])
        ->assertExactJson(['status' => 'lost']);
});

it('validates the token', function () {
    $this->actingAs($this->jane)
        ->postJson(route('edit-locks.heartbeat'), ['token' => 'short'])
        ->assertUnprocessable();
});

it('releases from a form-encoded beacon, and a second release is harmless', function () {
    $this->actingAs($this->jane)
        ->post(route('edit-locks.release'), ['token' => $this->lease->token])
        ->assertNoContent();

    $this->actingAs($this->jane)
        ->post(route('edit-locks.release'), ['token' => $this->lease->token])
        ->assertNoContent();

    expect(EditLock::query()->sole()->expires_at_ms)->toBeNull();
});

it('does not let another user release someone else\'s lease', function () {
    $this->actingAs($this->bob)
        ->post(route('edit-locks.release'), ['token' => $this->lease->token])
        ->assertNoContent();

    expect(EditLock::query()->sole()->expires_at_ms)->not->toBeNull();
});

it('keeps CSRF protection on both lease routes', function () {
    foreach (['edit-locks.heartbeat', 'edit-locks.release'] as $name) {
        $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
        $resolved = app('router')->resolveMiddleware($middleware);

        expect($resolved)->toContain(PreventRequestForgery::class)
            ->and(Route::getRoutes()->getByName($name)->uri())->not->toStartWith('{company}');
    }
});

it('keeps answering heartbeats and releases while the user owes a legal acceptance', function () {
    config(['legal.documents.terms.version' => '2099-01-01']);

    $this->actingAs($this->jane)
        ->postJson(route('edit-locks.heartbeat'), ['token' => $this->lease->token, 'active' => true])
        ->assertOk()
        ->assertJsonPath('status', 'held');

    $this->actingAs($this->jane)
        ->post(route('edit-locks.release'), ['token' => $this->lease->token])
        ->assertNoContent();
});
