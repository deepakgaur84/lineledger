<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Sales\SaveReceipt;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\ReceiptApplication;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * A receipt applies only to posted invoices of its own organization and
 * customer, for no more than each still owes. The Receive payment form builds
 * its apply table on the server and takes only the Apply amounts from the
 * browser; SaveReceipt re-checks every application, so the form, the API and
 * any other caller get the same answer, and posting a draft checks again.
 */
function receiptGuardInvoice(Company $company, Contact $customer, int $cents, string $no, bool $post = true): Invoice
{
    app()->instance('current_company', $company);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => $no,
        'invoice_date' => '2026-10-01',
        'due_date' => '2026-10-31',
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Services',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);

    if ($post) {
        app(InvoicePoster::class)->post($invoice);
    } else {
        $invoice->recalculateTotals();
    }

    return $invoice->fresh();
}

/**
 * @param  list<array{invoice_id: int, amount_cents: int}>  $applications
 */
function receiptGuardData(int $amountCents, array $applications, ?Contact $customer = null): array
{
    return [
        'contact_id' => ($customer ?? test()->customer)->id,
        'receipt_date' => '2026-10-20',
        'deposit_to_account_id' => test()->undeposited->id,
        'amount_cents' => $amountCents,
        'applications' => $applications,
    ];
}

/**
 * Another receipt paying $cents of $invoice, posted — under its own number, so
 * a form already open keeps the number it reserved.
 */
function receiptGuardPay(Invoice $invoice, int $cents): CustomerReceipt
{
    $receipt = app(SaveReceipt::class)->handle([
        ...receiptGuardData($cents, [['invoice_id' => $invoice->id, 'amount_cents' => $cents]], Contact::query()->findOrFail($invoice->contact_id)),
        'receipt_no' => 'ELSEWHERE-'.uniqid(),
    ]);

    app(ReceiptPoster::class)->post($receipt);

    return $receipt->fresh();
}

function receiptGuardApi(string $method, string $uri, array $payload = []): TestResponse
{
    app()->forgetInstance('current_company');

    return test()->json($method, $uri, $payload, ['Authorization' => 'Bearer '.test()->apiKey]);
}

function receiptGuardRow(array $applyTable, int $invoiceId): int
{
    return (int) collect($applyTable)->search(fn (array $row) => $row['invoice_id'] === $invoiceId);
}

