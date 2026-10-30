<?php

use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\CompanyRestore;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Records CompanyScope does not cover — a restore belongs to the user who
 * uploaded it, a member is a platform user — picked by a public Livewire id.
 * A client can rewrite any public property that is not #[Locked], so each id
 * is locked and still checked against its owner before use.
 */
afterEach(function () {
    app()->forgetInstance('current_company');
});

// ───────────────────────── Restore page (owned by the uploading user) ─────────────────────────

it('does not let another user point the restore page at someone else\'s restore', function () {
    Storage::fake('local');
    Storage::disk('local')->put('restores/alice-bundle.zip', 'zip-bytes');

    $alice = User::factory()->create();
    $restore = CompanyRestore::factory()->create([
        'requested_by_user_id' => $alice->id,
        'status' => CompanyRestoreStatus::Failed,
        'file_path' => 'restores/alice-bundle.zip',
        'disk' => 'local',
        'error_message' => 'SQLSTATE[23000]: alice@example.com',
    ]);

    $mallory = User::factory()->create();
    $this->actingAs($mallory);

    $page = Livewire::test('pages::companies.restore');

    expect(fn () => $page->set('restoreId', $restore->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    // Even with the lock bypassed, the row is looked up through its owner.
    $instance = $page->instance();
    $instance->restoreId = $restore->id;

    expect($instance->restore())->toBeNull();

    $instance->cancel();

    expect(CompanyRestore::query()->whereKey($restore->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists('restores/alice-bundle.zip');

    $instance->restoreId = $restore->id;
    expect(fn () => $instance->confirm())->toThrow(HttpException::class);
    expect($restore->fresh()->status)->toBe(CompanyRestoreStatus::Failed);
});

it('still lets a user cancel their own restore', function () {
    Storage::fake('local');
    Storage::disk('local')->put('restores/own-bundle.zip', 'zip-bytes');

    $user = User::factory()->create();
    $restore = CompanyRestore::factory()->create([
        'requested_by_user_id' => $user->id,
        'status' => CompanyRestoreStatus::Ready,
        'file_path' => 'restores/own-bundle.zip',
        'disk' => 'local',
    ]);
    $this->actingAs($user);

    $instance = Livewire::test('pages::companies.restore')->instance();
    $instance->restoreId = $restore->id;

    expect($instance->restore()?->id)->toBe($restore->id);

    $instance->cancel();

    expect(CompanyRestore::query()->whereKey($restore->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing('restores/own-bundle.zip');
});

// ───────────────────────── Remove-member modal ─────────────────────────

it('refuses a client-set member on the remove-member modal', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);
    $this->actingAs($owner);

    $modal = Livewire::test('pages::companies.remove-member-modal', ['company' => $company, 'memberId' => $member->id]);

    expect(fn () => $modal->set('memberId', User::factory()->create()->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('does not deprovision a user who is not a member of the organization', function () {
    config(['session.driver' => 'database']);

    // An owner of their own organization targets an arbitrary platform user.
    $owner = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $outsider = User::factory()->create(['email' => 'outsider@example.com']);
    $elsewhere = Company::factory()->create();
    $elsewhere->members()->attach($outsider, ['role' => CompanyRole::Owner->value]);

    $sessionId = Str::random(40);
    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $outsider->id,
        'ip_address' => '203.0.113.9',
        'user_agent' => 'Test',
        'payload' => 'x',
        'last_activity' => 1_700_000_000,
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.remove-member-modal', ['company' => $company, 'memberId' => $outsider->id])
        ->call('removeMember')
        ->assertNotFound();

    expect(DB::table('sessions')->where('id', $sessionId)->exists())->toBeTrue()
        ->and($outsider->fresh()->belongsToCompany($elsewhere))->toBeTrue()
        ->and(SecurityLog::query()->where('event', SecurityEvent::CompanyMemberRemoved->value)->exists())->toBeFalse();
});

it('does not remove an owner through the remove-member modal', function () {
    $owner = User::factory()->create();
    $coOwner = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($coOwner, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($owner);

    Livewire::test('pages::companies.remove-member-modal', ['company' => $company, 'memberId' => $coOwner->id])
        ->call('removeMember')
        ->assertForbidden();

    expect($coOwner->fresh()->belongsToCompany($company))->toBeTrue();
});
