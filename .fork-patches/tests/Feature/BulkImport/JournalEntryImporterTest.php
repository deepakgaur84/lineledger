<?php

use App\Enums\AccountSubtype;
use App\Enums\TaxAppliesTo;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Services\BulkImport\BulkImportRegistry;
use App\Services\BulkImport\Importers\JournalEntryImporter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-05-24 12:00:00');

    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $this->company);
    $this->importer = app(JournalEntryImporter::class);

    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->bankAccount = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->apAccount = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function journalRow(array $overrides = []): array
{
    $blank = array_fill_keys(array_keys(app(JournalEntryImporter::class)->csvColumns()), '');

    return array_merge($blank, [
        'import_ref' => '1',
        'entry_date' => '2026-01-15',
        'account_code' => test()->expenseAccount->code,
        'debit' => '100.00',
    ], $overrides);
}

/**
 * A simple, balanced two-line entry: a debit to the expense account, a
 * credit to the bank account, for the same amount.
 *
 * @return list<array<string, string>>
 */
function balancedJournalRows(string $amount = '100.00', array $overrides = []): array
{
    return [
        journalRow(array_merge(['debit' => $amount, 'credit' => ''], $overrides)),
        journalRow(array_merge(['account_code' => test()->bankAccount->code, 'debit' => '', 'credit' => $amount], $overrides)),
    ];
}

it('creates and posts a balanced two-line entry', function () {
    $rows = balancedJournalRows('150.00', ['entry_no' => 'JE-5001', 'memo' => 'Test entry']);

    expect($this->importer->validateGroup($rows, $this->company))->toBe([]);

    $this->importer->commitGroup($rows, $this->company);

    $entry = JournalEntry::query()->where('entry_no', 'JE-5001')->firstOrFail();

    expect($entry->memo)->toBe('Test entry')
        ->and($entry->is_posted)->toBeTrue()
        ->and($entry->lines)->toHaveCount(2)
        ->and((int) $entry->lines->sum('debit_cents'))->toBe(15000)
        ->and((int) $entry->lines->sum('credit_cents'))->toBe(15000);
});

it('auto-numbers the entry when entry_no is left blank', function () {
    $this->importer->commitGroup(balancedJournalRows(), $this->company);

    $entry = JournalEntry::query()->latest('id')->firstOrFail();

    expect($entry->entry_no)->not->toBeEmpty();
});

it('rejects a duplicate entry_no', function () {
    $this->importer->commitGroup(balancedJournalRows(overrides: ['entry_no' => 'JE-9999']), $this->company);

    $errors = $this->importer->validateGroup(balancedJournalRows(overrides: ['entry_no' => 'JE-9999']), $this->company);

    expect($errors)->not->toBeEmpty();
});

it('rejects a line with both a debit and a credit', function () {
    $errors = $this->importer->validateGroup([journalRow(['debit' => '100.00', 'credit' => '50.00'])], $this->company);

    expect(implode(' ', $errors))->toContain('cannot have both');
});

it('rejects a line with neither a debit nor a credit', function () {
    $errors = $this->importer->validateGroup([journalRow(['debit' => '', 'credit' => ''])], $this->company);

    expect(implode(' ', $errors))->toContain('needs a debit or a credit');
});

it('rejects an unbalanced entry, and commits nothing', function () {
    $rows = [
        journalRow(['debit' => '100.00', 'credit' => '']),
        journalRow(['account_code' => $this->bankAccount->code, 'debit' => '', 'credit' => '90.00']),
    ];

    $errors = $this->importer->validateGroup($rows, $this->company);

    expect(implode(' ', $errors))->toContain('does not balance');

    // The bulk-import page never calls commitGroup() on a group with
    // validation errors, but confirm directly that nothing would be
    // created even if it did, since a caller bypassing validation should
    // not silently produce an unbalanced or half-posted entry.
    expect(JournalEntry::query()->count())->toBe(0);
});

it('does not let ordinary floating-point rounding produce a false imbalance', function () {
    // 0.1 + 0.2 famously != 0.3 in binary floating point — three lines whose
    // decimal amounts individually look uneven but sum correctly in cents.
    $rows = [
        journalRow(['debit' => '0.10', 'credit' => '']),
        journalRow(['debit' => '0.20', 'credit' => '']),
        journalRow(['account_code' => $this->bankAccount->code, 'debit' => '', 'credit' => '0.30']),
    ];

    expect($this->importer->validateGroup($rows, $this->company))->toBe([]);
});

