<?php

// Fork: "Generate now" on a schedule page creates every occurrence that is
// already due (a schedule started months ago catches up in one go) instead of
// one occurrence per click.

use App\Actions\Accounting\SaveRecurringJournalEntry;
use App\Actions\Recurring\SaveRecurringDocument;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\RecurringDocument;
use App\Models\RecurringJournalEntry;
use App\Services\Recurring\RecurringDocumentGenerator;
use App\Services\Recurring\RecurringJournalEntryGenerator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-05-24 12:00:00');

    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->firstOrFail();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

function catchUpInvoiceSchedule(string $start): RecurringDocument
{
    return app(SaveRecurringDocument::class)->handle([
        'document_type' => 'invoice',
        'contact_id' => test()->customer->id,
        'terms_id' => null,
        'memo' => 'Monthly retainer',
        'name' => 'Acme retainer',
        'frequency' => 'monthly',
        'start_date' => $start,
        'day_of_month' => 24,
        'end_type' => 'never',
        'lines' => [[
            'item_id' => null,
            'account_id' => test()->incomeAccount->id,
            'description' => 'Service',
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'tax_code_id' => null,
        ]],
    ]);
}

function catchUpJournalSchedule(string $start): RecurringJournalEntry
{
    return app(SaveRecurringJournalEntry::class)->handle([
        'name' => 'Monthly depreciation',
        'memo' => 'Depreciation',
        'frequency' => 'monthly',
        'start_date' => $start,
        'day_of_month' => 24,
        'end_type' => 'never',
        'lines' => [
            ['account_id' => test()->expense->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => test()->bank->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ]);
}

it('generates every due invoice occurrence in one go', function () {
    $schedule = catchUpInvoiceSchedule('2026-01-24'); // Jan, Feb, Mar, Apr, May are due

    $created = app(RecurringDocumentGenerator::class)->generateAllDue($schedule);

    expect($created)->toHaveCount(5)
        ->and(Invoice::query()->where('recurring_document_id', $schedule->id)->count())->toBe(5);

    $fresh = $schedule->fresh();
    expect($fresh->occurrences_generated)->toBe(5)
        ->and($fresh->next_run_date->toDateString())->toBe('2026-06-24');
});

it('creates the next occurrence early when none is due yet', function () {
    $schedule = catchUpInvoiceSchedule('2026-06-24');

    $created = app(RecurringDocumentGenerator::class)->generateAllDue($schedule);

    expect($created)->toHaveCount(1)
        ->and($created->first()->invoice_date->toDateString())->toBe('2026-06-24')
        ->and($schedule->fresh()->occurrences_generated)->toBe(1);
});

it('refuses to generate from a paused invoice schedule', function () {
    $schedule = catchUpInvoiceSchedule('2026-01-24');
    $schedule->update(['is_active' => false]);

    expect(fn () => app(RecurringDocumentGenerator::class)->generateAllDue($schedule))
        ->toThrow(RuntimeException::class, 'paused or ended');

    expect(Invoice::query()->where('recurring_document_id', $schedule->id)->count())->toBe(0);
});

it('generates every due journal occurrence in one go', function () {
    $schedule = catchUpJournalSchedule('2026-01-24');

    $created = app(RecurringJournalEntryGenerator::class)->generateAllDue($schedule);

    expect($created)->toHaveCount(5)
        ->and(JournalEntry::query()->where('recurring_journal_entry_id', $schedule->id)->count())->toBe(5);

    $fresh = $schedule->fresh();
    expect($fresh->occurrences_generated)->toBe(5)
        ->and($fresh->next_run_date->toDateString())->toBe('2026-06-24');
});

it('creates the next journal occurrence early when none is due yet', function () {
    $schedule = catchUpJournalSchedule('2026-06-24');

    $created = app(RecurringJournalEntryGenerator::class)->generateAllDue($schedule);

    expect($created)->toHaveCount(1)
        ->and($schedule->fresh()->occurrences_generated)->toBe(1);
});

it('catches up from the recurring invoice schedule page and stays on it', function () {
    $schedule = catchUpInvoiceSchedule('2026-01-24');

    Livewire::test('pages::recurring.show', ['company' => $this->company, 'recurring' => $schedule])
        ->call('generateNow')
        ->assertNoRedirect();

    expect(Invoice::query()->where('recurring_document_id', $schedule->id)->count())->toBe(5);
});

it('catches up from the recurring journal schedule page and stays on it', function () {
    $schedule = catchUpJournalSchedule('2026-01-24');

    Livewire::test('pages::recurring-journal.show', ['company' => $this->company, 'recurring' => $schedule])
        ->call('generateNow')
        ->assertNoRedirect();

    expect(JournalEntry::query()->where('recurring_journal_entry_id', $schedule->id)->count())->toBe(5);
});
