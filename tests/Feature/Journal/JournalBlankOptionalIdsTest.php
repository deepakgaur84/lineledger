<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Accounting\SaveRecurringJournalEntry;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\JournalEntry;
use Livewire\Livewire;

// The bug: a Class (or Location / Fund / Tax code) select left on "—" submits an empty
// string, `?? null` does not catch that, and MySQL in strict mode refuses '' in an
// integer column ("Incorrect integer value: '' for column 'class_id'"). Saving a draft
// and posting both died with a 500. This suite runs on MySQL, so it fails for real
// without the fix rather than passing because a lenient database swallowed the ''.

beforeEach(function () {
    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $this->company);

    $this->assetAccount = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Office Equipment')
        ->firstOrFail();
    $this->bankAccount = Account::query()->where('subtype', AccountSubtype::Bank->value)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * The exact shape of the entry that failed: several debits carrying a Class and one
 * credit line whose Class was left on "—".
 */
function journalFormWithBlankClassLine()
{
    $department = Classification::create(['name' => 'DG', 'is_active' => true]);

    return Livewire::test('pages::journal.form', ['company' => test()->company])
        ->set('lines.0.account_id', test()->assetAccount->id)
        ->set('lines.0.debit', '1533.73')
        ->set('lines.0.class_id', $department->id)
        ->set('lines.1.account_id', test()->bankAccount->id)
        ->set('lines.1.credit', '1533.73')
        ->set('lines.1.class_id', '');
}

/**
 * @return list<array<string, int|null>>
 */
function journalSavedClassIds(): array
{
    return JournalEntry::query()->firstOrFail()
        ->lines()->orderBy('line_order')->get()
        ->map(fn ($line): array => ['class_id' => $line->class_id])
        ->all();
}

it('saves a draft when a line\'s Class is left on "—"', function () {
    journalFormWithBlankClassLine()->call('saveDraft')->assertHasNoErrors();

    $department = Classification::query()->where('name', 'DG')->firstOrFail();

    expect(JournalEntry::query()->count())->toBe(1)
        ->and(journalSavedClassIds())->toBe([['class_id' => $department->id], ['class_id' => null]]);
});

it('posts an entry when a line\'s Class is left on "—"', function () {
    journalFormWithBlankClassLine()->call('postEntry')->assertHasNoErrors();

    $department = Classification::query()->where('name', 'DG')->firstOrFail();

    expect(JournalEntry::query()->count())->toBe(1)
        ->and(journalSavedClassIds())->toBe([['class_id' => $department->id], ['class_id' => null]]);
});

it('stores every blank optional id as null, whoever calls the action', function () {
    // The form, an import and the API all reach this one action, so it is the place
    // that has to be safe — not just the one form that happened to hit it.
    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-10-02',
        'memo' => 'Reclass',
        'lines' => [
            [
                'account_id' => $this->assetAccount->id, 'debit_cents' => 153373, 'credit_cents' => 0,
                'contact_id' => '', 'tax_code_id' => '', 'class_id' => '', 'location_id' => '', 'fund_id' => '',
            ],
            [
                'account_id' => $this->bankAccount->id, 'debit_cents' => 0, 'credit_cents' => 153373,
                'contact_id' => null, 'tax_code_id' => '0', 'class_id' => '0', 'location_id' => 0,
            ],
        ],
    ]);

    foreach ($entry->lines()->get() as $line) {
        expect([$line->contact_id, $line->tax_code_id, $line->class_id, $line->location_id, $line->fund_id])
            ->toBe([null, null, null, null, null]);
    }
});

it('keeps real ids exactly as given, whether they arrive as numbers or as strings', function () {
    $department = Classification::create(['name' => 'DG', 'is_active' => true]);

    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-10-02',
        'lines' => [
            ['account_id' => $this->assetAccount->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'class_id' => $department->id],
            ['account_id' => $this->bankAccount->id, 'debit_cents' => 0, 'credit_cents' => 5000, 'class_id' => (string) $department->id],
        ],
    ]);

    expect($entry->lines()->orderBy('line_order')->pluck('class_id')->all())
        ->toBe([$department->id, $department->id]);
});

it('stores blank optional ids as null on a recurring journal entry too', function () {
    // SaveRecurringJournalEntry had the same raw `?? null`.
    $schedule = app(SaveRecurringJournalEntry::class)->handle([
        'name' => 'Monthly reclass',
        'memo' => 'Reclass',
        'frequency' => 'monthly',
        'start_date' => '2026-10-02',
        'day_of_month' => 2,
        'end_type' => 'never',
        'lines' => [
            [
                'account_id' => $this->assetAccount->id, 'debit_cents' => 5000, 'credit_cents' => 0,
                'contact_id' => '', 'class_id' => '', 'location_id' => '', 'fund_id' => '',
            ],
            ['account_id' => $this->bankAccount->id, 'debit_cents' => 0, 'credit_cents' => 5000, 'class_id' => ''],
        ],
    ]);

    foreach ($schedule->lines()->get() as $line) {
        expect([$line->contact_id, $line->class_id, $line->location_id, $line->fund_id])->toBe([null, null, null, null]);
    }
});