beforeEach(function () {
    // Another organization, with an open $500.00 invoice of its own.
    $this->victim = Company::factory()->create();
    app()->instance('current_company', $this->victim);
    $victimCustomer = Contact::create(['display_name' => 'Victim Customer', 'is_customer' => true]);
    $this->victimInvoice = receiptGuardInvoice($this->victim, $victimCustomer, 50000, 'VIC-1');

    // The organization under test: one member, two customers, a $100.00 invoice each.
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Own Customer', 'is_customer' => true]);
    $this->ownInvoice = receiptGuardInvoice($this->company, $this->customer, 10000, 'OWN-1');
    $this->otherCustomer = Contact::create(['display_name' => 'Other Customer', 'is_customer' => true]);
    $this->otherCustomerInvoice = receiptGuardInvoice($this->company, $this->otherCustomer, 10000, 'OWN-2');

    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    ['plaintext' => $this->apiKey] = CompanyApiKey::mint($this->company, 'Receipt guard');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

describe('Receive payment form', function () {
    it('refuses a browser update that points an apply row at another organization\'s invoice', function () {
        $form = Livewire::test('pages::receipts.form', ['company' => $this->company])
            ->call('selectContact', $this->customer->id)
            ->set('amount', '500.00');

        expect(fn () => $form->set('applyTable.0.invoice_id', $this->victimInvoice->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        $victimInvoice = Invoice::withoutGlobalScopes()->findOrFail($this->victimInvoice->id);

        expect($victimInvoice->amount_paid_cents)->toBe(0)
            ->and($victimInvoice->status)->toBe(InvoiceStatus::Posted)
            ->and(ReceiptApplication::query()->where('invoice_id', $this->victimInvoice->id)->exists())->toBeFalse();
    });

    it('refuses a browser update that replaces the whole apply table', function () {
        $form = Livewire::test('pages::receipts.form', ['company' => $this->company])
            ->call('selectContact', $this->customer->id)
            ->set('amount', '100.00');

        // What a crafted request can send: the row now names the other customer's
        // invoice, with a balance big enough to pass any check made against it.
        expect(fn () => $form->set('applyTable', [[
            'invoice_id' => $this->otherCustomerInvoice->id,
            'invoice_no' => 'OWN-1',
            'due_date' => '2026-10-31',
            'balance' => 99999999,
            'apply' => '100.00',
        ]]))->toThrow(CannotUpdateLockedPropertyException::class);

        expect($this->otherCustomerInvoice->fresh()->amount_paid_cents)->toBe(0)
            ->and(CustomerReceipt::query()->count())->toBe(0);
    });

    it('refuses a browser update that inflates an apply row\'s open balance', function () {
        $form = Livewire::test('pages::receipts.form', ['company' => $this->company])
            ->call('selectContact', $this->customer->id)
            ->set('amount', '300.00');

        expect(fn () => $form->set('applyTable.0.balance', 30000))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('refuses a browser update that adds an apply row or swaps one out', function () {
        $form = Livewire::test('pages::receipts.form', ['company' => $this->company])
            ->call('selectContact', $this->customer->id);

        expect(fn () => $form->set('applyTable.5.apply', '10.00'))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        $row = $form->get('applyTable.0');

        expect(fn () => $form->set('applyTable.0', [...$row, 'invoice_id' => $this->otherCustomerInvoice->id]))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        // The same row with only its Apply cell changed goes through.
        $form->set('applyTable.0', [...$row, 'apply' => '25.00'])
            ->assertSet('applyTable.0.apply', '25.00')
            ->assertSet('applyTable.0.invoice_id', $this->ownInvoice->id);
    });

    it('keeps the customer server-side, so the apply table always belongs to it', function () {
        $form = Livewire::test('pages::receipts.form', ['company' => $this->company])
            ->call('selectContact', $this->customer->id);

        expect(fn () => $form->set('contact_id', $this->otherCustomer->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('takes the Apply amounts typed into the table, one cell or every row at once', function () {
        $second = receiptGuardInvoice($this->company, $this->customer, 5000, 'OWN-3');

        $form = Livewire::test('pages::receipts.form', ['company' => $this->company])
            ->call('selectContact', $this->customer->id)
            ->set('amount', '120.00');

        // When every row changes in one round trip the browser sends the whole
        // array; it is accepted as long as only the Apply cells differ.
        $table = $form->get('applyTable');
        $table[receiptGuardRow($table, $this->ownInvoice->id)]['apply'] = '70.00';
        $table[receiptGuardRow($table, $second->id)]['apply'] = '50.00';

        $form->set('applyTable', $table)
            ->call('save')
            ->assertHasNoErrors();

        expect($this->ownInvoice->fresh()->amount_paid_cents)->toBe(7000)
            ->and($second->fresh()->amount_paid_cents)->toBe(5000)
            ->and($second->fresh()->status)->toBe(InvoiceStatus::Paid);
    });

    it('checks the open balance on the server when the table has gone stale', function () {
        $form = Livewire::test('pages::receipts.form', ['company' => $this->company])
            ->call('selectContact', $this->customer->id)
            ->set('amount', '100.00');

        $row = receiptGuardRow($form->get('applyTable'), $this->ownInvoice->id);
        expect($form->get("applyTable.$row.balance"))->toBe(10000);

        // Someone else records $60.00 against the invoice meanwhile.
        app()->instance('current_company', $this->company);
        receiptGuardPay($this->ownInvoice, 6000);

        $form->set("applyTable.$row.apply", '100.00')
            ->call('save')
            ->assertHasErrors('amount');

        expect($this->ownInvoice->fresh()->amount_paid_cents)->toBe(6000)
            ->and(CustomerReceipt::query()->count())->toBe(1);
    });

    it('shows each invoice\'s open balance less what other receipts already paid', function () {
        receiptGuardPay($this->ownInvoice, 2500);

        $form = Livewire::test('pages::receipts.form', ['company' => $this->company])
            ->call('selectContact', $this->customer->id);

        $row = receiptGuardRow($form->get('applyTable'), $this->ownInvoice->id);

        expect($form->get("applyTable.$row.balance"))->toBe(7500);
    });
});

describe('SaveReceipt', function () {
    it('refuses an invoice of another organization and writes nothing', function () {
        expect(fn () => app(SaveReceipt::class)->handle(receiptGuardData(50000, [
            ['invoice_id' => $this->victimInvoice->id, 'amount_cents' => 50000],
        ])))->toThrow(PostingValidationException::class, 'That invoice could not be found.');

        expect(CustomerReceipt::withoutGlobalScopes()->count())->toBe(0)
            ->and(ReceiptApplication::query()->count())->toBe(0)
            ->and(Invoice::withoutGlobalScopes()->findOrFail($this->victimInvoice->id)->amount_paid_cents)->toBe(0);
    });

    it('refuses another customer\'s invoice', function () {
        expect(fn () => app(SaveReceipt::class)->handle(receiptGuardData(10000, [
            ['invoice_id' => $this->otherCustomerInvoice->id, 'amount_cents' => 10000],
        ])))->toThrow(PostingValidationException::class, 'Invoice OWN-2 belongs to a different customer.');
    });

    it('refuses an invoice that is still a draft or has been voided', function () {
        $draft = receiptGuardInvoice($this->company, $this->customer, 10000, 'DRAFT-1', post: false);
        $voided = receiptGuardInvoice($this->company, $this->customer, 10000, 'VOID-1');
        app(InvoicePoster::class)->void($voided);

        expect(fn () => app(SaveReceipt::class)->handle(receiptGuardData(10000, [
            ['invoice_id' => $draft->id, 'amount_cents' => 10000],
        ])))->toThrow(PostingValidationException::class, 'Invoice DRAFT-1 is not open for payment.');

        expect(fn () => app(SaveReceipt::class)->handle(receiptGuardData(10000, [
            ['invoice_id' => $voided->id, 'amount_cents' => 10000],
        ])))->toThrow(PostingValidationException::class, 'Invoice VOID-1 is not open for payment.');
    });

    it('caps an application at the invoice\'s open balance and leaves an overpayment unapplied', function () {
        expect(fn () => app(SaveReceipt::class)->handle(receiptGuardData(30000, [
            ['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 30000],
        ])))->toThrow(PostingValidationException::class, "Cannot apply more than invoice OWN-1's open balance of 100.00.");

        // The same $300.00 applied at most $100.00 is an ordinary overpayment.
        $receipt = app(SaveReceipt::class)->handle(receiptGuardData(30000, [
            ['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 10000],
        ]));
        app(ReceiptPoster::class)->post($receipt);

        expect($this->ownInvoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($receipt->fresh()->unappliedCents())->toBe(20000);
    });

    it('counts what other receipts already paid', function () {
        receiptGuardPay($this->ownInvoice, 6000);

        expect(fn () => app(SaveReceipt::class)->handle(receiptGuardData(4001, [
            ['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 4001],
        ])))->toThrow(PostingValidationException::class, 'open balance of 40.00');

        receiptGuardPay($this->ownInvoice, 4000);

        expect($this->ownInvoice->fresh()->status)->toBe(InvoiceStatus::Paid);

        expect(fn () => app(SaveReceipt::class)->handle(receiptGuardData(100, [
            ['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 100],
        ])))->toThrow(PostingValidationException::class, 'Invoice OWN-1 has nothing left to pay.');
    });

    it('adds up an invoice named twice in one receipt', function () {
        expect(fn () => app(SaveReceipt::class)->handle(receiptGuardData(12000, [
            ['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 6000],
            ['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 6000],
        ])))->toThrow(PostingValidationException::class, 'open balance of 100.00');
    });

    it('refuses an application that is not a positive amount', function () {
        expect(fn () => app(SaveReceipt::class)->handle(receiptGuardData(10000, [
            ['invoice_id' => $this->ownInvoice->id, 'amount_cents' => -5000],
        ])))->toThrow(PostingValidationException::class, 'Apply a positive amount to each invoice.');
    });

    it('lets an edit re-apply what the receipt itself already paid, and no more', function () {
        $receipt = receiptGuardPay($this->ownInvoice, 10000);
        expect($this->ownInvoice->fresh()->status)->toBe(InvoiceStatus::Paid);

        // The paid invoice is still this receipt's to re-apply.
        $edited = app(SaveReceipt::class)->handle([
            ...receiptGuardData(12000, [['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 10000]]),
            'memo' => 'Corrected memo',
        ], $receipt);
        app(ReceiptPoster::class)->repost($edited);

        expect($this->ownInvoice->fresh()->amount_paid_cents)->toBe(10000)
            ->and($edited->fresh()->unappliedCents())->toBe(2000);

        expect(fn () => app(SaveReceipt::class)->handle(receiptGuardData(12000, [
            ['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 10001],
        ]), $edited->fresh()))->toThrow(PostingValidationException::class, 'open balance of 100.00');
    });

    it('caps a foreign-currency application in the invoice\'s own currency', function () {
        app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
        $this->company->refresh();

        $usCustomer = Contact::create(['display_name' => 'US Customer', 'is_customer' => true, 'currency_code' => 'USD']);
        $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

        $invoice = Invoice::create([
            'contact_id' => $usCustomer->id,
            'invoice_no' => 'USD-1',
            'invoice_date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'currency_code' => 'USD',
            'fx_rate' => '1.35',
        ]);
        $invoice->lines()->create([
            'account_id' => $income->id, 'description' => 'Export', 'quantity' => '1',
            'unit_price_cents' => 100000, 'line_subtotal_cents' => 100000,
            'line_tax_cents' => 0, 'line_total_cents' => 100000, 'line_order' => 0,
        ]);
        app(InvoicePoster::class)->post($invoice);

        // US$1,000.00 open: the cap is in dollars, not the CA$1,350.00 it booked at.
        expect(fn () => app(SaveReceipt::class)->handle(receiptGuardData(100001, [
            ['invoice_id' => $invoice->id, 'amount_cents' => 100001],
        ], $usCustomer)))->toThrow(PostingValidationException::class, 'open balance of 1000.00');

        $receipt = app(SaveReceipt::class)->handle(receiptGuardData(100000, [
            ['invoice_id' => $invoice->id, 'amount_cents' => 100000],
        ], $usCustomer));

        expect($receipt->currency_code)->toBe('USD')
            ->and((int) $receipt->applications->sum('amount_cents'))->toBe(100000);
    });
});

describe('Receipts API', function () {
    it('refuses an application above the invoice\'s open balance with a 422', function () {
        receiptGuardApi('POST', '/api/v1/receipts', receiptGuardData(30000, [
            ['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 30000],
        ]))->assertStatus(422)
            ->assertJsonPath('message', "Cannot apply more than invoice OWN-1's open balance of 100.00.");

        expect(ReceiptApplication::query()->where('invoice_id', $this->ownInvoice->id)->exists())->toBeFalse();
    });

    it('still takes an overpayment, leaving the excess as customer credit', function () {
        $id = receiptGuardApi('POST', '/api/v1/receipts', receiptGuardData(15000, [
            ['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 10000],
        ]))->assertStatus(201)->json('data.id');

        expect(CustomerReceipt::withoutGlobalScopes()->findOrFail($id)->unappliedCents())->toBe(5000)
            ->and($this->ownInvoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    });

    it('checks a draft again when it is posted, in case the invoice was paid meanwhile', function () {
        $draftId = receiptGuardApi('POST', '/api/v1/receipts', [
            ...receiptGuardData(10000, [['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 10000]]),
            'post' => false,
        ])->assertStatus(201)->json('data.id');

        receiptGuardApi('POST', '/api/v1/receipts', receiptGuardData(10000, [
            ['invoice_id' => $this->ownInvoice->id, 'amount_cents' => 10000],
        ]))->assertStatus(201);

        receiptGuardApi('POST', "/api/v1/receipts/{$draftId}/post")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invoice OWN-1 has nothing left to pay.');

        app()->instance('current_company', $this->company);

        expect(app(ReceiptPoster::class)->liveAppliedCents($this->ownInvoice->fresh()))->toBe(10000)
            ->and(CustomerReceipt::query()->findOrFail($draftId)->journal_entry_id)->toBeNull();
    });
});
