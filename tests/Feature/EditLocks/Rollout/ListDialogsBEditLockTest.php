<?php

use App\Actions\MasterData\SaveFund;
use App\Actions\MasterData\SaveLocation;
use App\Enums\CompanyRole;
use App\Enums\FundType;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Models\Contact;
use App\Models\FormStyle;
use App\Models\MembershipLevel;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Services\EditLocks\EditLockManager;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

/*
 * Edit locks on the Settings → Lists edit dialogs: locations, funds, asset
 * categories, membership levels, form styles, tax codes + agencies, and other
 * names (with its Convert row action).
 */

beforeEach(function () {
    $this->company = Company::factory()->create(['features_membership' => true]);
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    $this->owner = editLockMember($this->company, CompanyRole::Owner);
    app()->instance('current_company', $this->company);

    $this->locks = app(EditLockManager::class);
    $this->page = fn (string $component) => Livewire::test($component, ['company' => $this->company]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

dataset('list edit dialogs', [
    'locations' => ['pages::settings.lists.locations', 'openEdit', 'save', 'editingId', 'f_name', 'name', fn () => app(SaveLocation::class)->handle(['name' => 'Chapel A'])],
    'funds' => ['pages::settings.lists.funds', 'openEdit', 'save', 'editingId', 'f_name', 'name', fn () => app(SaveFund::class)->handle(['name' => 'Building fund', 'fund_type' => FundType::Restricted->value])],
    'asset categories' => ['pages::settings.lists.asset-categories', 'openEdit', 'save', 'editingId', 'f_name', 'name', fn () => AssetCategory::factory()->create(['name' => 'Vehicles'])],
    'membership levels' => ['pages::settings.lists.membership-levels', 'openEdit', 'save', 'editingId', 'f_name', 'name', fn () => MembershipLevel::factory()->create(['name' => 'Individual'])],
    'form styles' => ['pages::settings.lists.form-styles', 'openEdit', 'save', 'editingId', 'f_name', 'name', fn () => FormStyle::factory()->create(['name' => 'Classic'])],
    'tax codes' => ['pages::settings.lists.tax-codes', 'openEdit', 'save', 'editingId', 'f_name', 'name', fn () => TaxCode::query()->orderBy('code')->firstOrFail()],
    'tax agencies' => ['pages::settings.lists.tax-codes', 'openAgencyEdit', 'saveAgency', 'editingAgencyId', 'a_name', 'name', fn () => TaxAgency::query()->orderBy('name')->firstOrFail()],
    'other names' => ['pages::settings.lists.other-names', 'openEdit', 'save', 'editingId', 'f_display_name', 'display_name', fn () => Contact::factory()->otherName()->create(['display_name' => 'Raffle winner'])],
]);

it('takes the lock when a row opens for editing, and releases it on save', function (string $component, string $open, string $save, string $editing, string $field, string $column, Model $record) {
    $this->actingAs($this->jane);

    $page = ($this->page)($component)
        ->call($open, $record->getKey())
        ->assertSet($editing, $record->getKey());

    expect($page->get('editLockToken'))->toHaveLength(40)
        ->and($this->locks->holderOtherThan($record, $this->bob)?->user->is($this->jane))->toBeTrue();

    $page->set($field, 'Renamed by Jane')->call($save)->assertHasNoErrors()->assertSet('editLockToken', null);

    expect($record->fresh()->{$column})->toBe('Renamed by Jane')
        ->and($this->locks->holderOtherThan($record, $this->bob))->toBeNull();
})->with('list edit dialogs');

it('tells a second member who is editing instead of opening the dialog', function (string $component, string $open, string $save, string $editing, string $field, string $column, Model $record) {
    $this->actingAs($this->jane);
    ($this->page)($component)->call($open, $record->getKey());

    $this->actingAs($this->bob);
    ($this->page)($component)
        ->call($open, $record->getKey())
        ->assertSet($editing, null)
        ->assertSet('editLockToken', null)
        ->assertSet('editLockPendingTakeover', null)
        ->assertDispatched('toast-show');

    expect($this->locks->holderOtherThan($record, $this->bob)?->user->is($this->jane))->toBeTrue();
})->with('list edit dialogs');

it('releases the lock when the dialog closes', function (string $component, string $open, string $save, string $editing, string $field, string $column, Model $record) {
    $this->actingAs($this->jane);
    ($this->page)($component)
        ->call($open, $record->getKey())
        ->call($open === 'openAgencyEdit' ? 'releaseAgencyEditLock' : 'releaseEditLock')
        ->assertSet('editLockToken', null);

    $this->actingAs($this->bob);
    ($this->page)($component)->call($open, $record->getKey())->assertSet($editing, $record->getKey());
})->with('list edit dialogs');

it('offers an owner the take-over of a tax agency and reopens the agency dialog', function () {
    $agency = TaxAgency::query()->orderBy('name')->firstOrFail();

    $this->actingAs($this->jane);
    $janes = ($this->page)('pages::settings.lists.tax-codes')->call('openAgencyEdit', $agency->id)->set('a_name', 'Jane typing');

    $this->actingAs($this->owner);
    $owners = ($this->page)('pages::settings.lists.tax-codes')
        ->call('openAgencyEdit', $agency->id)
        ->assertSet('editingAgencyId', null);

    expect($owners->get('editLockPendingTakeover'))->toMatchArray(['method' => 'openAgencyEdit', 'args' => [$agency->id], 'name' => $this->jane->name]);

    $owners->call('takeOverPendingEditLock')->assertSet('editingAgencyId', $agency->id);

    $this->actingAs($this->jane);
    $janes->call('saveAgency')->assertDispatched('toast-show');

    expect($agency->fresh()->name)->not->toBe('Jane typing');
});

it('holds one lock at a time across the tax code and agency dialogs', function () {
    $code = TaxCode::query()->orderBy('code')->firstOrFail();
    $agency = TaxAgency::query()->orderBy('name')->firstOrFail();

    $this->actingAs($this->jane);
    $page = ($this->page)('pages::settings.lists.tax-codes')->call('openEdit', $code->id);

    $page->call('openAgencyEdit', $agency->id);

    expect($this->locks->holderOtherThan($code, $this->bob))->toBeNull()
        ->and($this->locks->holderOtherThan($agency, $this->bob)?->user->is($this->jane))->toBeTrue();

    $page->call('openCreate');

    expect($this->locks->holderOtherThan($agency, $this->bob))->toBeNull();
});

it('keeps the tax code lock while a new authority is added from inside its dialog', function () {
    $code = TaxCode::query()->orderBy('code')->firstOrFail();

    $this->actingAs($this->jane);
    $page = ($this->page)('pages::settings.lists.tax-codes')->call('openEdit', $code->id);
    $token = $page->get('editLockToken');

    // The inline "New authority" button opens the agency dialog over the tax code one.
    $page->call('openAgencyCreate')
        ->set('a_name', 'Nested Authority')
        ->call('saveAgency')
        ->assertHasNoErrors()
        ->call('releaseAgencyEditLock') // the agency dialog's wire:close
        ->assertSet('editLockToken', $token)
        ->assertSet('editingId', $code->id);

    expect(TaxAgency::query()->where('name', 'Nested Authority')->exists())->toBeTrue()
        ->and($this->locks->holderOtherThan($code, $this->bob)?->user->is($this->jane))->toBeTrue();

    $page->set('f_name', 'Renamed after nesting')->call('save')->assertHasNoErrors()->assertSet('editLockToken', null);

    expect($code->fresh()->name)->toBe('Renamed after nesting');
});

it('refuses converting an other name someone is editing', function () {
    $other = Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);

    $this->actingAs($this->jane);
    ($this->page)('pages::settings.lists.other-names')->call('openEdit', $other->id);

    $this->actingAs($this->bob);
    ($this->page)('pages::settings.lists.other-names')
        ->call('convert', $other->id, 'is_vendor')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $other->refresh();

    expect($other->is_other_name)->toBeTrue()
        ->and($other->is_vendor)->toBeFalse();
});

it('converts an other name nobody is editing', function () {
    $other = Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);

    $this->actingAs($this->bob);
    ($this->page)('pages::settings.lists.other-names')
        ->call('convert', $other->id, 'is_vendor')
        ->assertHasNoErrors();

    expect($other->fresh()->is_vendor)->toBeTrue();
});
