<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Banking\Feeds\ActualBudget\ActualBudgetProvider;
use App\Services\Banking\Feeds\Akahu\AkahuException;
use App\Services\Banking\Feeds\Akahu\AkahuProvider;
use App\Services\Banking\Feeds\BankFeedException;
use App\Services\Banking\Feeds\BankFeedProviders;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('lists Akahu as usable and Actual Budget as coming soon', function () {
    $providers = BankFeedProviders::all();

    expect(array_keys($providers))->toBe(['akahu', 'actual'])
        ->and($providers['akahu'])->toBeInstanceOf(AkahuProvider::class)
        ->and($providers['akahu']->isAvailable())->toBeTrue()
        ->and($providers['actual'])->toBeInstanceOf(ActualBudgetProvider::class)
        ->and($providers['actual']->isAvailable())->toBeFalse()
        ->and($providers['actual']->unavailableReason())->not->toBeNull();
});

it('refuses to connect a provider that is not available yet', function () {
    expect(fn () => BankFeedProviders::get('actual')->connect((int) $this->company->id, []))
        ->toThrow(BankFeedException::class);
});

it('rejects an unknown provider key', function () {
    expect(fn () => BankFeedProviders::get('nope'))->toThrow(InvalidArgumentException::class);
});

it('treats Akahu errors as bank feed errors', function () {
    expect(new AkahuException('x'))->toBeInstanceOf(BankFeedException::class);
});

it('shows both providers on the Bank feeds page', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);

    Livewire::test('pages::banking.feeds', ['company' => $this->company])
        ->assertSee('Akahu')
        ->assertSee('Actual Budget')
        ->assertSee('Coming soon')
        ->assertSee('Not connected');
});
