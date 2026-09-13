<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\EditLockState;
use App\Models\Account;
use App\Models\Company;
use App\Models\EditLock;
use App\Models\Invoice;
use App\Services\EditLocks\EditLockManager;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    $this->admin = editLockMember($this->company, CompanyRole::Admin);
    $this->invoice = editLockDraftInvoice($this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('takes the lock when the invoice is opened for editing', function () {
    $this->actingAs($this->jane);

    $form = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertSet('editLockBlocked', false);

    expect($form->get('editLockToken'))->toHaveLength(40);
    expect(EditLock::query()->sole()->user_id)->toBe($this->jane->id);
});

it('shows a second member who is editing instead of the form, and refuses a crafted save', function () {
    $this->actingAs($this->jane);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);

    $this->actingAs($this->bob);
    $bobs = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertSet('editLockBlocked', true)
        ->assertSee($this->jane->name)
        ->assertSee(__('Try again'))
        ->assertDontSee(__('Take over editing'))
        ->assertDontSee(__('Save draft'));

    $bobs->set('memo', 'Bob was here')->call('saveDraft')->assertDispatched('toast-show');

    expect($this->invoice->fresh()->memo)->not->toBe('Bob was here');
});

it('offers an admin the take-over, which hands them the lock and reloads their page', function () {
    $this->actingAs($this->jane);
    $janes = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);

    $this->actingAs($this->admin);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertSee(__('Take over editing'))
        ->call('takeOverEditLock')
        ->assertRedirect();

    expect(EditLock::query()->sole()->user_id)->toBe($this->admin->id);

    // Jane's page can no longer save, and says who has it.
    $this->actingAs($this->jane);
    $janes->set('memo', 'Jane was here')
        ->call('saveDraft')
        ->assertSet('editLockState', EditLockState::HeldBy)
        ->assertSee($this->admin->name);

    expect($this->invoice->fresh()->memo)->not->toBe('Jane was here');
});

it('refuses a take-over from a member who is not an owner or admin', function () {
    $this->actingAs($this->jane);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);

    $this->actingAs($this->bob);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])
        ->call('takeOverEditLock')
        ->assertNoRedirect();

    expect(EditLock::query()->sole()->user_id)->toBe($this->jane->id);
});

it('lets the holder save, and keeps the lock through the save', function () {
    $this->actingAs($this->jane);

    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])
        ->set('memo', 'Updated by Jane')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($this->invoice->fresh()->memo)->toBe('Updated by Jane')
        ->and(EditLock::query()->sole()->changed_at_ms)->not->toBeNull();
});

it('rejects the save of a page whose lease lapsed while someone else edited', function () {
    $this->actingAs($this->jane);
    $janes = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);

    $this->travel(config('edit_locks.ttl_seconds') + 5)->seconds();

    $this->actingAs($this->bob);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])->assertSet('editLockBlocked', false);

    $this->actingAs($this->jane);
    $janes->set('memo', 'Stale')->call('saveDraft')->assertSet('editLockState', EditLockState::HeldBy);

    expect($this->invoice->fresh()->memo)->not->toBe('Stale');
});

it('reclaims a lapsed lease silently when nobody touched the invoice', function () {
    $this->actingAs($this->jane);
    $janes = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);

    $this->travel(config('edit_locks.ttl_seconds') + 5)->seconds();

    $janes->set('memo', 'After a coffee')->call('saveDraft')->assertHasNoErrors();

    expect($this->invoice->fresh()->memo)->toBe('After a coffee');
});

it('rejects the save when an API write landed after the page took the lock', function () {
    $this->actingAs($this->jane);
    $janes = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);

    // An API write that passed its check before Jane acquired, and finished after.
    app(EditLockManager::class)->touch($this->invoice);

    $janes->set('memo', 'Overwrite')->call('saveDraft')->assertSet('editLockState', EditLockState::Changed);

    expect($this->invoice->fresh()->memo)->not->toBe('Overwrite');
});

it('reloads instead of editing in place when the lock frees up for a blocked member', function () {
    $this->actingAs($this->jane);
    $janes = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);

    $this->actingAs($this->bob);
    $bobs = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])->assertSet('editLockBlocked', true);

    app(EditLockManager::class)->release($janes->get('editLockToken'), $this->jane);

    $bobs->call('$refresh')
        ->assertSee(__('Start editing'))
        ->call('reloadEditLock')
        ->assertRedirect()
        ->assertSet('editLockBlocked', true);
});

it('reloads the page when someone saved while it was loading', function () {
    $this->actingAs($this->jane);
    $mine = app(EditLockManager::class)->acquire($this->invoice, $this->jane);
    app(EditLockManager::class)->release($mine->token, $this->jane);

    // Jane's save "finished" a moment after Bob's request started loading.
    DB::table('edit_locks')->update(['changed_at_ms' => now()->getTimestampMs() + 60_000]);

    $this->actingAs($this->bob);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])->assertRedirect();
});

