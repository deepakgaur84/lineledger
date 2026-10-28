<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\BankFeedAccount;
use App\Models\BankFeedConnection;
use App\Models\Company;
use App\Models\User;
use App\Services\Banking\Feeds\ActualBudget\ActualBudgetProvider;
use App\Services\Banking\Feeds\Akahu\AkahuException;
use App\Services\Banking\Feeds\Akahu\AkahuProvider;
use App\Services\Banking\Feeds\BankFeedException;
use App\Services\Banking\Feeds\BankFeedProviders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['timezone' => 'Pacific/Auckland']);
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function configureBridge(): void
{
    config()->set('bank_feeds.actual.bridge_url', 'http://bridge.test:5007');
    config()->set('bank_feeds.actual.bridge_token', 'bridge-secret-0123456789');
}

function connectActual(Company $company): BankFeedConnection
{
    return BankFeedProviders::get('actual')->connect((int) $company->id, [
        'server_url' => 'http://actual:5006',
        'password' => 'server-pass',
        'sync_id' => 'budget-uuid',
        'file_password' => '',
    ]);
}

it('lists Actual first and Akahu as the fallback', function () {
    $providers = BankFeedProviders::all();

    expect(array_keys($providers))->toBe(['actual', 'akahu'])
        ->and($providers['akahu'])->toBeInstanceOf(AkahuProvider::class)
        ->and($providers['akahu']->isAvailable())->toBeTrue()
        ->and($providers['actual'])->toBeInstanceOf(ActualBudgetProvider::class);
});

it('is unavailable until the bridge is configured', function () {
    config()->set('bank_feeds.actual.bridge_url', null);
    config()->set('bank_feeds.actual.bridge_token', null);

    $actual = BankFeedProviders::get('actual');

    expect($actual->isAvailable())->toBeFalse()
        ->and($actual->unavailableReason())->not->toBeNull();

    configureBridge();

    expect($actual->isAvailable())->toBeTrue()
        ->and($actual->unavailableReason())->toBeNull();
});

it('rejects an unknown provider key', function () {
    expect(fn () => BankFeedProviders::get('nope'))->toThrow(InvalidArgumentException::class);
});

it('treats Akahu errors as bank feed errors', function () {
    expect(new AkahuException('x'))->toBeInstanceOf(BankFeedException::class);
});

it('requires the server address, password and Sync ID to connect Actual', function () {
    expect(fn () => BankFeedProviders::get('actual')->connect((int) $this->company->id, ['server_url' => 'http://actual:5006']))
        ->toThrow(BankFeedException::class);
});

it('stores the Actual credentials encrypted', function () {
    $connection = connectActual($this->company);

    $raw = DB::table('bank_feed_connections')->where('id', $connection->id)->first();

    expect($raw->credentials)->not->toContain('server-pass')
        ->and($connection->fresh()->credentials['password'])->toBe('server-pass');
});

it('lists accounts through the bridge with the shared secret', function () {
    configureBridge();
    Http::fake(['bridge.test:5007/v1/accounts' => Http::response(['accounts' => [['id' => 'a1', 'name' => 'Everyday', 'offBudget' => false]]])]);

    $accounts = BankFeedProviders::get('actual')->accounts(connectActual($this->company));

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]->externalId)->toBe('a1')
        ->and($accounts[0]->name)->toBe('Everyday');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer bridge-secret-0123456789')
        && $request['serverUrl'] === 'http://actual:5006'
        && $request['syncId'] === 'budget-uuid');
});

it('maps bank-imported Actual transactions and skips hand-entered ones', function () {
    configureBridge();
    Http::fake(['bridge.test:5007/v1/transactions' => Http::response(['transactions' => [
        ['id' => 'x1', 'importedId' => 'tx_1', 'date' => '2026-10-20', 'amount' => -1234, 'payeeName' => 'Countdown', 'importedPayee' => 'COUNTDOWN 123', 'notes' => null, 'cleared' => true],
        ['id' => 'x2', 'importedId' => null, 'date' => '2026-10-21', 'amount' => -500, 'payeeName' => null, 'importedPayee' => null, 'notes' => 'cash', 'cleared' => false],
    ]])]);

    $connection = connectActual($this->company);
    $feed = new BankFeedAccount(['external_id' => 'a1']);

    $parsed = BankFeedProviders::get('actual')->transactions(
        $connection,
        $feed,
        CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        CarbonImmutable::parse('2026-10-28T00:00:00Z'),
        'Pacific/Auckland',
    );

    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]->externalId)->toBe('tx_1')
        ->and($parsed[0]->amountCents)->toBe(-1234)
        ->and($parsed[0]->description)->toBe('COUNTDOWN 123')
        ->and($parsed[0]->date->toDateString())->toBe('2026-10-20')
        ->and($parsed[0]->raw['merchant']['name'])->toBe('Countdown');
});

it('shows the bridge error message when Actual fails', function () {
    configureBridge();
    Http::fake(['bridge.test:5007/v1/transactions' => Http::response(['error' => 'Actual rejected the server password.'], 502)]);

    $feed = new BankFeedAccount(['external_id' => 'a1']);

    expect(fn () => BankFeedProviders::get('actual')->transactions(
        connectActual($this->company),
        $feed,
        CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        CarbonImmutable::parse('2026-10-28T00:00:00Z'),
        'Pacific/Auckland',
    ))->toThrow(BankFeedException::class, 'Actual rejected the server password.');
});

it('shows both providers on the Bank feeds page', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);

    Livewire::test('pages::banking.feeds', ['company' => $this->company])
        ->assertSee('Akahu')
        ->assertSee('Actual Budget')
        ->assertSee('Not connected');
});

it('will not link one ledger account to two feeds', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $connection = connectActual($this->company);

    $first = BankFeedAccount::create([
        'company_id' => $this->company->id,
        'bank_feed_connection_id' => $connection->id,
        'account_id' => $bank->id,
        'external_id' => 'a1',
        'name' => 'Everyday',
    ]);
    $second = BankFeedAccount::create([
        'company_id' => $this->company->id,
        'bank_feed_connection_id' => $connection->id,
        'external_id' => 'a2',
        'name' => 'Savings',
    ]);

    Livewire::test('pages::banking.feeds', ['company' => $this->company])
        ->set("links.{$second->id}", $bank->id)
        ->call('saveLink', $second->id)
        ->assertHasErrors(["links.{$second->id}"]);

    expect($second->fresh()->account_id)->toBeNull()
        ->and($first->fresh()->account_id)->toBe($bank->id);
});
