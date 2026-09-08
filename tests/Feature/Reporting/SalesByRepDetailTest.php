<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CreditMemoStatus;
use App\Enums\InvoiceStatus;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Services\Reporting\SalesPurchaseReportBuilder;
use App\Support\Reporting\RepSalesRow;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $this->income2 = Account::create([
        'company_id' => $this->company->id,
        'code' => '4950',
        'name' => 'Consulting Revenue',
        'type' => AccountType::Income->value,
        'subtype' => AccountSubtype::Income->value,
        'normal_balance' => NormalBalance::Credit->value,
    ]);

    $this->rep = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Jane Rep', 'is_employee' => true]);
    $this->customer = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Acme', 'is_customer' => true]);

    $this->start = CarbonImmutable::now()->subMonth();
    $this->end = CarbonImmutable::now()->addDay();
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** Distinct name: a duplicate global helper only breaks the full suite. */
function repSalesLine(Invoice|CreditMemo $doc, Account $account, int $subtotalCents): void
{
    $doc->lines()->create([
        'account_id' => $account->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => $subtotalCents,
        'line_subtotal_cents' => $subtotalCents,
        'line_tax_cents' => 0,
        'line_total_cents' => $subtotalCents,
        'line_order' => 0,
    ]);
}

function repInvoice(Company $company, Contact $customer, ?Contact $rep, string $no, string $status = 'posted'): Invoice
{
    return Invoice::create([
        'company_id' => $company->id,
        'contact_id' => $customer->id,
        'sales_rep_id' => $rep?->id,
        'invoice_no' => $no,
        'invoice_date' => CarbonImmutable::now(),
        'due_date' => CarbonImmutable::now(),
        'status' => $status,
    ]);
}

it('breaks a rep down by revenue account, netting credit memos and ignoring drafts and voids', function () {
    $a = repInvoice($this->company, $this->customer, $this->rep, 'INV-1');
    repSalesLine($a, $this->income, 10000);
    repSalesLine($a, $this->income2, 4000);

    $b = repInvoice($this->company, $this->customer, $this->rep, 'INV-2');
    repSalesLine($b, $this->income2, 6000);

    $draft = repInvoice($this->company, $this->customer, $this->rep, 'INV-3', InvoiceStatus::Draft->value);
    repSalesLine($draft, $this->income, 99999);

    $void = repInvoice($this->company, $this->customer, $this->rep, 'INV-4', InvoiceStatus::Void->value);
    repSalesLine($void, $this->income, 88888);

    $credit = CreditMemo::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->customer->id,
        'sales_rep_id' => $this->rep->id,
        'credit_memo_no' => 'CM-1',
        'credit_memo_date' => CarbonImmutable::now(),
        'status' => CreditMemoStatus::Posted->value,
    ]);
    repSalesLine($credit, $this->income, 2000);

    $rows = app(SalesPurchaseReportBuilder::class)
        ->salesByRepDetail($this->company, $this->start, $this->end, $this->rep->id);

    // One row per (account × document): INV-1 twice, INV-2, CM-1.
    expect($rows)->toHaveCount(4);

    $byAccount = $rows->groupBy(fn (RepSalesRow $r): int => (int) $r->accountId);
    $sum = fn ($rows): int => (int) $rows->sum(fn (RepSalesRow $r): int => $r->amountCents);

    expect($sum($byAccount[$this->income->id]))->toBe(8000)     // 10000 - 2000
        ->and($sum($byAccount[$this->income2->id]))->toBe(10000) // 4000 + 6000
        ->and($sum($rows))->toBe(18000);

    $creditRow = $rows->first(fn (RepSalesRow $r): bool => $r->docNo === 'CM-1');

    expect($creditRow->amountCents)->toBe(-2000)
        ->and($creditRow->routeName)->toBe('credit-memos.show')
        ->and($rows->first(fn (RepSalesRow $r): bool => $r->docNo === 'INV-2')->contact)->toBe('Acme')
        ->and($rows->first(fn (RepSalesRow $r): bool => $r->accountId === $this->income2->id)->accountLabel())
        ->toBe('4950 — Consulting Revenue');
});

