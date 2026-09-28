<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->fixedAsset = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', '!=', 'Accumulated Depreciation')
        ->orderBy('code')
        ->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function categoryDefaultsHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

it('defaults a category to straight-line with no rate', function () {
    $this->postJson('/api/v1/asset-categories', ['name' => 'Furniture'], categoryDefaultsHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.default_depreciation_method', 'straight_line')
        ->assertJsonPath('data.default_depreciation_rate', null);
});

it('creates a declining-balance category with a rate', function () {
    $response = $this->postJson('/api/v1/asset-categories', [
        'name' => 'Vehicles',
        'default_depreciation_method' => 'declining_balance',
        'default_depreciation_rate' => 25,
    ], categoryDefaultsHeader());

    $response->assertStatus(201)->assertJsonPath('data.default_depreciation_method', 'declining_balance');

    expect((float) $response->json('data.default_depreciation_rate'))->toBe(25.0);
});

it('gives a declining-balance category 20% when no rate is sent', function () {
    $response = $this->postJson('/api/v1/asset-categories', [
        'name' => 'Vehicles',
        'default_depreciation_method' => 'declining_balance',
    ], categoryDefaultsHeader());

    $response->assertStatus(201);

    expect((float) $response->json('data.default_depreciation_rate'))->toBe(20.0);
});

it('does not keep a rate for straight-line or immediate', function () {
    $this->postJson('/api/v1/asset-categories', ['name' => 'A', 'default_depreciation_rate' => 25], categoryDefaultsHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.default_depreciation_method', 'straight_line')
        ->assertJsonPath('data.default_depreciation_rate', null);

    $this->postJson('/api/v1/asset-categories', ['name' => 'B', 'default_depreciation_method' => 'immediate', 'default_depreciation_rate' => 25], categoryDefaultsHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.default_depreciation_rate', null);
});

it('rejects a rate below 1 or above 100, and an unknown method', function () {
    foreach ([0, 0.5, 0.99, 101] as $rate) {
        $this->postJson('/api/v1/asset-categories', [
            'name' => 'Vehicles',
            'default_depreciation_method' => 'declining_balance',
            'default_depreciation_rate' => $rate,
        ], categoryDefaultsHeader())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['default_depreciation_rate']);
    }

    $this->postJson('/api/v1/asset-categories', ['name' => 'Vehicles', 'default_depreciation_method' => 'sum_of_years'], categoryDefaultsHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['default_depreciation_method']);
});

it('keeps a category\'s method and rate when an update leaves them out', function () {
    $id = $this->postJson('/api/v1/asset-categories', [
        'name' => 'Vehicles',
        'default_depreciation_method' => 'declining_balance',
        'default_depreciation_rate' => 30,
    ], categoryDefaultsHeader())->json('data.id');

    $response = $this->patchJson("/api/v1/asset-categories/{$id}", ['name' => 'Company vehicles'], categoryDefaultsHeader());

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Company vehicles')
        ->assertJsonPath('data.default_depreciation_method', 'declining_balance');

    expect((float) $response->json('data.default_depreciation_rate'))->toBe(30.0);
});

it('lets an update switch the method and clears the rate it no longer uses', function () {
    $id = $this->postJson('/api/v1/asset-categories', [
        'name' => 'Vehicles',
        'default_depreciation_method' => 'declining_balance',
        'default_depreciation_rate' => 30,
    ], categoryDefaultsHeader())->json('data.id');

    $this->patchJson("/api/v1/asset-categories/{$id}", [
        'name' => 'Vehicles',
        'default_depreciation_method' => 'immediate',
    ], categoryDefaultsHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.default_depreciation_method', 'immediate')
        ->assertJsonPath('data.default_depreciation_rate', null);
});

// These fields share the request classes with the depreciation defaults; an edit
// that dropped their rules would make the API silently ignore them.
it('still stores the description, default accounts, useful life and active flag', function () {
    $this->postJson('/api/v1/asset-categories', [
        'name' => 'Equipment',
        'description' => 'Shop equipment',
        'default_asset_account_id' => $this->fixedAsset->id,
        'default_useful_life_months' => 60,
        'is_active' => false,
    ], categoryDefaultsHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.description', 'Shop equipment')
        ->assertJsonPath('data.default_asset_account_id', $this->fixedAsset->id)
        ->assertJsonPath('data.default_useful_life_months', 60)
        ->assertJsonPath('data.is_active', false);
});
