<?php

// Fork: the bank register's Source column says whether a row came from a bank feed,
// an imported statement, or was entered by hand.

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\BankFeedAccount;
use App\Models\BankFeedConnection;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $connection = BankFeedConnection::create([
        'company_id' => $this->company->id,
        'provider' => 'akahu',
        'app_token' => 'app_token_secret',
        'user_token' => 'user_token_secret',
    ]);
    $this->feedAccount = BankFeedAccount::create([
        'company_id' => $this->company->id,
        'bank_feed_connection_id' => $connection->id,
        'account_id' => $this->bank->id,
        'external_id' => 'acc_1',
        'name' => 'ANZ Everyday',
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function sourcePost(string $no, int $cents, string $memo): JournalEntry
{
    $entry = JournalEntry::create(['entry_no' => $no, 'entry_date' => '2025-03-10', 'memo' => $memo]);
    $entry->lines()->createMany([
        ['account_id' => test()->bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0],
        ['account_id' => test()->income->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1],
    ]);

    return app(JournalPoster::class)->post($entry);
}

function sourceOf($test, JournalEntry $entry): array
{
    $register = Livewire::test('pages::banking.register', ['company' => $test->company])
        ->set('account_id', $test->bank->id);

    $line = $entry->lines()->where('account_id', $test->bank->id)->firstOrFail();

    return $register->instance()->lineSources[$line->id];
}

it('labels a row created from a bank feed line as Bank feed', function () {
    $entry = sourcePost('JE-FEED', 10000, 'Feed row');
    $import = BankStatementImport::factory()->create([
        'account_id' => $this->bank->id,
        'bank_feed_account_id' => $this->feedAccount->id,
    ]);
    BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $this->bank->id,
        'created_journal_entry_id' => $entry->id,
    ]);

    expect(sourceOf($this, $entry))->toBe(['origin' => 'feed', 'matched' => null]);

    Livewire::test('pages::banking.register', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->assertSeeHtml('data-source="feed"');
});

it('labels a row created from an uploaded statement as Statement import', function () {
    $entry = sourcePost('JE-IMP', 5000, 'Imported row');
    $import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
    BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $this->bank->id,
        'created_journal_entry_id' => $entry->id,
    ]);

    expect(sourceOf($this, $entry))->toBe(['origin' => 'import', 'matched' => null]);
});

it('labels a hand-entered row Manual, noting when it was matched to a feed line', function () {
    $plain = sourcePost('JE-MAN', 2000, 'Typed in');
    expect(sourceOf($this, $plain))->toBe(['origin' => 'manual', 'matched' => null]);

    $matched = sourcePost('JE-MATCH', 3000, 'Typed then matched');
    $import = BankStatementImport::factory()->create([
        'account_id' => $this->bank->id,
        'bank_feed_account_id' => $this->feedAccount->id,
    ]);
    BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $this->bank->id,
        'matched_journal_line_id' => $matched->lines()->where('account_id', $this->bank->id)->value('id'),
    ]);

    expect(sourceOf($this, $matched))->toBe(['origin' => 'manual', 'matched' => 'feed']);
});