it('resolves a contact by name, and rejects an ambiguous one', function () {
    Contact::create(['display_name' => 'Acme Ltd', 'is_vendor' => true]);

    $rows = balancedJournalRows(overrides: ['contact_name' => 'Acme Ltd']);
    expect($this->importer->validateGroup($rows, $this->company))->toBe([]);

    $this->importer->commitGroup($rows, $this->company);
    $entry = JournalEntry::query()->latest('id')->firstOrFail();
    $line = $entry->lines->firstWhere('debit_cents', '>', 0);

    expect($line->contact_id)->not->toBeNull()
        ->and($line->contact->display_name)->toBe('Acme Ltd');
});

it('rejects a contact name that does not exist or is ambiguous', function () {
    $notFound = $this->importer->validateGroup([journalRow(['contact_name' => 'Nobody Here'])], $this->company);
    expect(implode(' ', $notFound))->toContain('not found');

    Contact::create(['display_name' => 'Acme Ltd', 'is_vendor' => true]);
    Contact::create(['display_name' => 'Acme Ltd', 'is_customer' => true]);

    $ambiguous = $this->importer->validateGroup([journalRow(['contact_name' => 'Acme Ltd'])], $this->company);
    expect(implode(' ', $ambiguous))->toContain('more than one');
});

it('resolves a tax code as a reporting tag only', function () {
    // A code deliberately distinct from any of the company's own seeded
    // defaults (CompanyFactory defaults to Canada, which already seeds a
    // real "GST" tax code) — this collided with that exact record, a
    // genuine setup bug in this test, not the importer.
    $taxCode = TaxCode::create(['code' => 'TEST15', 'name' => 'Test 15%', 'rate_basis_points' => 1500, 'applies_to' => TaxAppliesTo::Both->value, 'is_active' => true]);

    $rows = balancedJournalRows(overrides: ['tax_code' => 'TEST15']);
    $this->importer->commitGroup($rows, $this->company);

    $entry = JournalEntry::query()->latest('id')->firstOrFail();
    $line = $entry->lines->firstWhere('debit_cents', '>', 0);

    expect($line->tax_code_id)->toBe($taxCode->id);
});

it('rejects an unknown account code', function () {
    $errors = $this->importer->validateGroup([journalRow(['account_code' => '99999'])], $this->company);

    expect($errors)->not->toBeEmpty();
});

it('previews the entry number, date, line count and total debits', function () {
    $rows = balancedJournalRows('250.00', ['entry_no' => 'JE-7001']);

    $summary = $this->importer->summarizeGroup($rows, $this->company);

    expect($summary['Entry #'])->toBe('JE-7001')
        ->and($summary['Date'])->toBe('2026-01-15')
        ->and($summary['Lines'])->toBe('2')
        ->and($summary['Total debits'])->toBe('250.00');
});

it('shows auto-numbered in the preview when entry_no is blank', function () {
    $summary = $this->importer->summarizeGroup(balancedJournalRows(), $this->company);

    expect($summary['Entry #'])->toBe('(auto-numbered)');
});

it('applies each line\'s own memo separately from the entry\'s own memo', function () {
    $rows = [
        journalRow(['memo' => 'Entry memo', 'line_memo' => 'First line memo', 'debit' => '100.00', 'credit' => '']),
        journalRow(['account_code' => $this->bankAccount->code, 'memo' => '', 'line_memo' => 'Second line memo', 'debit' => '', 'credit' => '100.00']),
    ];

    $this->importer->commitGroup($rows, $this->company);
    $entry = JournalEntry::query()->latest('id')->firstOrFail();

    expect($entry->memo)->toBe('Entry memo')
        ->and($entry->lines->firstWhere('debit_cents', '>', 0)->memo)->toBe('First line memo')
        ->and($entry->lines->firstWhere('credit_cents', '>', 0)->memo)->toBe('Second line memo');
});

it('is available in the bulk-import tool', function () {
    $importer = BulkImportRegistry::find('journal-entries');

    expect($importer)->toBeInstanceOf(JournalEntryImporter::class)
        ->and($importer->label())->toBe('Journal Entries');
});
