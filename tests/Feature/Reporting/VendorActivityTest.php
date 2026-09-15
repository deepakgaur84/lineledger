<?php

use App\Actions\Banking\SaveCheque;
use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use App\Services\Posting\ChequePoster;
use App\Support\Contacts\ContactLinkResolver;
use App\Support\Reporting\ReportCatalog;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Vendor Activity report
|--------------------------------------------------------------------------
| Every posted transaction with a vendor, read off the GL — including cheques
| coded straight to an expense account, which never touch A/P and so never
| appear on the AP statement. The vendor list's Name drills here; its Open
| balance drills to the AP statement.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'Crossroads Services', 'is_vendor' => true]);
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function vendorActivityCheque(Contact $payee, Account $bank, Account $expense, string $no, int $cents, string $memo = 'Inv 16396'): Cheque
{
    $cheque = app(SaveCheque::class)->handle([
        'bank_account_id' => $bank->id,
        'cheque_no' => $no,
        'cheque_date' => '2026-08-04',
        'payee_contact_id' => $payee->id,
        'payee_name' => null,
        'memo' => $memo,
        'lines' => [['account_id' => $expense->id, 'description' => 'Services', 'amount_cents' => $cents]],
    ]);
    app(ChequePoster::class)->post($cheque);

    return $cheque->fresh();
}

function vendorActivityBill(Contact $vendor, Account $expense, string $no, int $cents): Bill
{
    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => $no,
        'bill_date' => '2026-06-01',
        'due_date' => '2026-07-01',
        'memo' => 'June services',
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Services',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

function vendorActivityReport(array $params = []): Testable
{
    return Livewire::withQueryParams(['start' => '2026-01-01', 'end' => '2026-12-31', ...$params])
        ->test('pages::reports.vendor-activity', ['company' => test()->company]);
}

it('lists a cheque coded straight to an expense account, which the AP statement cannot show', function () {
    vendorActivityCheque($this->vendor, $this->bank, $this->expense, 'e-Transfer', 169376);

    vendorActivityReport(['contact' => $this->vendor->id])
        ->assertSeeHtml('data-test="vendor-activity-row"')
        ->assertSee('Cheque')
        ->assertSee('e-Transfer')
        ->assertSee('Inv 16396')
        ->assertSee($this->bank->name)
        ->assertSee($this->expense->name)
        ->assertSee('1,693.76');

    $this->get(route('reports.contact-statement', ['company' => $this->company->slug, 'contact' => $this->vendor->id, 'kind' => 'ap', 'start' => '2026-01-01', 'end' => '2026-12-31']))
        ->assertOk()
        ->assertSee('No transactions in this range.');
});

it('lists bills and bill payments with A/P as the main account on the bill', function () {
    $bill = vendorActivityBill($this->vendor, $this->expense, 'B-100', 50000);

    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-100',
        'payment_date' => '2026-06-15',
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 50000,
    ]);
    $payment->applications()->create(['bill_id' => $bill->id, 'amount_cents' => 50000]);
    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    $rows = vendorActivityReport(['contact' => $this->vendor->id])->instance()->rows;

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray([
            'type' => 'Bill',
            'doc_no' => 'B-100',
            'memo' => 'June services',
            'account' => trim($this->ap->code.' — '.$this->ap->name, ' —'),
            'split' => [trim($this->expense->code.' — '.$this->expense->name, ' —')],
            'amount' => 50000,
            'is_void' => false,
        ])
        ->and($rows[1])->toMatchArray([
            'type' => 'Bill Payment',
            'doc_no' => 'PAY-100',
            'account' => trim($this->bank->code.' — '.$this->bank->name, ' —'),
            'split' => [trim($this->ap->code.' — '.$this->ap->name, ' —')],
            'amount' => 50000,
        ]);
});

it('shows a voided cheque once, flagged void, and leaves its reversal out', function () {
    $cheque = vendorActivityCheque($this->vendor, $this->bank, $this->expense, 'CHQ-VOID', 169376);
    app(ChequePoster::class)->void($cheque);

    $rows = vendorActivityReport(['contact' => $this->vendor->id, 'end' => $this->company->currentDateTime()->toDateString()])->instance()->rows;

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['doc_no'])->toBe('CHQ-VOID')
        ->and($rows[0]['is_void'])->toBeTrue();
});

