<?php

use App\Actions\Sales\SaveReceipt;
use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\ReceiptApplication;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Livewire\Livewire;

/**
 * Reproduces the production drift seen on Society's First (company 487):
 * receipt 37529 (4200) was re-saved so its single application moved from one
 * invoice to another. SaveReceipt wipes + re-creates the application rows
 * BEFORE ReceiptPoster::repost() runs, and repost() only recomputes the
 * invoices in the receipt's CURRENT applications — so the invoice the receipt
 * USED to apply to keeps its cached amount_paid_cents / status forever.
 *
 * Both callers (API ReceiptController::update and the Livewire receipt form)
 * run SaveReceipt::handle() then ReceiptPoster::repost() in that order; these
 * tests use exactly that order.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'SF016 Estate', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    // Invoice A — the large funeral invoice (INV 27/SF016, 103040).
    $this->invoiceA = reassignPostedInvoice($this->customer, $this->income, 'INV-SF016', 103040);

    // Invoice B — the small ASI invoice (INV 27/SF016 ASI B, 4200).
    // B is due later than A, so auto-apply by due date would always pick A first.
    $this->invoiceB = reassignPostedInvoice($this->customer, $this->income, 'INV-SF016-ASI-B', 4200, '2026-11-01');

    expect($this->invoiceA->total_cents)->toBe(103040);
    expect($this->invoiceB->total_cents)->toBe(4200);
    expect($this->invoiceA->status)->toBe(InvoiceStatus::Posted);
    expect($this->invoiceB->status)->toBe(InvoiceStatus::Posted);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function reassignPostedInvoice(Contact $customer, Account $income, string $no, int $cents, string $dueDate = '2026-10-01'): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => $no,
        'invoice_date' => '2026-09-01',
        'due_date' => $dueDate,
    ]);

    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice->fresh('lines'));

    return $invoice->fresh();
}

/**
 * Sum of live applications from POSTED receipts — the same query
 * ReceiptPoster::recomputeInvoicePaidFromAllReceipts() uses. This is what the
 * invoice's cached amount_paid_cents is supposed to mirror.
 */
function reassignLivePaidCents(Invoice $invoice): int
{
    return (int) ReceiptApplication::query()
        ->whereHas('receipt', fn ($q) => $q->where('status', 'posted'))
        ->where('invoice_id', $invoice->id)
        ->sum('amount_cents');
}

function reassignHeader(CustomerReceipt $receipt, array $applications): array
{
    // Full header echo, the way both the API client and the web form send it.
    return [
        'contact_id' => $receipt->contact_id,
        'receipt_no' => $receipt->receipt_no,
        'receipt_date' => $receipt->receipt_date->toDateString(),
        'deposit_to_account_id' => $receipt->deposit_to_account_id,
        'payment_method_id' => $receipt->payment_method_id,
        'reference' => $receipt->reference,
        'amount_cents' => (int) $receipt->amount_cents,
        'memo' => $receipt->memo,
        'applications' => $applications,
    ];
}

it('recomputes the invoice a receipt no longer applies to when SaveReceipt + repost move the application (A -> B)', function () {
    // Receipt of 4200 applied to A, posted.
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-SF016-B',
        'receipt_date' => '2026-09-04',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 4200,
    ]);
    $receipt->applications()->create(['invoice_id' => $this->invoiceA->id, 'amount_cents' => 4200]);

    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $this->invoiceA->refresh();
    expect($this->invoiceA->amount_paid_cents)->toBe(4200);
    expect($this->invoiceA->status)->toBe(InvoiceStatus::Partial);

    // Edit the receipt so it applies to B instead — exactly the order the
    // API controller and Livewire form use: SaveReceipt first, then repost().
    $receipt = app(SaveReceipt::class)->handle(
        reassignHeader($receipt->fresh(), [
            ['invoice_id' => $this->invoiceB->id, 'amount_cents' => 4200],
        ]),
        $receipt->fresh(),
    );

    app(ReceiptPoster::class)->repost($receipt);

    $this->invoiceA->refresh();
    $this->invoiceB->refresh();

    // The application rows really did move: A has none, B has the 4200.
    expect(reassignLivePaidCents($this->invoiceA))->toBe(0);
    expect(reassignLivePaidCents($this->invoiceB))->toBe(4200);

    // B (the new target) is correct.
    expect($this->invoiceB->amount_paid_cents)->toBe(4200);
    expect($this->invoiceB->status)->toBe(InvoiceStatus::Paid);

    // A (the old target) must be back to unpaid — this is the claim under test.
    expect([
        'amount_paid_cents' => $this->invoiceA->amount_paid_cents,
        'status' => $this->invoiceA->status->value,
    ])->toBe([
        'amount_paid_cents' => 0,
        'status' => InvoiceStatus::Posted->value,
    ]);
});

