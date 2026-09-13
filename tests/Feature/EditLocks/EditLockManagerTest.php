<?php

use App\Enums\CompanyRole;
use App\Enums\EditLockState;
use App\Exceptions\EditLocks\EditLockLostException;
use App\Exceptions\EditLocks\RecordEditLockedException;
use App\Models\Company;
use App\Models\EditLock;
use App\Services\Backup\BackupTableRegistry;
use App\Services\EditLocks\EditLockManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = editLockMember($this->company, CompanyRole::Owner);
    $this->admin = editLockMember($this->company, CompanyRole::Admin);
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Custom);
    $this->invoice = editLockDraftInvoice($this->company);
    $this->locks = app(EditLockManager::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('takes a free record and stores one row for it', function () {
    $result = $this->locks->acquire($this->invoice, $this->jane);

    expect($result->acquired)->toBeTrue()
        ->and($result->token)->toHaveLength(40)
        ->and($result->version)->toHaveLength(20);

    $row = EditLock::query()->sole();
    expect($row->company_id)->toBe($this->company->id)
        ->and($row->lockable_type)->toBe($this->invoice::class)
        ->and($row->lockable_id)->toBe($this->invoice->id)
        ->and($row->user_id)->toBe($this->jane->id);
});

it('refuses a second member while the lease is live and names the holder', function () {
    $this->locks->acquire($this->invoice, $this->jane);

    $result = $this->locks->acquire($this->invoice, $this->bob);

    expect($result->acquired)->toBeFalse()
        ->and($result->holder->user->is($this->jane))->toBeTrue()
        ->and(EditLock::query()->count())->toBe(1);
});

it('lets the same user take their own lock again with a new token, so the older page is told it is elsewhere', function () {
    $first = $this->locks->acquire($this->invoice, $this->jane);
    $second = $this->locks->acquire($this->invoice, $this->jane);

    expect($second->acquired)->toBeTrue()
        ->and($second->token)->not->toBe($first->token)
        ->and($second->version)->not->toBe($first->version);

    try {
        $this->locks->verify($this->invoice, $first->token, $first->version, $this->jane);
        $this->fail('The older page should no longer hold the lock.');
    } catch (EditLockLostException $e) {
        expect($e->state)->toBe(EditLockState::Elsewhere);
    }
});

it('lets another member in once the lease has expired', function () {
    $this->locks->acquire($this->invoice, $this->jane);

    $this->travel(config('edit_locks.ttl_seconds') + 1)->seconds();

    expect($this->locks->acquire($this->invoice, $this->bob)->acquired)->toBeTrue();
});

it('lets an owner or admin take over, but not an accountant or custom member', function () {
    $this->locks->acquire($this->invoice, $this->jane);

    expect($this->locks->acquire($this->invoice, $this->admin, takeOver: true)->acquired)->toBeTrue()
        ->and($this->locks->acquire($this->invoice, $this->owner, takeOver: true)->acquired)->toBeTrue();

    expect(fn () => $this->locks->acquire($this->invoice, $this->bob, takeOver: true))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $this->locks->acquire($this->invoice, $this->jane, takeOver: true))
        ->toThrow(AuthorizationException::class);
});

it('tells a taken-over holder who has the lock now', function () {
    $mine = $this->locks->acquire($this->invoice, $this->jane);
    $this->locks->acquire($this->invoice, $this->admin, takeOver: true);

    try {
        $this->locks->verify($this->invoice, $mine->token, $mine->version, $this->jane);
        $this->fail('Jane should have lost the lock.');
    } catch (EditLockLostException $e) {
        expect($e->state)->toBe(EditLockState::HeldBy)
            ->and($e->getMessage())->toContain($this->admin->name);
    }
});

it('renews a held lease on verify', function () {
    $mine = $this->locks->acquire($this->invoice, $this->jane);
    $before = EditLock::query()->sole()->expires_at_ms;

    $this->travel(30)->seconds();
    $this->locks->verify($this->invoice, $mine->token, $mine->version, $this->jane);

    expect(EditLock::query()->sole()->expires_at_ms)->toBeGreaterThan($before);
});

it('silently reclaims a lapsed lease when nothing changed meanwhile', function () {
    $mine = $this->locks->acquire($this->invoice, $this->jane);
    $this->travel(config('edit_locks.ttl_seconds') + 5)->seconds();

    $this->locks->verify($this->invoice, $mine->token, $mine->version, $this->jane);

    expect($this->locks->acquire($this->invoice, $this->bob)->acquired)->toBeFalse();
});

it('refuses to reclaim a lapsed lease after someone else wrote the record', function () {
    $mine = $this->locks->acquire($this->invoice, $this->jane);
    $this->travel(config('edit_locks.ttl_seconds') + 5)->seconds();

    $this->locks->guardWrite($this->invoice, $this->bob, fn () => null);

    expect(fn () => $this->locks->verify($this->invoice, $mine->token, $mine->version, $this->jane))
        ->toThrow(EditLockLostException::class);
});

