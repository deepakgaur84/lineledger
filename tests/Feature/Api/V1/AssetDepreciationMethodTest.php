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

function methodApiHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function methodApiPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Delivery van',
        'asset_account_id' => test()->fixedAsset->id,
        'acquired_date' => '2026-01-15',
        'cost_cents' => 4500000,
    ], $overrides);
}

it('defaults to straight-line and keeps the useful life', function () {
    $response = $this->postJson('/api/v1/assets', methodApiPayload(['useful_life_months' => 60]), methodApiHeader());

    $response->assertStatus(201)
        ->assertJsonPath('data.depreciation_method', 'straight_line')
        ->assertJsonPath('data.useful_life_months', 60)
        ->assertJsonPath('data.depreciation_rate', null);
});

it('requires a rate when the method is declining balance', function () {
    $this->postJson('/api/v1/assets', methodApiPayload(['depreciation_method' => 'declining_balance']), methodApiHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['depreciation_rate']);
});

it('rejects any rate below 1 or above 100, and an unknown method', function () {
    $this->postJson('/api/v1/assets', methodApiPayload(['depreciation_method' => 'declining_balance', 'depreciation_rate' => 150]), methodApiHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['depreciation_rate']);

    foreach ([0, 0.5, 0.99] as $rate) {
        $this->postJson('/api/v1/assets', methodApiPayload(['depreciation_method' => 'declining_balance', 'depreciation_rate' => $rate]), methodApiHeader())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['depreciation_rate']);
    }

    $this->postJson('/api/v1/assets', methodApiPayload(['depreciation_method' => 'sum_of_years']), methodApiHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['depreciation_method']);
});

it('creates a declining-balance asset, keeping the useful life it now uses', function () {
    $response = $this->postJson('/api/v1/assets', methodApiPayload([
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
        'useful_life_months' => 60,
    ]), methodApiHeader());

    $response->assertStatus(201)
        ->assertJsonPath('data.depreciation_method', 'declining_balance')
        ->assertJsonPath('data.useful_life_months', 60);

    expect((float) $response->json('data.depreciation_rate'))->toBe(20.0);
});

it('creates a declining-balance asset with no useful life, leaving the materiality limit to default', function () {
    $this->postJson('/api/v1/assets', methodApiPayload([
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
    ]), methodApiHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.useful_life_months', null)
        ->assertJsonPath('data.materiality_limit_cents', null);
});

it('stores a materiality limit for a declining-balance asset but not for the other methods', function () {
    $this->postJson('/api/v1/assets', methodApiPayload([
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
        'materiality_limit_cents' => 75000,
    ]), methodApiHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.materiality_limit_cents', 75000);

    $this->postJson('/api/v1/assets', methodApiPayload([
        'useful_life_months' => 60,
        'materiality_limit_cents' => 75000,
    ]), methodApiHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.depreciation_method', 'straight_line')
        ->assertJsonPath('data.materiality_limit_cents', null);

    $this->postJson('/api/v1/assets', methodApiPayload([
        'depreciation_method' => 'immediate',
        'materiality_limit_cents' => 75000,
    ]), methodApiHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.materiality_limit_cents', null);
});

it('rejects a negative materiality limit', function () {
    $this->postJson('/api/v1/assets', methodApiPayload([
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
        'materiality_limit_cents' => -1,
    ]), methodApiHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['materiality_limit_cents']);
});

it('creates an immediate asset with neither a useful life nor a rate', function () {
    $response = $this->postJson('/api/v1/assets', methodApiPayload([
        'depreciation_method' => 'immediate',
        'useful_life_months' => 60,
        'depreciation_rate' => 30,
    ]), methodApiHeader());

    $response->assertStatus(201)
        ->assertJsonPath('data.depreciation_method', 'immediate')
        ->assertJsonPath('data.useful_life_months', null)
        ->assertJsonPath('data.depreciation_rate', null);
});

it('does not store a rate on a straight-line asset', function () {
    $this->postJson('/api/v1/assets', methodApiPayload([
        'useful_life_months' => 60,
        'depreciation_rate' => 30,
    ]), methodApiHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.depreciation_method', 'straight_line')
        ->assertJsonPath('data.depreciation_rate', null);
});

it('keeps an asset\'s method, rate and materiality limit when a client that predates methods updates it', function () {
    $id = $this->postJson('/api/v1/assets', methodApiPayload([
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
        'materiality_limit_cents' => 75000,
    ]), methodApiHeader())->json('data.id');

    // No depreciation_method, depreciation_rate or materiality_limit_cents in the payload at all.
    $response = $this->patchJson("/api/v1/assets/{$id}", methodApiPayload(['name' => 'Cargo van']), methodApiHeader());

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Cargo van')
        ->assertJsonPath('data.depreciation_method', 'declining_balance')
        ->assertJsonPath('data.materiality_limit_cents', 75000);

    expect((float) $response->json('data.depreciation_rate'))->toBe(20.0);
});

it('lets an update switch the method and clears what the new method does not use', function () {
    $id = $this->postJson('/api/v1/assets', methodApiPayload([
        'depreciation_method' => 'declining_balance',
        'depreciation_rate' => 20,
        'useful_life_months' => 60,
        'materiality_limit_cents' => 75000,
    ]), methodApiHeader())->json('data.id');

    $this->patchJson("/api/v1/assets/{$id}", methodApiPayload(['depreciation_method' => 'immediate']), methodApiHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.depreciation_method', 'immediate')
        ->assertJsonPath('data.depreciation_rate', null)
        ->assertJsonPath('data.useful_life_months', null)
        ->assertJsonPath('data.materiality_limit_cents', null);
});

it('requires a rate when an update switches to declining balance', function () {
    $id = $this->postJson('/api/v1/assets', methodApiPayload(['useful_life_months' => 60]), methodApiHeader())->json('data.id');

    $this->patchJson("/api/v1/assets/{$id}", methodApiPayload(['depreciation_method' => 'declining_balance']), methodApiHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['depreciation_rate']);
});
