<?php

use App\Actions\Purchasing\SaveBill;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Actions\Sales\SaveEstimate;
use App\Actions\Sales\SaveInvoice;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->contact = Contact::factory()->customer()->create();
    $this->vendor = Contact::factory()->vendor()->create();

    // A complete, valid invoice payload; $overrides swaps in the bit under test.
    $this->payload = fn (array $overrides = []): array => array_merge([
        'contact_id' => $this->contact->id,
        'invoice_date' => '2026-06-01',
        'due_date' => '2026-06-30',
        'lines' => [[
            'account_id' => $this->income->id,
            'quantity' => '1',
            'unit_price_cents' => 10000,
        ]],
    ], $overrides);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('saves a changed invoice number on an existing invoice', function () {
    $invoice = app(SaveInvoice::class)->handle(($this->payload)(['invoice_no' => 'INV-000001']));

    expect($invoice->invoice_no)->toBe('INV-000001');

    app(SaveInvoice::class)->handle(($this->payload)(['invoice_no' => '2026-042']), $invoice);

    expect($invoice->fresh()->invoice_no)->toBe('2026-042');
});

it('leaves the invoice number alone when the update omits it', function () {
    $invoice = app(SaveInvoice::class)->handle(($this->payload)(['invoice_no' => 'KEEP-ME']));

    // The API's update request never sends invoice_no — a partial update must not
    // blank the number it doesn't know about.
    app(SaveInvoice::class)->handle(($this->payload)(), $invoice);

    expect($invoice->fresh()->invoice_no)->toBe('KEEP-ME');

    app(SaveInvoice::class)->handle(($this->payload)(['invoice_no' => '']), $invoice);

    expect($invoice->fresh()->invoice_no)->toBe('KEEP-ME');
});

it('carries a renumbered invoice into the GL memo on repost', function () {
    $invoice = app(SaveInvoice::class)->handle(($this->payload)(['invoice_no' => 'INV-000001']));
    app(InvoicePoster::class)->post($invoice);

    expect($invoice->journalEntry->memo)->toContain('INV-000001');

    app(SaveInvoice::class)->handle(($this->payload)(['invoice_no' => 'INV-000999']), $invoice);
    app(InvoicePoster::class)->repost($invoice);

    expect($invoice->fresh()->invoice_no)->toBe('INV-000999')
        ->and($invoice->fresh()->journalEntry->memo)->toContain('INV-000999');
});

it('persists a renumbered invoice through the edit form', function () {
    $invoice = app(SaveInvoice::class)->handle(($this->payload)(['invoice_no' => 'INV-000001']));

    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $invoice])
        ->assertSet('invoice_no', 'INV-000001')
        ->set('invoice_no', 'CUSTOM-7')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($invoice->fresh()->invoice_no)->toBe('CUSTOM-7');
});

it('rejects renumbering an invoice onto a number another invoice already uses', function () {
    app(SaveInvoice::class)->handle(($this->payload)(['invoice_no' => 'TAKEN']));
    $invoice = app(SaveInvoice::class)->handle(($this->payload)(['invoice_no' => 'MINE']));

    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $invoice])
        ->set('invoice_no', 'TAKEN')
        ->call('saveDraft')
        ->assertHasErrors('invoice_no');

    expect($invoice->fresh()->invoice_no)->toBe('MINE')
        ->and(Invoice::query()->where('invoice_no', 'TAKEN')->count())->toBe(1);
});

it('still saves an edited invoice that keeps its own number', function () {
    $invoice = app(SaveInvoice::class)->handle(($this->payload)(['invoice_no' => 'MINE']));

    // The unique rule has to ignore the record being edited, or every other edit breaks.
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $invoice])
        ->set('memo', 'Updated memo')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($invoice->fresh()->memo)->toBe('Updated memo')
        ->and($invoice->fresh()->invoice_no)->toBe('MINE');
});

it('saves a changed document number on the other sales and purchasing documents', function () {
    $estimate = app(SaveEstimate::class)->handle([
        'contact_id' => $this->contact->id,
        'estimate_date' => '2026-06-01',
        'estimate_no' => 'EST-000001',
        'lines' => [['account_id' => $this->income->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
    ]);
    app(SaveEstimate::class)->handle([
        'contact_id' => $this->contact->id,
        'estimate_date' => '2026-06-01',
        'estimate_no' => 'EST-RENAMED',
        'lines' => [['account_id' => $this->income->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
    ], $estimate);

    $bill = app(SaveBill::class)->handle([
        'contact_id' => $this->vendor->id,
        'bill_date' => '2026-06-01',
        'bill_no' => 'BILL-000001',
        'lines' => [['account_id' => $this->expense->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
    ]);
    app(SaveBill::class)->handle([
        'contact_id' => $this->vendor->id,
        'bill_date' => '2026-06-01',
        'bill_no' => 'BILL-RENAMED',
        'lines' => [['account_id' => $this->expense->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
    ], $bill);

    $po = app(SavePurchaseOrder::class)->handle([
        'contact_id' => $this->vendor->id,
        'po_date' => '2026-06-01',
        'po_no' => 'PO-000001',
        'lines' => [['account_id' => $this->expense->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
    ]);
    app(SavePurchaseOrder::class)->handle([
        'contact_id' => $this->vendor->id,
        'po_date' => '2026-06-01',
        'po_no' => 'PO-RENAMED',
        'lines' => [['account_id' => $this->expense->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
    ], $po);

    expect($estimate->fresh()->estimate_no)->toBe('EST-RENAMED')
        ->and($bill->fresh()->bill_no)->toBe('BILL-RENAMED')
        ->and($po->fresh()->po_no)->toBe('PO-RENAMED');
});
