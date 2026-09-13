<?php

use App\Enums\CompanyRole;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Services\EditLocks\EditLockManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The customer, vendor and employee edit dialogs take the contact's edit lock,
 * and the row actions that change a contact (toggle, merge, attachments) are
 * refused while someone else is editing it.
 */

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    $this->owner = editLockMember($this->company, CompanyRole::Owner);
    app()->instance('current_company', $this->company);

    $this->page = fn (string $component) => Livewire::test($component, ['company' => $this->company]);
    $this->locks = fn () => app(EditLockManager::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

dataset('contact dialogs', [
    'customers' => ['pages::customers.index', 'customer-form', ['is_customer' => true]],
    'vendors' => ['pages::vendors.index', 'vendor-form', ['is_vendor' => true]],
    'employees' => ['pages::employees.index', 'employee-form', ['is_employee' => true]],
]);

dataset('contact row-action pages', [
    'customers' => ['pages::customers.index', ['is_customer' => true]],
    'vendors' => ['pages::vendors.index', ['is_vendor' => true]],
]);

it('takes the lock when a contact opens for editing, and releases it on save', function (string $component, string $modal, array $role) {
    $contact = Contact::factory()->create($role);
    $this->actingAs($this->jane);

    $page = ($this->page)($component)
        ->call('openEdit', $contact->id)
        ->assertSet('editingId', $contact->id)
        ->assertDispatched('modal-show', name: $modal);

    expect($page->get('editLockToken'))->toHaveLength(40)
        ->and(($this->locks)()->holderOtherThan($contact, $this->bob)?->user->is($this->jane))->toBeTrue();

    $page->set('f_display_name', 'Renamed by Jane')->call('save')->assertHasNoErrors()->assertSet('editLockToken', null);

    expect($contact->fresh()->display_name)->toBe('Renamed by Jane')
        ->and(($this->locks)()->holderOtherThan($contact, $this->bob))->toBeNull();
})->with('contact dialogs');

it('tells a second member who is editing instead of opening the dialog', function (string $component, string $modal, array $role) {
    $contact = Contact::factory()->create($role);
    $this->actingAs($this->jane);
    ($this->page)($component)->call('openEdit', $contact->id);

    $this->actingAs($this->bob);
    ($this->page)($component)
        ->call('openEdit', $contact->id)
        ->assertSet('editingId', null)
        ->assertSet('editLockPendingTakeover', null)
        ->assertSet('f_display_name', '')
        ->assertDispatched('toast-show')
        ->assertNotDispatched('modal-show', name: $modal);
})->with('contact dialogs');

it('shows the toast instead of opening a ?edit= deep link someone else holds', function (string $component, string $modal, array $role) {
    $contact = Contact::factory()->create($role);
    ($this->locks)()->acquire($contact, $this->jane);

    $this->actingAs($this->bob);
    Livewire::withQueryParams(['edit' => $contact->id])
        ->test($component, ['company' => $this->company])
        ->assertSet('editingId', null)
        ->assertSet('editRequest', null)
        ->assertSet('editLockToken', null)
        ->assertDispatched('toast-show')
        ->assertNotDispatched('modal-show', name: $modal);
})->with('contact dialogs');

it('offers an owner the take-over from a ?edit= deep link, then opens the dialog', function (string $component, string $modal, array $role) {
    $contact = Contact::factory()->create($role);
    ($this->locks)()->acquire($contact, $this->jane);

    $this->actingAs($this->owner);
    $page = Livewire::withQueryParams(['edit' => $contact->id])
        ->test($component, ['company' => $this->company])
        ->assertSet('editingId', null)
        ->assertDispatched('modal-show', name: 'edit-lock-takeover');

    expect($page->get('editLockPendingTakeover')['name'])->toBe($this->jane->name);

    $page->call('takeOverPendingEditLock')
        ->assertSet('editingId', $contact->id)
        ->assertDispatched('modal-show', name: $modal);

    expect(($this->locks)()->holderOtherThan($contact, $this->jane)?->user->is($this->owner))->toBeTrue();
})->with('contact dialogs');

it('still opens a ?edit= deep link nobody else is editing, taking the lock', function (string $component, string $modal, array $role) {
    $contact = Contact::factory()->create($role);

    $this->actingAs($this->bob);
    $page = Livewire::withQueryParams(['edit' => $contact->id])
        ->test($component, ['company' => $this->company])
        ->assertSet('editingId', $contact->id)
        ->assertSet('editRequest', null)
        ->assertDispatched('modal-show', name: $modal);

    expect($page->get('editLockToken'))->toHaveLength(40);
})->with('contact dialogs');

it('refuses toggling a contact someone is editing', function (string $component, array $role) {
    $contact = Contact::factory()->create($role);
    ($this->locks)()->acquire($contact, $this->jane);

    $this->actingAs($this->bob);
    ($this->page)($component)
        ->call('toggleActive', $contact->id)
        ->assertDispatched('toast-show');

    expect($contact->fresh()->is_active)->toBeTrue();
})->with('contact row-action pages');

it('refuses merging when either contact is being edited', function (string $component, array $role) {
    $loser = Contact::factory()->create($role);
    $survivor = Contact::factory()->create($role);
    $this->actingAs($this->bob);

    // Merging INTO the contact being edited.
    $held = ($this->locks)()->acquire($survivor, $this->jane);

    ($this->page)($component)
        ->call('openMerge', $loser->id)
        ->set('mergeTargetId', $survivor->id)
        ->set('mergeConfirmed', true)
        ->call('merge')
        ->assertDispatched('toast-show');

    expect(Contact::query()->whereKey($loser->id)->exists())->toBeTrue();

    // Merging AWAY the contact being edited.
    ($this->locks)()->release($held->token, $this->jane);
    ($this->locks)()->acquire($loser, $this->jane);

    ($this->page)($component)
        ->call('openMerge', $loser->id)
        ->set('mergeTargetId', $survivor->id)
        ->set('mergeConfirmed', true)
        ->call('merge')
        ->assertDispatched('toast-show');

    expect(Contact::query()->whereKey($loser->id)->exists())->toBeTrue();
})->with('contact row-action pages');

it('refuses attachment changes to a contact someone else is editing', function (string $component, array $role) {
    Storage::fake('local');
    $contact = Contact::factory()->create($role);

    $this->actingAs($this->jane);
    ($this->page)($component)
        ->call('openEdit', $contact->id)
        ->set('newAttachments', [UploadedFile::fake()->create('kept.pdf', 10, 'application/pdf')])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $attachment = Attachment::query()->sole();

    // Bob crafts the dialog's editing id without ever holding the lock.
    $this->actingAs($this->bob);
    ($this->page)($component)
        ->set('editingId', $contact->id)
        ->set('newAttachments', [UploadedFile::fake()->create('sneaky.pdf', 10, 'application/pdf')])
        ->call('uploadAttachments')
        ->assertDispatched('toast-show')
        ->call('removeAttachment', $attachment->id);

    expect(Attachment::query()->pluck('original_filename')->all())->toBe(['kept.pdf']);
})->with('contact row-action pages');

it('refuses an attachment upload once the holder has lost the lock', function (string $component, array $role) {
    Storage::fake('local');
    $contact = Contact::factory()->create($role);

    $this->actingAs($this->jane);
    $janes = ($this->page)($component)->call('openEdit', $contact->id);

    ($this->locks)()->acquire($contact, $this->owner, takeOver: true);

    $janes->set('newAttachments', [UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')])
        ->call('uploadAttachments')
        ->assertDispatched('toast-show');

    expect(Attachment::query()->count())->toBe(0);
})->with('contact row-action pages');

it('lets the holder upload an attachment and still save the dialog', function () {
    Storage::fake('local');
    $customer = Contact::factory()->customer()->create();
    $this->actingAs($this->jane);

    ($this->page)('pages::customers.index')
        ->call('openEdit', $customer->id)
        ->set('newAttachments', [UploadedFile::fake()->create('terms.pdf', 10, 'application/pdf')])
        ->call('uploadAttachments')
        ->assertHasNoErrors()
        ->set('f_display_name', 'Saved after upload')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editLockToken', null);

    expect($customer->fresh()->display_name)->toBe('Saved after upload')
        ->and(Attachment::query()->count())->toBe(1);
});
