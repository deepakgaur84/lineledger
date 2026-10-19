<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    // A New Zealand-style April fiscal year, and "today" inside Apr 2026 – Mar 2027.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 4, 'features_budgets' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $this->postIncome = function (string $date, int $cents): void {
        $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
        $entry->lines()->create(['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 0]);
        $entry->lines()->create(['account_id' => $this->bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 1]);
    };

    $this->makeBudget = function (string $name, int $fiscalYear, int $monthOneCents = 1000000): Budget {
        $budget = Budget::create(['name' => $name, 'fiscal_year' => $fiscalYear]);
        $budget->lines()->create(['account_id' => $this->income->id, 'month_1_cents' => $monthOneCents]);

        return $budget;
    };
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('spells out the months a budget covers', function () {
    $nz = Budget::create(['name' => 'Base', 'fiscal_year' => 2027]);

    expect($nz->periodStart()->toDateString())->toBe('2027-04-01')
        ->and($nz->periodEnd()->toDateString())->toBe('2028-03-31')
        ->and($nz->periodLabel())->toBe('Apr 2027 – Mar 2028')
        ->and($nz->pickerLabel())->toBe('Base (Apr 2027 – Mar 2028)');

    $calendar = Company::factory()->create(['fiscal_year_start_month' => 1]);
    app()->instance('current_company', $calendar);
    $jan = Budget::create(['name' => 'Cal', 'fiscal_year' => 2026]);

    expect($jan->periodLabel())->toBe('Jan – Dec 2026')
        ->and($jan->pickerLabel())->toBe('Cal (2026)');
});

it('moves Budget vs. Actual to the budget\'s own period when the default dates miss it', function () {
    // "Base (2027)" begins in April 2027 — after today's fiscal year (Apr 2026 – Mar 2027).
    $budget = ($this->makeBudget)('Base', 2027);

    $component = Livewire::test('pages::reports.budget-vs-actual', ['company' => $this->company])
        ->assertSet('budgetId', $budget->id)
        ->assertSet('startDate', '2027-04-01')
        ->assertSet('endDate', '2028-03-31');

    $row = collect($component->instance()->report()['groups']['income']['rows'])->firstWhere('code', $this->income->code);

    expect($row['budget'])->toBe(1000000);
});

it('keeps the default fiscal-year-to-date dates when the budget is for the current year', function () {
    ($this->makeBudget)('Current', 2026);

    Livewire::test('pages::reports.budget-vs-actual', ['company' => $this->company])
        ->assertSet('startDate', '2026-04-01');
});

it('never overrides dates the user asked for, but explains the empty Budget column', function () {
    ($this->makeBudget)('Base', 2027);

    Livewire::withQueryParams(['start' => '2026-04-01', 'end' => '2027-03-31'])
        ->test('pages::reports.budget-vs-actual', ['company' => $this->company])
        ->assertSet('startDate', '2026-04-01')
        ->assertSet('endDate', '2027-03-31')
        ->assertSeeHtml('data-test="budget-out-of-range"')
        ->call('showBudgetPeriod')
        ->assertSet('startDate', '2027-04-01')
        ->assertSet('endDate', '2028-03-31')
        ->assertDontSeeHtml('data-test="budget-out-of-range"');
});

it('realigns the dates when another budget is picked', function () {
    $current = ($this->makeBudget)('Current', 2026);
    $next = ($this->makeBudget)('Next', 2027);

    Livewire::test('pages::reports.budget-vs-actual', ['company' => $this->company])
        ->set('budgetId', $current->id)
        ->set('startDate', '2026-04-01')
        ->set('endDate', '2027-03-31')
        ->set('budgetId', $next->id)
        ->assertSet('startDate', '2027-04-01')
        ->assertSet('endDate', '2028-03-31');
});

it('shows actuals and budget by month for the current fiscal year', function () {
    ($this->postIncome)('2026-04-10', 80000);
    $budget = ($this->makeBudget)('Current', 2026, 100000);

    $report = Livewire::test('pages::reports.budget-vs-actual-by-month', ['company' => $this->company])
        ->set('budgetId', $budget->id)
        ->set('metric', 'actual')
        ->assertDontSeeHtml('data-test="budget-period-future"')
        ->instance()
        ->report();

    $row = collect($report['income']['rows'])->firstWhere('code', $this->income->code);

    expect($row['cells'][1])->toBe(80000);
});

it('says so when the budget\'s period has not started, instead of a grid of zeros', function () {
    ($this->makeBudget)('Base', 2027);

    Livewire::test('pages::reports.budget-vs-actual-by-month', ['company' => $this->company])
        ->assertSeeHtml('data-test="budget-period-future"')
        ->assertSee('Apr 2027 – Mar 2028');
});

it('puts the PDF, print and email controls on all three budget reports', function (string $component) {
    ($this->makeBudget)('Current', 2026);

    Livewire::test($component, ['company' => $this->company])
        ->assertSeeHtml('data-test="print-report"')
        ->assertSeeHtml('data-test="email-trigger"')
        ->assertSee('Download');
})->with([
    'budget vs actual' => 'pages::reports.budget-vs-actual',
    'by month' => 'pages::reports.budget-vs-actual-by-month',
    'overview' => 'pages::reports.budget-overview',
]);

it('downloads each budget report as a PDF', function (string $component) {
    ($this->postIncome)('2026-05-02', 50000);
    ($this->makeBudget)('Current', 2026);

    $response = Livewire::test($component, ['company' => $this->company])->instance()->exportPdf();

    expect($response)->toBeInstanceOf(BinaryFileResponse::class)
        ->and(substr((string) file_get_contents($response->getFile()->getPathname()), 0, 4))->toBe('%PDF');
})->with([
    'budget vs actual' => 'pages::reports.budget-vs-actual',
    'by month' => 'pages::reports.budget-vs-actual-by-month',
    'overview' => 'pages::reports.budget-overview',
]);

it('still produces a PDF when no budget exists', function (string $component) {
    $response = Livewire::test($component, ['company' => $this->company])->instance()->exportPdf();

    expect(substr((string) file_get_contents($response->getFile()->getPathname()), 0, 4))->toBe('%PDF');
})->with([
    'budget vs actual' => 'pages::reports.budget-vs-actual',
    'by month' => 'pages::reports.budget-vs-actual-by-month',
    'overview' => 'pages::reports.budget-overview',
]);

it('prints each budget report inline for the budget on screen', function (string $reportKey) {
    $budget = ($this->makeBudget)('Current', 2026);

    $response = $this->get(route('reports.print', [
        'company' => $this->company->slug,
        'reportKey' => $reportKey,
        'budgetId' => $budget->id,
    ]));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
})->with(['reports.budget-vs-actual', 'reports.budget-vs-actual-by-month', 'reports.budget-overview']);
