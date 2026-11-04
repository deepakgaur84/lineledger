<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
    $this->line = BankStatementLine::factory()->create([
        'bank_statement_import_id' => $this->import->id,
        'account_id' => $this->bank->id,
        'txn_date' => now()->toDateString(),
        'amount_cents' => 10000,
        'description' => 'DEPOSIT',
        'match_status' => 'unmatched',
        'created_journal_entry_id' => null,
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('does not crash while an amount is half-typed in the split dialog', function (string $typed) {
    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('openSplit', $this->line->id)
        ->set('splits', [['account_id' => $this->income->id, 'amount' => $typed, 'tax_code_ids' => []]])
        ->assertOk();
})->with(['6.', '-', 'abc', '6.123', '1050+52.50', '1,000']);

it('refuses to save a split with an unreadable amount, with a message instead of an error', function () {
    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('openSplit', $this->line->id)
        ->set('splits', [['account_id' => $this->income->id, 'amount' => '6.', 'tax_code_ids' => []]])
        ->call('saveSplit')
        ->assertOk();

    expect($this->line->fresh()->created_journal_entry_id)->toBeNull();
});

it('still saves a valid split, including a thousands separator', function () {
    $this->line->forceFill(['amount_cents' => 115000])->save();

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('openSplit', $this->line->id)
        ->set('splits', [['account_id' => $this->income->id, 'amount' => '1,000.00', 'tax_code_ids' => []], ['account_id' => $this->income->id, 'amount' => '150.00', 'tax_code_ids' => []]])
        ->call('saveSplit')
        ->assertOk();

    expect($this->line->fresh()->created_journal_entry_id)->not->toBeNull();
});