it('refuses to reclaim after someone merely opened and closed the record', function () {
    $mine = $this->locks->acquire($this->invoice, $this->jane);
    DB::table('edit_locks')->update(['expires_at_ms' => now()->getTimestampMs() - 1]); // Jane's lease runs out

    $bobs = $this->locks->acquire($this->invoice, $this->bob);
    $this->locks->release($bobs->token, $this->bob);

    try {
        $this->locks->verify($this->invoice, $mine->token, $mine->version, $this->jane);
        $this->fail('The version moved, so Jane must reload.');
    } catch (EditLockLostException $e) {
        expect($e->state)->toBe(EditLockState::Changed);
    }
});

it('invalidates a taker whose takeover landed during the holder\'s call', function () {
    $mine = $this->locks->acquire($this->invoice, $this->jane);
    $this->locks->verify($this->invoice, $mine->token, $mine->version, $this->jane); // Jane's save starts

    $admins = $this->locks->acquire($this->invoice, $this->admin, takeOver: true); // lands mid-save

    $this->locks->afterHolderCall($this->invoice, $mine->token); // Jane's save finishes

    expect(fn () => $this->locks->verify($this->invoice, $admins->token, $admins->version, $this->admin))
        ->toThrow(EditLockLostException::class);
});

it('leaves the holder alone after a call when nobody took over', function () {
    $mine = $this->locks->acquire($this->invoice, $this->jane);

    $this->locks->afterHolderCall($this->invoice, $mine->token);

    $this->locks->verify($this->invoice, $mine->token, $mine->version, $this->jane);
    expect(EditLock::query()->sole()->changed_at_ms)->not->toBeNull();
});

it('renews on heartbeat, reports idle after inactivity, and expired after the lease runs out', function () {
    $mine = $this->locks->acquire($this->invoice, $this->jane);

    expect($this->locks->heartbeat($mine->token, true, $this->jane))
        ->toBe(['status' => 'held', 'version' => $mine->version]);

    // Inactive beats keep renewing until the idle limit…
    $idleSeconds = config('edit_locks.idle_minutes') * 60;
    for ($elapsed = 60; $elapsed < $idleSeconds; $elapsed += 60) {
        $this->travel(60)->seconds();
        expect($this->locks->heartbeat($mine->token, false, $this->jane)['status'])->toBe('held');
    }

    // …then stop.
    $this->travel(60)->seconds();
    expect($this->locks->heartbeat($mine->token, false, $this->jane)['status'])->toBe('idle');
    expect($this->locks->inspect($this->invoice, $mine->token, $mine->version, $this->jane))->toBe(EditLockState::Idle);

    $this->travel(config('edit_locks.ttl_seconds') + 1)->seconds();
    expect($this->locks->heartbeat($mine->token, false, $this->jane)['status'])->toBe('expired');
});

it('reports lost to a heartbeat from a replaced token, another user, or a removed member', function () {
    $old = $this->locks->acquire($this->invoice, $this->jane);
    $new = $this->locks->acquire($this->invoice, $this->jane);

    expect($this->locks->heartbeat($old->token, true, $this->jane)['status'])->toBe('lost')
        ->and($this->locks->heartbeat($new->token, true, $this->bob)['status'])->toBe('lost');

    $this->company->members()->detach($this->jane);
    expect($this->locks->heartbeat($new->token, true, $this->jane)['status'])->toBe('lost');
});

it('releases only the matching token, and releasing twice is harmless', function () {
    $old = $this->locks->acquire($this->invoice, $this->jane);
    $new = $this->locks->acquire($this->invoice, $this->jane);

    $this->locks->release($old->token, $this->jane); // a late beacon from the page Jane left
    expect($this->locks->acquire($this->invoice, $this->bob)->acquired)->toBeFalse();

    $this->locks->release($new->token, $this->jane);
    $this->locks->release($new->token, $this->jane);

    expect($this->locks->acquire($this->invoice, $this->bob)->acquired)->toBeTrue()
        ->and(EditLock::query()->count())->toBe(1);
});

it('refuses guarded writes by other members while held, and by null actors', function () {
    $this->locks->acquire($this->invoice, $this->jane);

    expect(fn () => $this->locks->guardWrite($this->invoice, $this->bob, fn () => 'written'))
        ->toThrow(RecordEditLockedException::class);
    expect(fn () => $this->locks->guardWrite($this->invoice, null, fn () => 'written'))
        ->toThrow(RecordEditLockedException::class);

    expect($this->locks->guardWrite($this->invoice, $this->jane, fn () => 'written'))->toBe('written');
});

