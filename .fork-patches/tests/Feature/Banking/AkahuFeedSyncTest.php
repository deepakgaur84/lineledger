<?php

use App\Actions\Contacts\SaveContact;
use App\Enums\AccountSubtype;
use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Enums\CompanyRole;
use App\Enums\StatementLineMatchStatus;
use App\Jobs\SyncBankFeedAccount;
use App\Models\Account;
use App\Models\BankFeedAccount;
use App\Models\BankFeedConnection;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\User;
use App\Services\Banking\Feeds\Akahu\AkahuException;
use App\Services\Banking\Feeds\BankFeedSyncer;
use App\Services\Banking\Feeds\BankFeedSyncStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['timezone' => 'Pacific/Auckland']);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    $this->connection = BankFeedConnection::create([
        'company_id' => $this->company->id,
        'provider' => 'akahu',
        'app_token' => 'app_token_secret',
        'user_token' => 'user_token_secret',
    ]);

    $this->feed = BankFeedAccount::create([
        'company_id' => $this->company->id,
        'bank_feed_connection_id' => $this->connection->id,
        'account_id' => $this->bank->id,
        'external_id' => 'acc_1',
        'name' => 'ANZ Everyday',
        'institution' => 'ANZ',
        'sync_from' => '2026-10-01',
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function akahuTxn(string $id, string $date, float $amount, string $description = 'COUNTDOWN'): array
{
    return ['_id' => $id, '_account' => 'acc_1', 'date' => $date, 'description' => $description, 'amount' => $amount, 'balance' => 1000.5, 'type' => 'EFTPOS'];
}

function fakeAkahu(array $items, ?string $next = null): void
{
    Http::fake([
        'api.akahu.io/v1/accounts/acc_1/transactions*' => Http::response(['success' => true, 'items' => $items, 'cursor' => ['next' => $next]]),
    ]);
}

it('stores the tokens encrypted at rest', function () {
    $raw = DB::table('bank_feed_connections')->where('id', $this->connection->id)->first();

    expect($raw->app_token)->not->toContain('app_token_secret')
        ->and($raw->user_token)->not->toContain('user_token_secret')
        ->and($this->connection->fresh()->user_token)->toBe('user_token_secret');
});

it('imports settled transactions as a feed import with the right sign, date and id', function () {
    fakeAkahu([
        akahuTxn('tx_out', '2026-10-22T11:00:00.000Z', -12.34),
        akahuTxn('tx_in', '2026-10-21T11:00:00.000Z', 500.00, 'SALARY'),
    ]);

    $result = app(BankFeedSyncer::class)->sync($this->feed, CarbonImmutable::parse('2026-10-23T02:00:00Z'));

    expect($result['added'])->toBe(2);

    $out = BankStatementLine::query()->where('external_id', 'tx_out')->firstOrFail();
    $in = BankStatementLine::query()->where('external_id', 'tx_in')->firstOrFail();

    // 11:00 UTC on the 22nd is midnight on the 23rd in Auckland (NZDT, UTC+13).
    expect($out->amount_cents)->toBe(-1234)
        ->and($out->txn_date->toDateString())->toBe('2026-10-23')
        ->and($in->amount_cents)->toBe(50000)
        ->and($in->match_status)->toBe(StatementLineMatchStatus::Unmatched)
        ->and($in->account_id)->toBe($this->bank->id);

    $import = BankStatementImport::query()->findOrFail($result['import_id']);

    expect($import->source_format)->toBe(BankStatementFormat::Feed)
        ->and($import->bank_feed_account_id)->toBe($this->feed->id)
        ->and($import->line_count)->toBe(2);

    expect(BankStatementLine::query()->forReview()->count())->toBe(2);
    expect($this->feed->fresh()->last_synced_at)->not->toBeNull()
        ->and($this->feed->fresh()->last_error)->toBeNull();
});

it('does not import the same transaction twice', function () {
    fakeAkahu([akahuTxn('tx_1', '2026-10-22T11:00:00.000Z', -5.00)]);

    $first = app(BankFeedSyncer::class)->sync($this->feed, CarbonImmutable::parse('2026-10-23T02:00:00Z'));
    $second = app(BankFeedSyncer::class)->sync($this->feed->fresh(), CarbonImmutable::parse('2026-10-24T02:00:00Z'));

    expect($first['added'])->toBe(1)
        ->and($second['added'])->toBe(0)
        ->and($second['import_id'])->toBeNull()
        ->and(BankStatementLine::query()->count())->toBe(1);
});

it('applies a bank-side change to an unreviewed line but not to a reviewed one', function () {
    // Http::fake() stubs accumulate and the first match wins, so script both
    // responses in order: the original amounts, then the bank-side changes.
    Http::fake([
        'api.akahu.io/v1/accounts/acc_1/transactions*' => Http::sequence()
            ->push(['success' => true, 'items' => [akahuTxn('tx_a', '2026-10-22T11:00:00.000Z', -10.00), akahuTxn('tx_b', '2026-10-22T11:00:00.000Z', -20.00)], 'cursor' => ['next' => null]])
            ->push(['success' => true, 'items' => [akahuTxn('tx_a', '2026-10-22T11:00:00.000Z', -11.50), akahuTxn('tx_b', '2026-10-22T11:00:00.000Z', -25.00)], 'cursor' => ['next' => null]]),
    ]);
    app(BankFeedSyncer::class)->sync($this->feed, CarbonImmutable::parse('2026-10-23T02:00:00Z'));

    BankStatementLine::query()->where('external_id', 'tx_b')->update(['match_status' => StatementLineMatchStatus::Created->value]);

    $result = app(BankFeedSyncer::class)->sync($this->feed->fresh(), CarbonImmutable::parse('2026-10-24T02:00:00Z'));

    $a = BankStatementLine::query()->where('external_id', 'tx_a')->firstOrFail();
    $b = BankStatementLine::query()->where('external_id', 'tx_b')->firstOrFail();

    expect($result['updated'])->toBe(1)
        ->and($a->amount_cents)->toBe(-1150)
        ->and($b->amount_cents)->toBe(-2000)
        ->and($b->raw)->toHaveKey('feed_changed');
});

it('follows the pagination cursor', function () {
    Http::fake([
        'api.akahu.io/v1/accounts/acc_1/transactions*' => Http::sequence()
            ->push(['success' => true, 'items' => [akahuTxn('p1', '2026-10-20T11:00:00.000Z', -1.00)], 'cursor' => ['next' => 'CURSOR2']])
            ->push(['success' => true, 'items' => [akahuTxn('p2', '2026-10-21T11:00:00.000Z', -2.00)], 'cursor' => ['next' => null]]),
    ]);

    $result = app(BankFeedSyncer::class)->sync($this->feed, CarbonImmutable::parse('2026-10-23T02:00:00Z'));

    expect($result['added'])->toBe(2);
    Http::assertSentCount(2);
});

it('sends both Akahu credentials as headers', function () {
    fakeAkahu([]);

    app(BankFeedSyncer::class)->sync($this->feed, CarbonImmutable::parse('2026-10-23T02:00:00Z'));

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer user_token_secret')
        && $request->hasHeader('X-Akahu-Id', 'app_token_secret'));
});

it('records the error and keeps going when Akahu rejects the tokens', function () {
    Http::fake(['api.akahu.io/*' => Http::response(['success' => false], 401)]);

    expect(fn () => app(BankFeedSyncer::class)->sync($this->feed))->toThrow(AkahuException::class);

    expect($this->feed->fresh()->last_error)->not->toBeNull()
        ->and($this->connection->fresh()->last_error)->not->toBeNull();
});

it('refuses a currency mismatch', function () {
    $this->feed->update(['currency' => 'USD']);
    $this->bank->update(['currency_code' => 'NZD']);
    fakeAkahu([akahuTxn('tx_1', '2026-10-22T11:00:00.000Z', -5.00)]);

    expect(fn () => app(BankFeedSyncer::class)->sync($this->feed->fresh()))->toThrow(RuntimeException::class);
    expect(BankStatementLine::query()->count())->toBe(0);
});

it('syncs every linked account from the command and fails when one errors', function () {
    // Http::fake() stubs accumulate and the first match wins, so script both
    // responses in order: a good page first, then a server error.
    Http::fake([
        'api.akahu.io/v1/accounts/acc_1/transactions*' => Http::sequence()
            ->push(['success' => true, 'items' => [akahuTxn('tx_cmd', '2026-10-22T11:00:00.000Z', -3.00)], 'cursor' => ['next' => null]])
            ->push([], 500),
    ]);

    $this->artisan('bank-feeds:sync')->assertSuccessful();
    expect(BankStatementLine::query()->where('external_id', 'tx_cmd')->exists())->toBeTrue();

    $this->artisan('bank-feeds:sync')->assertFailed();
});

it('connects from the settings page, loads accounts and links one', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);

    BankFeedAccount::query()->delete();
    BankFeedConnection::query()->delete();

    Http::fake([
        'api.akahu.io/v1/accounts' => Http::response(['success' => true, 'items' => [
            ['_id' => 'acc_9', 'name' => 'Wise NZD', 'formatted_account' => '12-3456-7890123-00', 'connection' => ['name' => 'Wise'], 'balance' => ['currency' => 'NZD', 'current' => 10]],
        ]]),
    ]);

    $component = Livewire::test('pages::banking.feeds', ['company' => $this->company])
        ->set('appToken', 'app_x')
        ->set('userToken', 'user_x')
        ->call('saveTokens');

    $feed = BankFeedAccount::query()->where('external_id', 'acc_9')->firstOrFail();

    expect($feed->institution)->toBe('Wise')->and($feed->currency)->toBe('NZD');

    $component->set("links.{$feed->id}", $this->bank->id)
        ->set("syncFrom.{$feed->id}", '2026-09-01')
        ->call('saveLink', $feed->id);

    expect($feed->fresh()->account_id)->toBe($this->bank->id)
        ->and($feed->fresh()->sync_from->toDateString())->toBe('2026-09-01');
});

