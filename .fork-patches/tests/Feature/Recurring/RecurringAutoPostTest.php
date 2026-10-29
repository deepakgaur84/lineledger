<?php

// Fork: recurring journal entries and monthly depreciation can post each generated
// entry automatically instead of leaving a draft.

use App\Actions\Accounting\SaveRecurringJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Company;
use App\Models\RecurringJournalEntry;
use App\Models\User;
use App\Services\Assets\DepreciationGenerator;
use App\Services\Recurring\RecurringJournalEntryGenerator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-05-24 12:00:00');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->accumDep = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Accumulated Depreciation')
        ->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

function autoPostJournalSchedule(bool $autoPost, string $start = '2026-03-24'): RecurringJournalEntry
{
    return app(SaveRecurringJournalEntry::class)->handle([
        'name' => 'Monthly rent',
        'memo' => 'Rent',
        'auto_post' => $autoPost,
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

function autoPostAsset(): Asset
{
    // 360000¢ over 36 months → 10000¢ a month; in service April → only April has ended.
    return Asset::factory()->create([
        'in_service_date' => '2026-04-10',
        'cost_cents' => 360000,
        'salvage_value_cents' => 0,
        'useful_life_months' => 36,
        'auto_depreciate' => true,
        'accumulated_depreciation_account_id' => test()->accumDep->id,
        'depreciation_expense_account_id' => test()->expense->id,
    ]);
}

it('leaves recurring journal entries as drafts by default', function () {
    $schedule = autoPostJournalSchedule(false);

    $created = app(RecurringJournalEntryGenerator::class)->generateDue($schedule, CarbonImmutable::parse('2026-05-24'));

    expect($schedule->fresh()->auto_post)->toBeFalse()
        ->and($created)->toHaveCount(3)
        ->and($created->every(fn ($e) => $e->fresh()->is_posted === false))->toBeTrue();
});

it('posts every recurring journal entry when auto-post is on, including catch-up', function () {
    $schedule = autoPostJournalSchedule(true);

    $created = app(RecurringJournalEntryGenerator::class)->generateDue($schedule, CarbonImmutable::parse('2026-05-24'));

    expect($created)->toHaveCount(3)
        ->and($created->every(fn ($e) => $e->fresh()->is_posted === true))->toBeTrue()
        ->and($schedule->fresh()->occurrences_generated)->toBe(3);
});

it('leaves an auto-post journal entry as a draft when its period is locked, and keeps going', function () {
    $this->company->update(['lock_date' => '2026-04-30']);
    $schedule = autoPostJournalSchedule(true);

    $created = app(RecurringJournalEntryGenerator::class)->generateDue($schedule, CarbonImmutable::parse('2026-05-24'));

    $byDate = $created->mapWithKeys(fn ($e) => [$e->fresh()->entry_date->toDateString() => $e->fresh()->is_posted]);

    expect($created)->toHaveCount(3)
        ->and($byDate['2026-03-24'])->toBeFalse()
        ->and($byDate['2026-04-24'])->toBeFalse()
        ->and($byDate['2026-05-24'])->toBeTrue()
        ->and($schedule->fresh()->occurrences_generated)->toBe(3);
});

it('saves the auto-post option through the recurring journal form and shows it on the schedule', function () {
    Livewire::test('pages::recurring-journal.form', ['company' => $this->company])
        ->set('name', 'Monthly rent')
        ->set('frequency', 'monthly')
        ->set('start_date', '2026-05-24')
        ->set('day_of_month', 24)
        ->set('auto_post', true)
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.debit', '50.00')
        ->set('lines.1.account_id', $this->bank->id)
        ->set('lines.1.credit', '50.00')
        ->call('save')
        ->assertHasNoErrors();

    $schedule = RecurringJournalEntry::query()->where('name', 'Monthly rent')->firstOrFail();
    expect($schedule->auto_post)->toBeTrue();

    Livewire::test('pages::recurring-journal.show', ['company' => $this->company, 'recurring' => $schedule])
        ->assertSeeHtml('data-test="recurring-journal-post-mode">Posts automatically</span>');
});

it('keeps monthly depreciation as a draft unless the company setting is on', function () {
    autoPostAsset();

    $created = app(DepreciationGenerator::class)
        ->generateDue($this->company, $this->company->currentDateTime()->startOfDay());

    expect($created)->toHaveCount(1)
        ->and($created->first()->fresh()->is_posted)->toBeFalse();
});

it('posts monthly depreciation when the company setting is on', function () {
    $this->company->forceFill(['settings' => ['assets' => ['auto_post_depreciation' => true]]])->save();
    autoPostAsset();

    $created = app(DepreciationGenerator::class)
        ->generateDue($this->company->fresh(), $this->company->currentDateTime()->startOfDay());

    expect($created)->toHaveCount(1)
        ->and($created->first()->fresh()->is_posted)->toBeTrue();
});

it('toggles the depreciation auto-post setting from the Fixed assets page', function () {
    Livewire::test('pages::assets.index', ['company' => $this->company])
        ->assertSet('autoPostDepreciation', false)
        ->set('autoPostDepreciation', true);

    expect(data_get($this->company->fresh()->settings, 'assets.auto_post_depreciation'))->toBeTrue();

    Livewire::test('pages::assets.index', ['company' => $this->company->fresh()])
        ->assertSet('autoPostDepreciation', true)
        ->set('autoPostDepreciation', false);

    expect(data_get($this->company->fresh()->settings, 'assets.auto_post_depreciation'))->toBeFalse();
});