it('does not block magic property syncs or upload internals', function () {
    $this->actingAs($this->jane);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);

    $this->actingAs($this->bob);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])
        ->call('$commit')
        ->assertNotDispatched('toast-show');
});

it('lets the user who opened the invoice in a second tab save there, and tells the first tab', function () {
    $this->actingAs($this->jane);
    $first = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);
    $second = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])->assertSet('editLockBlocked', false);

    $first->call('saveDraft')->assertSet('editLockState', EditLockState::Elsewhere);
    $second->set('memo', 'From tab two')->call('saveDraft')->assertHasNoErrors();

    expect($this->invoice->fresh()->memo)->toBe('From tab two');
});

it('shows the paused banner when the keeper reports inactivity, and resumes on request', function () {
    $this->actingAs($this->jane);
    $form = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);

    $this->travel(config('edit_locks.idle_minutes') * 60 + 1)->seconds();
    DB::table('edit_locks')->update(['expires_at_ms' => now()->getTimestampMs() + 60_000]);

    $form->call('syncEditLock')
        ->assertSet('editLockState', EditLockState::Idle)
        ->assertSee(__('Editing paused'))
        ->call('resumeEditLock')
        ->assertSet('editLockState', EditLockState::Held);
});

it('takes no lock on the create page, until a record exists', function () {
    $this->actingAs($this->jane);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertSet('editLockToken', null);

    expect(EditLock::query()->count())->toBe(0);
});

it('does nothing for a mount with no signed-in user', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])->assertSet('editLockToken', null);

    expect(EditLock::query()->count())->toBe(0);
});

it('renders the keeper with its lease endpoints for the holder', function () {
    $this->actingAs($this->jane);

    $html = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertSeeHtml('data-test="edit-lock-keeper"')
        ->html();

    // @js() nests the config in JSON.parse('…'), escaping every slash.
    expect(str_replace('\\', '', $html))->toContain('editLockKeeper(')
        ->toContain('/edit-locks/heartbeat')
        ->toContain('/edit-locks/release');
});

it('renders no keeper for a blocked member', function () {
    $this->actingAs($this->jane);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);

    $this->actingAs($this->bob);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertDontSeeHtml('data-test="edit-lock-keeper"');
});

it('reloads when a write landed on a never-opened invoice while the page was loading', function () {
    $this->freezeTime();

    // An API update / show-page action on an invoice with no lock row yet.
    app(EditLockManager::class)->guardWrite($this->invoice, null, fn () => null);

    $this->actingAs($this->jane);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])->assertRedirect();
});

it('locks a record the create page made when posting failed, and refuses it to a second member', function () {
    $this->company->update(['lock_date' => '2099-12-31']);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

    $this->actingAs($this->jane);
    $create = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_id', $this->invoice->contact_id)
        ->set('lines.0.account_id', $income->id)
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '50.00')
        ->call('postInvoice')
        ->assertHasErrors();

    $created = Invoice::query()->whereKeyNot($this->invoice->id)->sole();

    expect($create->get('editLockToken'))->toHaveLength(40);

    $this->actingAs($this->bob);
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $created])
        ->assertSet('editLockBlocked', true);
});

it('refuses to take a create page\'s record over silently once someone else changed it', function () {
    $this->company->update(['lock_date' => '2099-12-31']);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

    $this->actingAs($this->jane);
    config(['edit_locks.enabled' => false]);
    $create = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_id', $this->invoice->contact_id)
        ->set('lines.0.account_id', $income->id)
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '50.00')
        ->call('postInvoice');
    config(['edit_locks.enabled' => true]);

    $created = Invoice::query()->whereKeyNot($this->invoice->id)->sole();
    app(EditLockManager::class)->guardWrite($created, $this->bob, fn () => $created->update(['memo' => 'Bob changed it']));

    $create->set('memo', 'Jane stale')
        ->call('saveDraft')
        ->assertSet('editLockState', EditLockState::Changed);

    expect($created->fresh()->memo)->toBe('Bob changed it');
});

it('polls the blocked panel only while someone else still holds the lock', function () {
    $this->actingAs($this->jane);
    $janes = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice]);

    $this->actingAs($this->bob);
    $bobs = Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertSeeHtml('data-test="edit-lock-blocked-poller"')
        ->assertDontSeeHtml('wire:poll');

    app(EditLockManager::class)->release($janes->get('editLockToken'), $this->jane);

    $bobs->call('$refresh')->assertDontSeeHtml('data-test="edit-lock-blocked-poller"');
});
