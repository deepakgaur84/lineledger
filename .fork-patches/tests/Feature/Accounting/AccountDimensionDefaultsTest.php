<?php

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Location;
use App\Services\Posting\JournalPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->class = Classification::create(['name' => 'Auckland']);
    $this->otherClass = Classification::create(['name' => 'Wellington']);
    $this->location = Location::create(['name' => 'Head office']);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function saveAccountWith(Account $account, array $extra): Account
{
    return app(SaveAccount::class)->handle([
        'code' => $account->code,
        'name' => $account->name,
        'subtype' => $account->subtype->value,
    ] + $extra, $account);
}

function postedLines(array $legs): \Illuminate\Support\Collection
{
    $entry = JournalEntry::create([
        'company_id' => app('current_company')->id,
        'entry_no' => 'JE-DIM-'.fake()->unique()->numerify('#####'),
        'entry_date' => now()->toDateString(),
        'memo' => 'Dimension defaults',
    ]);

    foreach ($legs as $order => $leg) {
        $entry->lines()->create($leg + ['line_order' => $order]);
    }

    app(JournalPoster::class)->post($entry);

    return JournalLine::query()->where('journal_entry_id', $entry->id)->get();
}

it('saves, keeps and clears an account default class and location', function () {
    saveAccountWith($this->expense, ['default_class_id' => $this->class->id, 'default_location_id' => $this->location->id]);
    expect($this->expense->fresh()->default_class_id)->toBe($this->class->id)
        ->and($this->expense->fresh()->default_location_id)->toBe($this->location->id);

    // Key absent: untouched.
    saveAccountWith($this->expense, []);
    expect($this->expense->fresh()->default_class_id)->toBe($this->class->id);

    saveAccountWith($this->expense, ['default_class_id' => null, 'default_location_id' => null]);
    expect($this->expense->fresh()->default_class_id)->toBeNull()
        ->and($this->expense->fresh()->default_location_id)->toBeNull();
});

it('tags an untagged line from its own account default', function () {
    saveAccountWith($this->expense, ['default_class_id' => $this->class->id, 'default_location_id' => $this->location->id]);

    $lines = postedLines([
        ['account_id' => $this->expense->id, 'debit_cents' => 1000, 'credit_cents' => 0],
        ['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 1000],
    ]);

    $expense = $lines->firstWhere('account_id', $this->expense->id);
    $bank = $lines->firstWhere('account_id', $this->bank->id);

    expect($expense->class_id)->toBe($this->class->id)
        ->and($expense->location_id)->toBe($this->location->id)
        ->and($bank->class_id)->toBeNull();
});

it('lets the expense line inherit the bank account default when it has none of its own', function () {
    saveAccountWith($this->bank, ['default_class_id' => $this->class->id, 'default_location_id' => $this->location->id]);

    $lines = postedLines([
        ['account_id' => $this->expense->id, 'debit_cents' => 2000, 'credit_cents' => 0],
        ['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 2000],
    ]);

    expect($lines->firstWhere('account_id', $this->expense->id)->class_id)->toBe($this->class->id)
        ->and($lines->firstWhere('account_id', $this->expense->id)->location_id)->toBe($this->location->id)
        ->and($lines->firstWhere('account_id', $this->bank->id)->class_id)->toBe($this->class->id);
});

it('prefers the expense account default over the bank default, and never overrides an explicit tag', function () {
    saveAccountWith($this->bank, ['default_class_id' => $this->class->id]);
    saveAccountWith($this->expense, ['default_class_id' => $this->otherClass->id]);

    $own = postedLines([
        ['account_id' => $this->expense->id, 'debit_cents' => 500, 'credit_cents' => 0],
        ['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 500],
    ]);

    expect($own->firstWhere('account_id', $this->expense->id)->class_id)->toBe($this->otherClass->id);

    $explicit = postedLines([
        ['account_id' => $this->expense->id, 'debit_cents' => 700, 'credit_cents' => 0, 'class_id' => $this->class->id],
        ['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 700],
    ]);

    expect($explicit->firstWhere('account_id', $this->expense->id)->class_id)->toBe($this->class->id);
});

it('does not push a bank default onto balance-sheet lines', function () {
    saveAccountWith($this->bank, ['default_class_id' => $this->class->id]);

    $lines = postedLines([
        ['account_id' => $this->bank->id, 'debit_cents' => 900, 'credit_cents' => 0],
        ['account_id' => $this->ar->id, 'debit_cents' => 0, 'credit_cents' => 900],
    ]);

    expect($lines->firstWhere('account_id', $this->ar->id)->class_id)->toBeNull();
});

it('sets the defaults from the chart of accounts form', function () {
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $this->bank->id)
        ->set('form_default_class_id', $this->class->id)
        ->set('form_default_location_id', $this->location->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->bank->fresh()->default_class_id)->toBe($this->class->id)
        ->and($this->bank->fresh()->default_location_id)->toBe($this->location->id);
});