it('gives API clients a message that never names the holder, with a retry hint', function () {
    $this->locks->acquire($this->invoice, $this->jane);

    try {
        $this->locks->assertWritable($this->invoice, null);
        $this->fail('Expected the write to be refused.');
    } catch (RecordEditLockedException $e) {
        expect($e->clientSafeMessage())->not->toContain($this->jane->name)
            ->and($e->getMessage())->toContain($this->jane->name)
            ->and($e->retryAfterSeconds)->toBeGreaterThan(0)->toBeLessThanOrEqual(config('edit_locks.ttl_seconds'));
    }
});

it('replaces the version after a guarded write even when the write throws', function () {
    $this->locks->acquire($this->invoice, $this->bob);
    $this->locks->release(EditLock::query()->sole()->token, $this->bob);
    $before = $this->locks->currentVersion($this->invoice);

    expect(fn () => $this->locks->guardWrite($this->invoice, $this->jane, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    expect($this->locks->currentVersion($this->invoice))->not->toBe($before);
});

it('refuses a version-pinned write once the version moved', function () {
    $pinned = $this->locks->currentVersion($this->invoice); // null: never opened

    $this->locks->guardWriteAtVersion($this->invoice, null, $pinned, fn () => null);

    expect(fn () => $this->locks->guardWriteAtVersion($this->invoice, null, $pinned, fn () => null))
        ->not->toThrow(Throwable::class); // no row yet, still null

    $mine = $this->locks->acquire($this->invoice, $this->jane);
    $this->locks->release($mine->token, $this->jane);

    expect(fn () => $this->locks->guardWriteAtVersion($this->invoice, null, $pinned, fn () => null))
        ->toThrow(EditLockLostException::class);
});

it('treats a deleted holder as free', function () {
    $this->locks->acquire($this->invoice, $this->jane);

    $this->jane->delete();

    expect($this->locks->acquire($this->invoice, $this->bob)->acquired)->toBeTrue();
});

it('does nothing when edit locks are switched off', function () {
    config(['edit_locks.enabled' => false]);

    expect($this->locks->acquire($this->invoice, $this->jane)->acquired)->toBeTrue()
        ->and($this->locks->acquire($this->invoice, $this->bob)->acquired)->toBeTrue()
        ->and(EditLock::query()->count())->toBe(0);

    $this->locks->assertWritable($this->invoice, null);
});

it('prunes released rows nobody has touched for the retention window, keeping live and recent ones', function () {
    $other = editLockDraftInvoice($this->company);

    $stale = $this->locks->acquire($this->invoice, $this->jane);
    $this->locks->release($stale->token, $this->jane);

    $this->travel(config('edit_locks.prune_after_days') + 1)->days();

    $live = $this->locks->acquire($other, $this->bob);

    $this->artisan('edit-locks:prune')->assertSuccessful();

    expect(EditLock::query()->pluck('lockable_id')->all())->toBe([$other->id]);
    expect($live->acquired)->toBeTrue();
});

it('is excluded from company backups', function () {
    expect(array_column(BackupTableRegistry::excludedTables(), 'table'))->toContain('edit_locks');
});

it('records a guarded write to a record nobody has opened, so the next editor sees it', function () {
    expect(EditLock::query()->count())->toBe(0);

    $this->locks->guardWrite($this->invoice, $this->bob, fn () => null);

    $row = EditLock::query()->sole();
    expect($row->user_id)->toBeNull()
        ->and($row->expires_at_ms)->toBeNull()
        ->and($row->changed_at_ms)->not->toBeNull();

    $result = $this->locks->acquire($this->invoice, $this->jane);

    expect($result->acquired)->toBeTrue()
        ->and($result->changedAtMs)->toBe($row->changed_at_ms);
});

it('does not count a keeper check-in as activity', function () {
    $mine = $this->locks->acquire($this->invoice, $this->jane);
    $activeAt = EditLock::query()->sole()->last_active_at_ms;

    $this->travel(5)->minutes();
    $this->locks->verify($this->invoice, $mine->token, $mine->version, $this->jane, markActive: false);

    expect(EditLock::query()->sole()->last_active_at_ms)->toBe($activeAt);
});

it('keeps an idle page idle even after its lease lapsed', function () {
    $mine = $this->locks->acquire($this->invoice, $this->jane);

    $this->travel(config('edit_locks.idle_minutes') * 60 + 60)->seconds();

    expect($this->locks->inspect($this->invoice, $mine->token, $mine->version, $this->jane))->toBe(EditLockState::Idle);
});

it('refuses a version-pinned write when the holder saved after the page loaded', function () {
    $lease = $this->locks->acquire($this->invoice, $this->jane);
    $pinned = $this->locks->currentVersion($this->invoice);
    $loadedAt = $this->locks->nowMs();

    $this->travel(1)->seconds();
    $this->locks->afterHolderCall($this->invoice, $lease->token); // Jane saves
    $this->locks->release($lease->token, $this->jane);

    expect(fn () => $this->locks->guardWriteAtVersion($this->invoice, null, $pinned, fn () => null, $loadedAt))
        ->toThrow(EditLockLostException::class);
});