it('recomputes the old invoice when the application is moved via PATCH /api/v1/receipts/{id} (B -> A, production direction)', function () {
    app()->forgetInstance('current_company');
    $headers = ['Authorization' => 'Bearer '.$this->plain];

    // Receipt of 4200 applied to B (the ASI), posted — B becomes paid.
    $receiptId = $this->postJson('/api/v1/receipts', [
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-SF016-B',
        'receipt_date' => '2026-09-04',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 4200,
        'applications' => [
            ['invoice_id' => $this->invoiceB->id, 'amount_cents' => 4200],
        ],
    ], $headers)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'posted')
        ->json('data.id');

    $this->invoiceB->refresh();
    expect($this->invoiceB->amount_paid_cents)->toBe(4200);
    expect($this->invoiceB->status)->toBe(InvoiceStatus::Paid);

    // Full-header PATCH that moves the single application from B to A.
    $receipt = CustomerReceipt::withoutGlobalScopes()->findOrFail($receiptId);

    $this->patchJson("/api/v1/receipts/{$receiptId}", reassignHeader($receipt, [
        ['invoice_id' => $this->invoiceA->id, 'amount_cents' => 4200],
    ]), $headers)
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.applications.0.invoice_id', $this->invoiceA->id)
        ->assertJsonCount(1, 'data.applications');

    $this->invoiceA->refresh();
    $this->invoiceB->refresh();

    expect(reassignLivePaidCents($this->invoiceA))->toBe(4200);
    expect(reassignLivePaidCents($this->invoiceB))->toBe(0);

    // A (new target) is correct: partial, 4200.
    expect($this->invoiceA->amount_paid_cents)->toBe(4200);
    expect($this->invoiceA->status)->toBe(InvoiceStatus::Partial);

    // B (old target) must be back to unpaid / posted.
    expect([
        'amount_paid_cents' => $this->invoiceB->amount_paid_cents,
        'status' => $this->invoiceB->status->value,
    ])->toBe([
        'amount_paid_cents' => 0,
        'status' => InvoiceStatus::Posted->value,
    ]);
});

it('recomputes the old contact\'s invoice and AR when the receipt moves to another customer', function () {
    $other = Contact::create(['display_name' => 'Other Estate', 'is_customer' => true]);
    $invoiceC = reassignPostedInvoice($other, $this->income, 'INV-OTHER', 4200);

    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-MOVE',
        'receipt_date' => '2026-09-04',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 4200,
    ]);
    $receipt->applications()->create(['invoice_id' => $this->invoiceA->id, 'amount_cents' => 4200]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $arBefore = $this->customer->fresh()->recomputeArBalance();

    $header = reassignHeader($receipt->fresh(), [['invoice_id' => $invoiceC->id, 'amount_cents' => 4200]]);
    $header['contact_id'] = $other->id;
    $receipt = app(SaveReceipt::class)->handle($header, $receipt->fresh());
    app(ReceiptPoster::class)->repost($receipt);

    expect($this->invoiceA->fresh()->amount_paid_cents)->toBe(0);
    expect($this->invoiceA->fresh()->status)->toBe(InvoiceStatus::Posted);
    expect($invoiceC->fresh()->amount_paid_cents)->toBe(4200);
    expect($invoiceC->fresh()->status)->toBe(InvoiceStatus::Paid);
    // the old customer owes the full invoice again; the new one is settled
    expect($this->customer->fresh()->ar_balance_cents)->toBe($arBefore + 4200);
    expect($other->fresh()->ar_balance_cents)->toBe(0);
});

it('heals a stale paid cache on the contact the next time one of its receipts is reposted', function () {
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-HEAL',
        'receipt_date' => '2026-09-04',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 4200,
    ]);
    $receipt->applications()->create(['invoice_id' => $this->invoiceB->id, 'amount_cents' => 4200]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    // Drift left behind by the old repost: A shows paid with no application.
    Invoice::withoutGlobalScopes()->whereKey($this->invoiceA->id)->update(['amount_paid_cents' => 4200, 'status' => InvoiceStatus::Partial->value]);

    // An unrelated edit to the receipt (same application, new memo)…
    $receipt = app(SaveReceipt::class)->handle(
        ['memo' => 'unrelated edit'] + reassignHeader($receipt->fresh(), [['invoice_id' => $this->invoiceB->id, 'amount_cents' => 4200]]),
        $receipt->fresh(),
    );
    app(ReceiptPoster::class)->repost($receipt);

    // …is enough to put A right.
    expect($this->invoiceA->fresh()->amount_paid_cents)->toBe(0);
    expect($this->invoiceA->fresh()->status)->toBe(InvoiceStatus::Posted);
    expect($this->invoiceB->fresh()->status)->toBe(InvoiceStatus::Paid);
});

/*
 * The web form. Its save is one transaction: a repost the poster refuses
 * leaves the applications untouched. And re-entering the amount on a receipt
 * that already has a distribution must not move the money by due date.
 */
