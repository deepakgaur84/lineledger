<?php

use App\Enums\CompanyRole;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('starts a new category on straight-line with no rate', function () {
    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->assertSet('f_default_depreciation_method', 'straight_line')
        ->assertSet('f_default_depreciation_rate', '')
        ->set('f_name', 'Furniture')
        ->call('save')
        ->assertHasNoErrors();

    $category = AssetCategory::query()->where('name', 'Furniture')->firstOrFail();

    expect($category->defaultDepreciationMethod()->value)->toBe('straight_line')
        ->and($category->default_depreciation_rate)->toBeNull();
});

it('shows the rate field for straight-line and declining balance, but not immediate', function () {
    // Straight-line's own rate field used to be hidden entirely, the same bug
    // the asset form itself had before it was fixed — this is the regression
    // test for that fix landing here too.
    $page = Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->assertSee('Default depreciation method')
        ->assertSeeHtml('data-test="asset-category-rate"');

    $page->set('f_default_depreciation_method', 'declining_balance')
        ->assertSeeHtml('data-test="asset-category-rate"');

    $page->set('f_default_depreciation_method', 'immediate')
        ->assertDontSeeHtml('data-test="asset-category-rate"');

    $page->set('f_default_depreciation_method', 'straight_line')
        ->assertSeeHtml('data-test="asset-category-rate"');
});

it('suggests 20% when declining balance is picked and no rate is there yet', function () {
    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_default_depreciation_method', 'declining_balance')
        ->assertSet('f_default_depreciation_rate', '20');
});

it('keeps a rate that was already typed when declining balance is picked', function () {
    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_default_depreciation_rate', '12.5')
        ->set('f_default_depreciation_method', 'declining_balance')
        ->assertSet('f_default_depreciation_rate', '12.5');
});

it('saves a declining-balance category with its rate', function () {
    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Vehicles')
        ->set('f_default_depreciation_method', 'declining_balance')
        ->set('f_default_depreciation_rate', '25')
        ->call('save')
        ->assertHasNoErrors();

    $category = AssetCategory::query()->where('name', 'Vehicles')->firstOrFail();

    expect($category->defaultDepreciationMethod()->value)->toBe('declining_balance')
        ->and((float) $category->default_depreciation_rate)->toBe(25.0);
});

it('stores 20% when a declining-balance category is saved with the rate cleared', function () {
    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Vehicles')
        ->set('f_default_depreciation_method', 'declining_balance')
        ->set('f_default_depreciation_rate', '')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) AssetCategory::query()->where('name', 'Vehicles')->firstOrFail()->default_depreciation_rate)->toBe(20.0);
});

it('refuses a default rate below 1% or above 100%', function () {
    foreach (['0', '0.5', '0.99', '101', '250'] as $rate) {
        Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
            ->call('openCreate')
            ->set('f_name', 'Vehicles')
            ->set('f_default_depreciation_method', 'declining_balance')
            ->set('f_default_depreciation_rate', $rate)
            ->call('save')
            ->assertHasErrors(['f_default_depreciation_rate']);
    }

    expect(AssetCategory::query()->count())->toBe(0);
});

it('keeps a rate switched over from declining balance for straight-line, but not for 100% on purchase', function () {
    // Straight-line's own rate used to be discarded here too — the same bug
    // the asset form itself had before it was fixed (README-FORK-PATCHES.md §12).
    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Category straight_line')
        ->set('f_default_depreciation_method', 'declining_balance')
        ->set('f_default_depreciation_rate', '25')
        ->set('f_default_depreciation_method', 'straight_line')
        ->call('save')
        ->assertHasNoErrors();

    $slCategory = AssetCategory::query()->where('name', 'Category straight_line')->firstOrFail();

    expect($slCategory->defaultDepreciationMethod()->value)->toBe('straight_line')
        ->and((float) $slCategory->default_depreciation_rate)->toBe(25.0);

    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Category immediate')
        ->set('f_default_depreciation_method', 'declining_balance')
        ->set('f_default_depreciation_rate', '25')
        ->set('f_default_depreciation_method', 'immediate')
        ->call('save')
        ->assertHasNoErrors();

    $immediateCategory = AssetCategory::query()->where('name', 'Category immediate')->firstOrFail();

    expect($immediateCategory->defaultDepreciationMethod()->value)->toBe('immediate')
        ->and($immediateCategory->default_depreciation_rate)->toBeNull();
});

it('loads a category\'s method and rate when it is edited', function () {
    $category = AssetCategory::create([
        'name' => 'Vehicles',
        'default_depreciation_method' => 'declining_balance',
        'default_depreciation_rate' => 30,
        'is_active' => true,
    ]);

    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openEdit', $category->id)
        ->assertSet('f_default_depreciation_method', 'declining_balance')
        ->assertSet('f_default_depreciation_rate', '30');
});

it('shows each category\'s method and rate in the list', function () {
    AssetCategory::create([
        'name' => 'Vehicles',
        'default_depreciation_method' => 'declining_balance',
        'default_depreciation_rate' => 30,
        'is_active' => true,
    ]);
    AssetCategory::create(['name' => 'Furniture', 'is_active' => true]);

    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->assertSee('Written-down value (declining balance)')
        ->assertSee('30%')
        ->assertSee('Straight-line');
});

it('accepts and shows a rate on a straight-line category — the bug this guards', function () {
    // Previously rejected outright: the rate field was entirely hidden for
    // straight-line, and the save action discarded any rate it was somehow
    // given anyway, the same bug the asset form itself had.
    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->assertSeeHtml('data-test="asset-category-rate"')
        ->set('f_name', 'Vehicles')
        ->set('f_default_depreciation_rate', '20')
        ->call('save')
        ->assertHasNoErrors();

    $category = AssetCategory::query()->where('name', 'Vehicles')->firstOrFail();

    expect($category->defaultDepreciationMethod()->value)->toBe('straight_line')
        ->and((float) $category->default_depreciation_rate)->toBe(20.0);
});

it('never invents a straight-line default rate out of nothing — unlike declining balance\'s own 20%', function () {
    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Furniture')
        ->call('save')
        ->assertHasNoErrors();

    expect(AssetCategory::query()->where('name', 'Furniture')->firstOrFail()->default_depreciation_rate)->toBeNull();
});

it('does not require a straight-line rate to save the category', function () {
    Livewire::test('pages::settings.lists.asset-categories', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Furniture')
        ->set('f_default_useful_life_months', 36)
        ->call('save')
        ->assertHasNoErrors();

    expect(AssetCategory::query()->where('name', 'Furniture')->count())->toBe(1);
});