it('ties exactly to the rep row on the Sales by Rep summary', function () {
    $a = repInvoice($this->company, $this->customer, $this->rep, 'INV-1');
    repSalesLine($a, $this->income, 12500);
    repSalesLine($a, $this->income2, 7500);

    $credit = CreditMemo::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->customer->id,
        'sales_rep_id' => $this->rep->id,
        'credit_memo_no' => 'CM-1',
        'credit_memo_date' => CarbonImmutable::now(),
        'status' => CreditMemoStatus::Posted->value,
    ]);
    repSalesLine($credit, $this->income2, 3000);

    // An invoice with no rep must not leak into the rep's detail.
    $unattributed = repInvoice($this->company, $this->customer, null, 'INV-9');
    repSalesLine($unattributed, $this->income, 55555);

    $builder = app(SalesPurchaseReportBuilder::class);

    $summary = $builder->salesByDimension($this->company, $this->start, $this->end, 'sales_rep')
        ->firstWhere('key', $this->rep->id);

    $detail = $builder->salesByRepDetail($this->company, $this->start, $this->end, $this->rep->id);

    $detailTotal = (int) $detail->sum(fn (RepSalesRow $r): int => $r->amountCents);

    expect($detailTotal)->toBe($summary['amount_cents'])
        ->and($detailTotal)->toBe(17000);
});

it('does not leak another company\'s documents', function () {
    $mine = repInvoice($this->company, $this->customer, $this->rep, 'INV-1');
    repSalesLine($mine, $this->income, 1000);

    $other = Company::factory()->create();

    // BelongsToCompany forces company_id to the bound company on create, so the
    // other tenant's rows have to be built while that company is bound.
    app()->instance('current_company', $other);

    $otherCustomer = Contact::create(['company_id' => $other->id, 'display_name' => 'Other Co', 'is_customer' => true]);
    $theirs = Invoice::create([
        'company_id' => $other->id,
        'contact_id' => $otherCustomer->id,
        'sales_rep_id' => $this->rep->id,
        'invoice_no' => 'INV-X',
        'invoice_date' => CarbonImmutable::now(),
        'due_date' => CarbonImmutable::now(),
        'status' => InvoiceStatus::Posted->value,
    ]);
    $theirs->lines()->create([
        'account_id' => Account::query()->withoutGlobalScopes()->where('company_id', $other->id)->where('subtype', AccountSubtype::Income->value)->value('id'),
        'description' => 'x', 'quantity' => '1', 'unit_price_cents' => 77777,
        'line_subtotal_cents' => 77777, 'line_tax_cents' => 0, 'line_total_cents' => 77777, 'line_order' => 0,
    ]);

    app()->instance('current_company', $this->company);

    $rows = app(SalesPurchaseReportBuilder::class)
        ->salesByRepDetail($this->company, $this->start, $this->end, $this->rep->id);

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->sum(fn (RepSalesRow $r): int => $r->amountCents))->toBe(1000);
});

it('links each rep on the summary to its detail page and leaves the no-rep row unlinked', function () {
    $withRep = repInvoice($this->company, $this->customer, $this->rep, 'INV-1');
    repSalesLine($withRep, $this->income, 5000);

    $noRep = repInvoice($this->company, $this->customer, null, 'INV-2');
    repSalesLine($noRep, $this->income, 3000);

    $url = route('reports.sales-by-rep-detail', [
        'company' => $this->company->slug,
        'rep' => $this->rep->id,
        'start' => $this->start->toDateString(),
        'end' => $this->end->toDateString(),
    ]);

    Livewire::test('pages::reports.sales-by-rep', ['company' => $this->company])
        ->set('startDate', $this->start->toDateString())
        ->set('endDate', $this->end->toDateString())
        ->assertSeeHtml('data-test="drill-rep"')
        ->assertSeeHtml(e($url))
        ->assertSee('No sales rep');
});