it('runs a sync as a background job and reports progress, then the result', function () {
    fakeAkahu([akahuTxn('tx_job', '2026-10-22T11:00:00.000Z', -7.00)]);

    BankFeedSyncStatus::put($this->feed->id, 'running', 'Starting…');
    expect(BankFeedSyncStatus::isRunning($this->feed->id))->toBeTrue();

    (new SyncBankFeedAccount($this->feed->id, $this->company->id))->handle(app(BankFeedSyncer::class));

    $status = BankFeedSyncStatus::get($this->feed->id);

    expect($status['state'])->toBe('done')
        ->and($status['message'])->toContain('1 new')
        ->and(BankStatementLine::query()->where('external_id', 'tx_job')->exists())->toBeTrue();
});

it('reports a failed background sync instead of throwing', function () {
    Http::fake(['api.akahu.io/*' => Http::response(['success' => false], 401)]);

    (new SyncBankFeedAccount($this->feed->id, $this->company->id))->handle(app(BankFeedSyncer::class));

    $status = BankFeedSyncStatus::get($this->feed->id);

    expect($status['state'])->toBe('error')->and($status['message'])->not->toBe('');
});

it('finishes matching left unfinished by an interrupted sync', function () {
    fakeAkahu([akahuTxn('tx_stuck', '2026-10-22T11:00:00.000Z', -9.00)]);
    app(BankFeedSyncer::class)->sync($this->feed, CarbonImmutable::parse('2026-10-23T02:00:00Z'));

    $import = BankStatementImport::query()->firstOrFail();
    $import->forceFill(['status' => BankStatementImportStatus::Matching->value])->save();

    app(BankFeedSyncer::class)->sync($this->feed->fresh(), CarbonImmutable::parse('2026-10-24T02:00:00Z'));

    expect($import->fresh()->status)->toBe(BankStatementImportStatus::Ready);
});

it('pre-fills the vendor from the feed merchant name when a vendor of that name exists', function () {
    $vendor = app(SaveContact::class)->handle(['display_name' => 'Countdown'], 'is_vendor');

    $known = akahuTxn('tx_known', '2026-10-22T11:00:00.000Z', -12.34);
    $known['merchant'] = ['name' => 'COUNTDOWN'];
    $unknown = akahuTxn('tx_unknown', '2026-10-22T11:00:00.000Z', -8.00, 'SOME CAFE');
    $unknown['merchant'] = ['name' => 'Some Cafe'];
    $income = akahuTxn('tx_income', '2026-10-22T11:00:00.000Z', 50.00, 'REFUND');
    $income['merchant'] = ['name' => 'Countdown'];

    fakeAkahu([$known, $unknown, $income]);
    app(BankFeedSyncer::class)->sync($this->feed, CarbonImmutable::parse('2026-10-23T02:00:00Z'));

    $line = fn (string $id) => BankStatementLine::query()->where('external_id', $id)->firstOrFail();

    expect($line('tx_known')->suggested_contact_id)->toBe($vendor->id)
        ->and($line('tx_unknown')->suggested_contact_id)->toBeNull()
        ->and($line('tx_income')->suggested_contact_id)->toBeNull();
});