it('groups every vendor when unfiltered and leaves out non-vendor names', function () {
    $other = Contact::create(['display_name' => 'Second Vendor', 'is_vendor' => true]);
    $customer = Contact::create(['display_name' => 'Walk-in Customer', 'is_customer' => true]);

    vendorActivityCheque($this->vendor, $this->bank, $this->expense, 'CHQ-A', 1000, 'First memo');
    vendorActivityCheque($other, $this->bank, $this->expense, 'CHQ-B', 2000, 'Second memo');
    vendorActivityCheque($customer, $this->bank, $this->expense, 'CHQ-C', 3000, 'Customer memo');

    vendorActivityReport()
        ->assertSeeHtml('data-test="vendor-activity-group"')
        ->assertSee('Crossroads Services')
        ->assertSee('Second Vendor')
        ->assertSee('First memo')
        ->assertSee('Second memo')
        ->assertDontSee('Customer memo');

    vendorActivityReport(['contact' => $other->id])
        ->assertDontSeeHtml('data-test="vendor-activity-group"')
        ->assertSee('Second memo')
        ->assertDontSee('First memo');
});

it('never shows another company\'s transactions, even when filtered to its vendor id', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreignVendor = Contact::create(['display_name' => 'Foreign Vendor', 'is_vendor' => true]);
    $foreignBank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $foreignExpense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    vendorActivityCheque($foreignVendor, $foreignBank, $foreignExpense, 'CHQ-FOREIGN', 4200, 'Foreign memo');
    app()->instance('current_company', $this->company);

    vendorActivityReport()
        ->assertDontSee('Foreign memo')
        ->assertSee('No vendor transactions in this period.');

    vendorActivityReport(['contact' => $foreignVendor->id])
        ->assertDontSee('Foreign memo')
        ->assertDontSee('CHQ-FOREIGN');
});

it('exports the activity as CSV', function () {
    vendorActivityCheque($this->vendor, $this->bank, $this->expense, 'e-Transfer', 169376);

    vendorActivityReport(['contact' => $this->vendor->id])
        ->call('exportCsv')
        ->assertFileDownloaded('vendor-activity-2026-01-01-to-2026-12-31.csv');
});

it('links each vendor name to its activity and the open balance to its AP statement', function () {
    $this->get(route('vendors.index', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('data-test="vendor-activity-link"', false)
        ->assertSee(app(ContactLinkResolver::class)->vendorActivityUrl($this->vendor, $this->company))
        ->assertSee('data-test="vendor-statement-link"', false)
        ->assertSee(route('reports.contact-statement', ['company' => $this->company->slug, 'contact' => $this->vendor->id, 'kind' => 'ap']));
});

it('reaches older transactions through the vendor list link\'s all-time range', function () {
    $cheque = app(SaveCheque::class)->handle([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => 'CHQ-OLD',
        'cheque_date' => $this->company->currentDateTime()->subYears(2)->toDateString(),
        'payee_contact_id' => $this->vendor->id,
        'payee_name' => null,
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Old', 'amount_cents' => 1500]],
    ]);
    app(ChequePoster::class)->post($cheque);

    $this->get(app(ContactLinkResolver::class)->vendorActivityUrl($this->vendor, $this->company))
        ->assertOk()
        ->assertSee('CHQ-OLD');
});

it('renders names and balances as plain text for a member without report access', function () {
    $vendorsOnly = User::factory()->create();
    $this->company->memberships()->create([
        'user_id' => $vendorsOnly->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Vendors->value],
    ]);
    $this->actingAs($vendorsOnly);

    $this->get(route('vendors.index', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Crossroads Services')
        ->assertDontSee('data-test="vendor-activity-link"', false)
        ->assertDontSee('data-test="vendor-statement-link"', false);

    $this->get(route('reports.vendor-activity', ['company' => $this->company->slug]))
        ->assertForbidden();
});

it('is listed under Vendors & Payables in the report catalog', function () {
    $category = collect(ReportCatalog::for($this->company, $this->user))->firstWhere('label', 'Vendors & Payables');

    expect(collect($category['reports'])->pluck('key'))->toContain('reports.vendor-activity');
});
