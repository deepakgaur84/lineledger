<?php

use App\Actions\Portal\EnsureStripeAccounts;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Mcp\Tools\Form1099Tool;
use App\Models\Account;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Reporting\Form1099Calculator;
use App\Support\Tax\Form1099Threshold;
use Carbon\CarbonImmutable;
use Laravel\Mcp\Request;
use Livewire\Livewire;

/*
 * The 1099 Summary's reporting threshold and its card-payment exclusion
 * (audit findings D3Purchases-06 and D3Purchases-07).
 *
 * irs.gov, "Am I required to file a Form 1099 or other information return?":
 * "For payments made before 2026, the reporting threshold is $600. For payments
 * made in 2026, the reporting threshold is $2,000."
 *
 * Instructions for Forms 1099-MISC and 1099-NEC: "Payments made with a credit card
 * or payment card and certain other types of payments, including third-party network
 * transactions, must be reported on Form 1099-K by the payment settlement entity ...
 * and are not subject to reporting on Form 1099-MISC or Form 1099-NEC."
 */
beforeEach(function () {
    $this->company = Company::factory()->forCountry(Country::UnitedStates)->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->card = Account::query()->where('subtype', AccountSubtype::CreditCard->value)->orderBy('code')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function f1099cp_vendor(string $name): Contact
{
    return Contact::create(['display_name' => $name, 'is_vendor' => true, 'track_1099' => true, 'tax_number' => '12-3456789']);
}

function f1099cp_billPayment(Contact $vendor, Account $from, int $cents, string $date, ?PaymentMethod $method = null): void
{
    BillPayment::create([
        'contact_id' => $vendor->id,
        'payment_no' => 'PAY-'.uniqid(),
        'payment_date' => $date,
        'paid_from_account_id' => $from->id,
        'payment_method_id' => $method?->id,
        'amount_cents' => $cents,
        'status' => 'posted',
    ]);
}

function f1099cp_expense(Contact $vendor, Account $from, int $cents, string $date, ?PaymentMethod $method = null): void
{
    $expense = Expense::create([
        'payment_account_id' => $from->id,
        'payment_method_id' => $method?->id,
        'expense_date' => $date,
        'payee_contact_id' => $vendor->id,
        'payee_name' => $vendor->display_name,
        'amount_cents' => $cents,
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    $expense->lines()->create(['account_id' => Account::query()->where('code', '6050')->value('id'), 'amount_cents' => $cents, 'line_order' => 0]);
}

function f1099cp_cheque(Contact $vendor, Account $bank, int $cents, string $date): void
{
    Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => 'CHQ-'.uniqid(),
        'cheque_date' => $date,
        'payee_contact_id' => $vendor->id,
        'payee_name' => $vendor->display_name,
        'amount_cents' => $cents,
        'status' => 'posted',
    ]);
}

/**
 * @return array<string, array{contact_id: int, name: string, tax_number: ?string, total_cents: int, meets_threshold: bool}>
 */
function f1099cp_rowsByName(Company $company, int $year): array
{
    $rows = app(Form1099Calculator::class)->rows(
        $company,
        CarbonImmutable::create($year, 1, 1),
        CarbonImmutable::create($year, 12, 31)->endOfDay(),
    );

    return collect($rows)->keyBy('name')->all();
}

// ─── D3Purchases-06: the threshold follows the year the payments were made in ───

it('sets the 1099-NEC threshold at $600 for payments made before 2026 and $2,000 for 2026', function () {
    expect(Form1099Threshold::centsFor(2020))->toBe(60000)
        ->and(Form1099Threshold::centsFor(2025))->toBe(60000)
        ->and(Form1099Threshold::centsFor(2026))->toBe(200000);
});

it('holds a later year at the latest published threshold until the IRS figure is added', function () {
    // From 2027 the $2,000 is indexed for inflation (IRC §6041(h)); the IRS had not
    // published the 2027 figure when this table was last updated.
    expect(Form1099Threshold::isPublished(2025))->toBeTrue()
        ->and(Form1099Threshold::isPublished(2026))->toBeTrue()
        ->and(Form1099Threshold::isPublished(2027))->toBeFalse()
        ->and(Form1099Threshold::latestPublishedYear())->toBe(2026)
        ->and(Form1099Threshold::centsFor(2027))->toBe(200000);
});

it('applies the $2,000 threshold to payments made in 2026 (and $600 before 2026)', function () {
    $vendor = f1099cp_vendor('Contractor 2026');

    // $1,500 paid by bank in each of 2025 and 2026.
    f1099cp_billPayment($vendor, $this->bank, 150000, '2025-06-01');
    f1099cp_billPayment($vendor, $this->bank, 150000, '2026-06-01');

    $rows2025 = f1099cp_rowsByName($this->company, 2025);
    $rows2026 = f1099cp_rowsByName($this->company, 2026);

    expect($rows2025['Contractor 2026']['meets_threshold'])->toBeTrue()
        ->and($rows2026['Contractor 2026']['total_cents'])->toBe(150000)
        ->and($rows2026['Contractor 2026']['meets_threshold'])->toBeFalse();
});

it('meets the threshold at exactly $600 in 2025 and exactly $2,000 in 2026', function () {
    $at600 = f1099cp_vendor('At 600');
    $under600 = f1099cp_vendor('Under 600');
    $at2000 = f1099cp_vendor('At 2000');
    $under2000 = f1099cp_vendor('Under 2000');

    f1099cp_billPayment($at600, $this->bank, 60000, '2025-12-31');
    f1099cp_billPayment($under600, $this->bank, 59999, '2025-12-31');
    f1099cp_billPayment($at2000, $this->bank, 200000, '2026-01-01');
    f1099cp_billPayment($under2000, $this->bank, 199999, '2026-01-01');

    $rows2025 = f1099cp_rowsByName($this->company, 2025);
    $rows2026 = f1099cp_rowsByName($this->company, 2026);

    expect($rows2025['At 600']['meets_threshold'])->toBeTrue()
        ->and($rows2025['Under 600']['meets_threshold'])->toBeFalse()
        ->and($rows2026['At 2000']['meets_threshold'])->toBeTrue()
        ->and($rows2026['Under 2000']['meets_threshold'])->toBeFalse();
});

it('refuses a period that spans two calendar years, since each year has its own threshold', function () {
    app(Form1099Calculator::class)->rows(
        $this->company,
        CarbonImmutable::create(2025, 7, 1),
        CarbonImmutable::create(2026, 6, 30)->endOfDay(),
    );
})->throws(InvalidArgumentException::class);

it('shows the 2026 report against the $2,000 threshold and hides vendors below it', function () {
    $big = f1099cp_vendor('Big Contractor');
    $mid = f1099cp_vendor('Mid Contractor');
    f1099cp_billPayment($big, $this->bank, 250000, '2026-04-01');
    f1099cp_billPayment($mid, $this->bank, 150000, '2026-04-01'); // over $600, under $2,000

    $page = Livewire::actingAs($this->user)
        ->test('pages::reports.form-1099', ['company' => $this->company])
        ->set('year', 2026)
        ->assertSee('Show below $2,000.00')
        ->assertDontSee('Show below $600');

    expect(collect($page->instance()->rows())->pluck('name')->all())->toBe(['Big Contractor']);

    $page->set('showAll', true)->assertSee('below $2,000.00');

    expect(collect($page->instance()->rows())->pluck('name')->all())->toBe(['Big Contractor', 'Mid Contractor']);
});

it('keeps the $600 threshold on the 2025 report', function () {
    $mid = f1099cp_vendor('Mid Contractor');
    f1099cp_billPayment($mid, $this->bank, 150000, '2025-04-01');

    $page = Livewire::actingAs($this->user)
        ->test('pages::reports.form-1099', ['company' => $this->company])
        ->set('year', 2025)
        ->assertSee('Show below $600.00');

    expect(collect($page->instance()->rows())->pluck('name')->all())->toBe(['Mid Contractor']);
});

it('says when the IRS has not published the year’s threshold yet', function () {
    Livewire::actingAs($this->user)
        ->test('pages::reports.form-1099', ['company' => $this->company])
        ->set('year', 2027)
        ->assertSee('The IRS has not published the 2027 reporting threshold yet');

    Livewire::actingAs($this->user)
        ->test('pages::reports.form-1099', ['company' => $this->company])
        ->set('year', 2026)
        ->assertDontSee('has not published');
});

it('prints the year’s threshold on the PDF', function () {
    $html = view('pdf.reports.form-1099', [
        'company' => $this->company,
        'rows' => [['contact_id' => 1, 'name' => 'Mid Contractor', 'tax_number' => null, 'total_cents' => 150000, 'meets_threshold' => false]],
        'totals' => ['total' => 150000, 'count' => 1],
        'year' => 2026,
        'threshold' => '$2,000.00',
    ])->render();

    expect($html)->toContain('Mid Contractor (below $2,000.00)')
        ->and($html)->toContain('Reporting threshold: $2,000.00')
        ->and($html)->not->toContain('$600');
});

it('downloads the 1099 Summary PDF', function () {
    f1099cp_billPayment(f1099cp_vendor('Big Contractor'), $this->bank, 250000, '2026-04-01');

    Livewire::actingAs($this->user)
        ->test('pages::reports.form-1099', ['company' => $this->company])
        ->set('year', 2026)
        ->call('exportPdf')
        ->assertFileDownloaded('1099-summary-2026.pdf');
});

it('reports the year’s threshold through the MCP tool', function () {
    f1099cp_billPayment(f1099cp_vendor('Mid Contractor'), $this->bank, 150000, '2026-04-01');

    bindMcpTenant($this->company);

    $text2026 = (string) app(Form1099Tool::class)->handle(new Request(['year' => 2026]))->content();
    $text2027 = (string) app(Form1099Tool::class)->handle(new Request(['year' => 2027]))->content();

    expect($text2026)->toContain('reporting threshold $2,000.00')
        ->and($text2026)->toContain('Mid Contractor: $1,500.00 (below threshold)')
        ->and($text2026)->not->toContain('600.00')
        ->and($text2027)->toContain('has not published the 2027 threshold');
});

// ─── D3Purchases-07: card and payment-app payments are reported on Form 1099-K ───

it('leaves out expenses charged to a credit card account', function () {
    expect($this->card)->not->toBeNull();

    $vendor = f1099cp_vendor('Card Paid Contractor');

    // $500 by bank (reportable) + $700 charged to the company credit card (1099-K, not 1099-NEC).
    f1099cp_billPayment($vendor, $this->bank, 50000, '2025-03-01');
    f1099cp_expense($vendor, $this->card, 70000, '2025-04-01');

    $row = f1099cp_rowsByName($this->company, 2025)['Card Paid Contractor'];

    expect($row['total_cents'])->toBe(50000)
        ->and($row['meets_threshold'])->toBeFalse();
});

it('leaves out bill payments made from a credit card account', function () {
    $vendor = f1099cp_vendor('Card Bill Contractor');

    f1099cp_billPayment($vendor, $this->bank, 40000, '2025-03-01');
    f1099cp_billPayment($vendor, $this->card, 30000, '2025-04-01');

    $row = f1099cp_rowsByName($this->company, 2025)['Card Bill Contractor'];

    expect($row['total_cents'])->toBe(40000)
        ->and($row['meets_threshold'])->toBeFalse();
});

it('leaves out bank-funded payments made with a card or payment-app method', function () {
    $vendor = f1099cp_vendor('Mixed Contractor');

    $debitCard = PaymentMethod::create(['name' => 'Debit card', 'is_card' => true]);
    $payPal = PaymentMethod::create(['name' => 'PayPal', 'is_card' => true]);
    $ach = PaymentMethod::query()->where('name', 'ACH')->firstOrFail();

    f1099cp_expense($vendor, $this->bank, 11000, '2025-02-01', $debitCard);  // 1099-K
    f1099cp_billPayment($vendor, $this->bank, 22000, '2025-03-01', $payPal); // 1099-K
    f1099cp_billPayment($vendor, $this->bank, 40000, '2025-04-01', $ach);    // 1099-NEC
    f1099cp_expense($vendor, $this->bank, 5000, '2025-05-01');               // no method: 1099-NEC
    f1099cp_cheque($vendor, $this->bank, 25000, '2025-06-01');               // a check: 1099-NEC

    $row = f1099cp_rowsByName($this->company, 2025)['Mixed Contractor'];

    expect($row['total_cents'])->toBe(70000)
        ->and($row['meets_threshold'])->toBeTrue();
});

it('can push a vendor under the threshold once the card payments are left out', function () {
    $vendor = f1099cp_vendor('Mostly Card Contractor');

    f1099cp_billPayment($vendor, $this->bank, 150000, '2026-02-01');
    f1099cp_expense($vendor, $this->card, 100000, '2026-03-01');

    $row = f1099cp_rowsByName($this->company, 2026)['Mostly Card Contractor'];

    expect($row['total_cents'])->toBe(150000)
        ->and($row['meets_threshold'])->toBeFalse();
});

it('marks the seeded Credit card method as a card method', function () {
    $methods = PaymentMethod::query()->pluck('is_card', 'name');

    expect($methods['Credit card'])->toBeTrue()
        ->and($methods['Check'])->toBeFalse()
        ->and($methods['ACH'])->toBeFalse()
        ->and($methods['Wire'])->toBeFalse()
        ->and($methods['Cash'])->toBeFalse();
});

it('marks the Card (Stripe) method as a card method when Stripe is connected', function () {
    $method = app(EnsureStripeAccounts::class)->handle($this->company)['method'];

    expect($method->name)->toBe('Card (Stripe)')
        ->and($method->fresh()->is_card)->toBeTrue();
});

it('lets a US organization mark a payment method as a card or payment app', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::settings.lists.payment-methods', ['company' => $this->company])
        ->assertSee('Card or payment app')
        ->call('openCreate')
        ->set('f_name', 'Venmo')
        ->set('f_is_card', true)
        ->call('save')
        ->assertHasNoErrors();

    $venmo = PaymentMethod::query()->where('name', 'Venmo')->firstOrFail();
    expect($venmo->is_card)->toBeTrue();

    Livewire::test('pages::settings.lists.payment-methods', ['company' => $this->company])
        ->call('openEdit', $venmo->id)
        ->assertSet('f_is_card', true)
        ->set('f_is_card', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($venmo->fresh()->is_card)->toBeFalse();
});

it('keeps the card switch off the payment methods page outside the US', function () {
    app()->forgetInstance('current_company');

    $ca = Company::factory()->forCountry(Country::Canada, 'ON')->create();
    $ca->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $ca);
    $this->actingAs($this->user);

    $card = PaymentMethod::query()->where('name', 'Credit card')->firstOrFail();

    Livewire::test('pages::settings.lists.payment-methods', ['company' => $ca])
        ->assertDontSee('Card or payment app')
        ->call('openEdit', $card->id)
        ->set('f_name', 'Credit card (Visa)')
        ->call('save')
        ->assertHasNoErrors();

    // Saving from a page that never showed the switch leaves the flag as it was.
    expect($card->fresh()->is_card)->toBeTrue();
});
