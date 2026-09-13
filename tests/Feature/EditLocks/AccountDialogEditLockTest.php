<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\EditLockState;
use App\Models\Account;
use App\Models\Company;
use App\Models\EditLock;
use App\Services\EditLocks\EditLockManager;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    $this->owner = editLockMember($this->company, CompanyRole::Owner);
    app()->instance('current_company', $this->company);

    $this->account = Account::query()->where('subtype', AccountSubtype::Expense->value)->where('is_system', false)->orderBy('code')->firstOrFail();
    $this->other = Account::query()->where('subtype', AccountSubtype::Expense->value)->where('is_system', false)->whereKeyNot($this->account->id)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('takes the lock when an account opens for editing, and releases it on save', function () {
    $this->actingAs($this->jane);

    $page = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $this->account->id)
        ->assertSet('editingId', $this->account->id);

    expect($page->get('editLockToken'))->toHaveLength(40)
        ->and(app(EditLockManager::class)->holderOtherThan($this->account, $this->bob)?->user->is($this->jane))->toBeTrue();

    $page->set('form_name', 'Renamed by Jane')->call('save')->assertHasNoErrors()->assertSet('editLockToken', null);

    expect($this->account->fresh()->name)->toBe('Renamed by Jane')
        ->and(app(EditLockManager::class)->holderOtherThan($this->account, $this->bob))->toBeNull();
});

it('tells a second member who is editing instead of opening the dialog', function () {
    $this->actingAs($this->jane);
    Livewire::test('pages::accounts.index', ['company' => $this->company])->call('openEdit', $this->account->id);

    $this->actingAs($this->bob);
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $this->account->id)
        ->assertSet('editingId', null)
        ->assertSet('editLockPendingTakeover', null)
        ->assertDispatched('toast-show');
});

it('offers an owner the take-over and then opens the dialog for them', function () {
    $this->actingAs($this->jane);
    $janes = Livewire::test('pages::accounts.index', ['company' => $this->company])->call('openEdit', $this->account->id)->set('form_name', 'Jane typing');

    $this->actingAs($this->owner);
    $owners = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $this->account->id)
        ->assertSet('editingId', null);

    expect($owners->get('editLockPendingTakeover')['name'])->toBe($this->jane->name);

    $owners->call('takeOverPendingEditLock')->assertSet('editingId', $this->account->id);

    // Jane's dialog can no longer save.
    $this->actingAs($this->jane);
    $janes->call('save')->assertDispatched('toast-show');

    expect($this->account->fresh()->name)->not->toBe('Jane typing');
});

it('releases the lock when the dialog closes', function () {
    $this->actingAs($this->jane);
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $this->account->id)
        ->call('releaseEditLock')
        ->assertSet('editLockToken', null);

    $this->actingAs($this->bob);
    Livewire::test('pages::accounts.index', ['company' => $this->company])->call('openEdit', $this->account->id)->assertSet('editingId', $this->account->id);
});

it('lets go of the previous row when another row opens', function () {
    $this->actingAs($this->jane);
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $this->account->id)
        ->call('openEdit', $this->other->id);

    expect(app(EditLockManager::class)->holderOtherThan($this->account, $this->bob))->toBeNull()
        ->and(app(EditLockManager::class)->holderOtherThan($this->other, $this->bob))->not->toBeNull();
});

it('refuses toggling or merging an account someone is editing', function () {
    $this->actingAs($this->jane);
    Livewire::test('pages::accounts.index', ['company' => $this->company])->call('openEdit', $this->account->id);

    $this->actingAs($this->bob);
    $wasActive = $this->account->is_active;

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('toggleActive', $this->account->id)
        ->assertDispatched('toast-show');

    expect($this->account->fresh()->is_active)->toBe($wasActive);

    // Merging another account INTO the one being edited is refused too.
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->set('mergingId', $this->other->id)
        ->set('mergeTargetId', $this->account->id)
        ->set('mergeConfirmed', true)
        ->call('merge');

    expect(Account::query()->whereKey($this->other->id)->exists())->toBeTrue();
});

it('toggles an account nobody is editing', function () {
    $this->actingAs($this->bob);
    $wasActive = $this->account->is_active;

    Livewire::test('pages::accounts.index', ['company' => $this->company])->call('toggleActive', $this->account->id);

    // The write is recorded (so an editor that loaded the account just before
    // can't save over it), but nobody holds the lock.
    expect($this->account->fresh()->is_active)->toBe(! $wasActive)
        ->and(EditLock::query()->sole()->user_id)->toBeNull()
        ->and(EditLock::query()->sole()->changed_at_ms)->not->toBeNull();
});

it('refuses a crafted save for an account someone else is editing without opening the dialog', function () {
    $this->actingAs($this->jane);
    Livewire::test('pages::accounts.index', ['company' => $this->company])->call('openEdit', $this->account->id);

    $this->actingAs($this->bob);
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->set('editingId', $this->account->id)
        ->set('form_code', $this->account->code)
        ->set('form_name', 'Bob crafted')
        ->set('form_subtype', $this->account->subtype->value)
        ->call('save')
        ->assertDispatched('toast-show');

    expect($this->account->fresh()->name)->not->toBe('Bob crafted');
});

it('takes the lock for a save that skipped opening the dialog when nobody holds it, then lets it go', function () {
    $this->actingAs($this->bob);
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->set('editingId', $this->account->id)
        ->set('form_code', $this->account->code)
        ->set('form_name', 'Bob direct')
        ->set('form_subtype', $this->account->subtype->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editLockToken', null);

    expect($this->account->fresh()->name)->toBe('Bob direct')
        ->and(app(EditLockManager::class)->holderOtherThan($this->account, $this->jane))->toBeNull();
});

it('answers the keeper\'s sync and resume calls on a dialog page', function () {
    $this->actingAs($this->jane);
    $janes = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $this->account->id)
        ->call('syncEditLock')
        ->assertSet('editLockState', EditLockState::Held);

    $this->actingAs($this->owner);
    app(EditLockManager::class)->acquire($this->account, $this->owner, takeOver: true);

    $this->actingAs($this->jane);
    $janes->call('syncEditLock')->assertSet('editLockState', EditLockState::HeldBy)
        ->call('resumeEditLock')->assertSet('editLockState', EditLockState::HeldBy);
});

it('lets a dialog go idle when only the keeper is checking in', function () {
    $this->actingAs($this->jane);
    $page = Livewire::test('pages::accounts.index', ['company' => $this->company])->call('openEdit', $this->account->id);

    for ($minute = 1; $minute <= config('edit_locks.idle_minutes') + 1; $minute++) {
        $this->travel(1)->minutes();
        $page->call('syncEditLock', false);
    }

    $page->assertSet('editLockState', EditLockState::Idle);
});