function reassignFormFor(Company $company, ?CustomerReceipt $receipt = null)
{
    return Livewire::test('pages::receipts.form', array_filter(['company' => $company, 'receipt' => $receipt]));
}

function reassignRowIndex(array $applyTable, int $invoiceId): int
{
    foreach ($applyTable as $i => $row) {
        if ((int) $row['invoice_id'] === $invoiceId) {
            return $i;
        }
    }
    throw new RuntimeException("invoice {$invoiceId} not in the apply table");
}

it('rolls the application rewrite back when the form\'s repost is refused', function () {
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-FORM',
        'receipt_date' => '2026-09-04',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 4200,
    ]);
    $receipt->applications()->create(['invoice_id' => $this->invoiceA->id, 'amount_cents' => 4200]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    // Close the period the receipt sits in, so repost() throws.
    $this->company->update(['lock_date' => '2026-09-30']);

    $form = reassignFormFor($this->company, $receipt->fresh());
    $table = $form->get('applyTable');
    $form->set('applyTable.'.reassignRowIndex($table, $this->invoiceA->id).'.apply', '0.00')
        ->set('applyTable.'.reassignRowIndex($table, $this->invoiceB->id).'.apply', '42.00')
        ->call('save')
        ->assertHasErrors('amount');

    expect($receipt->fresh()->applications->pluck('invoice_id')->all())->toBe([$this->invoiceA->id]);
    expect($this->invoiceA->fresh()->amount_paid_cents)->toBe(4200);
    expect($this->invoiceB->fresh()->amount_paid_cents)->toBe(0);
});

it('keeps an explicit distribution when the amount is re-entered, and still auto-applies a fresh one', function () {
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-DIST',
        'receipt_date' => '2026-09-04',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 4200,
    ]);
    // Applied to B, the later-due invoice — auto-apply by due date would pick A.
    $receipt->applications()->create(['invoice_id' => $this->invoiceB->id, 'amount_cents' => 4200]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $form = reassignFormFor($this->company, $receipt->fresh());
    $table = $form->get('applyTable');
    $a = reassignRowIndex($table, $this->invoiceA->id);
    $b = reassignRowIndex($table, $this->invoiceB->id);

    $form->set('amount', '42.00')
        ->assertSet("applyTable.{$a}.apply", '0.00')
        ->assertSet("applyTable.{$b}.apply", '42.00');

    // A brand-new receipt has no distribution yet, so the amount still auto-applies.
    $fresh = reassignFormFor($this->company)->call('selectContact', $this->customer->id)->set('amount', '42.00');
    $applied = collect($fresh->get('applyTable'))->sum(fn ($row) => (int) round(((float) $row['apply']) * 100));
    expect($applied)->toBe(4200);
});

it('keeps auto-applying a fresh receipt as the amount is typed and corrected', function () {
    // The amount field syncs live per keystroke: "4" arrives before "42.00".
    $form = reassignFormFor($this->company)->call('selectContact', $this->customer->id);
    $a = reassignRowIndex($form->get('applyTable'), $this->invoiceA->id);

    $form->set('amount', '4')
        ->assertSet("applyTable.{$a}.apply", '4.00')
        ->set('amount', '42.00')
        ->assertSet("applyTable.{$a}.apply", '42.00')
        // a downward correction re-walks too, so save cannot trip on "applied exceeds amount"
        ->set('amount', '1000.00')
        ->assertSet("applyTable.{$a}.apply", '1000.00')
        ->set('amount', '100.00')
        ->assertSet("applyTable.{$a}.apply", '100.00');

    // …until the user edits an Apply cell themselves; then the amount leaves it alone.
    $b = reassignRowIndex($form->get('applyTable'), $this->invoiceB->id);
    $form->set("applyTable.{$b}.apply", '42.00')
        ->set('amount', '142.00')
        ->assertSet("applyTable.{$a}.apply", '100.00')
        ->assertSet("applyTable.{$b}.apply", '42.00');
});

it('voids a moved receipt cleanly on both invoices', function () {
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-VOID',
        'receipt_date' => '2026-09-04',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 4200,
    ]);
    $receipt->applications()->create(['invoice_id' => $this->invoiceA->id, 'amount_cents' => 4200]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $receipt = app(SaveReceipt::class)->handle(
        reassignHeader($receipt->fresh(), [['invoice_id' => $this->invoiceB->id, 'amount_cents' => 4200]]),
        $receipt->fresh(),
    );
    app(ReceiptPoster::class)->repost($receipt);
    expect($this->invoiceB->fresh()->status)->toBe(InvoiceStatus::Paid);

    app(ReceiptPoster::class)->void($receipt->fresh(['applications.invoice', 'journalEntry']));

    foreach ([$this->invoiceA, $this->invoiceB] as $invoice) {
        expect($invoice->fresh()->amount_paid_cents)->toBe(0);
        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Posted);
    }
    expect($this->customer->fresh()->ar_balance_cents)->toBe(103040 + 4200);
});