it('renders the detail page grouped by account with a grand total', function () {
    $inv = repInvoice($this->company, $this->customer, $this->rep, 'INV-1');
    repSalesLine($inv, $this->income2, 4200);

    Livewire::test('pages::reports.sales-by-rep-detail', ['company' => $this->company, 'rep' => $this->rep])
        ->set('startDate', $this->start->toDateString())
        ->set('endDate', $this->end->toDateString())
        ->assertOk()
        ->assertSee('Jane Rep')
        ->assertSee('4950 — Consulting Revenue')
        ->assertSee('INV-1')
        ->assertSeeHtml('data-test="rep-detail-total"')
        ->assertSee('42.00');
});

it('offers an income-statement view of the rep, one row per revenue account', function () {
    $inv = repInvoice($this->company, $this->customer, $this->rep, 'INV-1');
    repSalesLine($inv, $this->income, 10000);
    repSalesLine($inv, $this->income2, 4000);

    Livewire::test('pages::reports.sales-by-rep-detail', ['company' => $this->company, 'rep' => $this->rep])
        ->set('startDate', $this->start->toDateString())
        ->set('endDate', $this->end->toDateString())
        ->set('view', 'accounts')
        ->assertOk()
        ->assertSeeHtml('data-test="rep-account-row"')
        ->assertSee('4950 — Consulting Revenue')
        ->assertSee('140.00')            // grand total, both accounts
        // The per-document table is not rendered in this view.
        ->assertDontSeeHtml('data-test="rep-detail-row"');
});

it('compares the rep\'s accounts against the prior period', function () {
    // Prior month — the period 'prior_period' compares against.
    $old = repInvoice($this->company, $this->customer, $this->rep, 'INV-OLD');
    $old->forceFill(['invoice_date' => CarbonImmutable::now()->subMonth()->startOfMonth()->addDays(3)->toDateString()])->save();
    repSalesLine($old, $this->income2, 5000);

    // Current month.
    $now = repInvoice($this->company, $this->customer, $this->rep, 'INV-NOW');
    repSalesLine($now, $this->income2, 8000);

    $start = CarbonImmutable::now()->startOfMonth();
    $end = CarbonImmutable::now()->endOfMonth();

    $component = Livewire::test('pages::reports.sales-by-rep-detail', ['company' => $this->company, 'rep' => $this->rep])
        ->set('view', 'accounts')
        ->set('startDate', $start->toDateString())
        ->set('endDate', $end->toDateString())
        ->set('comparisonBasis', 'prior_period');

    $row = $component->instance()->accountRows->firstWhere('key', $this->income2->id);

    expect($row->amountCents)->toBe(8000)
        ->and($row->priorAmountCents)->toBe(5000)
        ->and($row->changeCents())->toBe(3000);

    $component->assertSee('Prior')->assertSee('80.00')->assertSee('50.00');
});

it('does not compute a prior period while the documents view is showing', function () {
    $inv = repInvoice($this->company, $this->customer, $this->rep, 'INV-1');
    repSalesLine($inv, $this->income, 1000);

    $component = Livewire::test('pages::reports.sales-by-rep-detail', ['company' => $this->company, 'rep' => $this->rep])
        ->set('startDate', $this->start->toDateString())
        ->set('endDate', $this->end->toDateString())
        ->set('view', 'documents')
        ->set('comparisonBasis', 'prior_period');

    expect($component->instance()->priorRows)->toBeEmpty();
});

it('falls back to the documents view for an unknown view parameter', function () {
    Livewire::withQueryParams(['view' => 'bogus'])
        ->test('pages::reports.sales-by-rep-detail', ['company' => $this->company, 'rep' => $this->rep])
        ->assertOk()
        ->assertSet('view', 'documents');
});
