<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->undep = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->firstOrFail();

    $this->makeReceipt = function (string $name, string $date, int $cents, string $suffix) use ($income): CustomerReceipt {
        $contact = Contact::create(['display_name' => $name, 'is_customer' => true]);

        $inv = Invoice::create([
            'contact_id' => $contact->id,
            'invoice_no' => 'INV-X-'.$suffix,
            'invoice_date' => $date,
            'due_date' => $date,
        ]);
        $inv->lines()->create([
            'account_id' => $income->id, 'description' => 'x', 'quantity' => '1',
            'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents,
            'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0,
        ]);
        app(InvoicePoster::class)->post($inv);

        $receipt = CustomerReceipt::create([
            'contact_id' => $contact->id,
            'receipt_no' => 'REC-X-'.$suffix,
            'receipt_date' => $date,
            'deposit_to_account_id' => $this->undep->id,
            'payment_method_id' => PaymentMethod::query()->orderBy('id')->value('id'),
            'reference' => 'PMT-'.$suffix,
            'amount_cents' => $cents,
        ]);
        $receipt->applications()->create(['invoice_id' => $inv->fresh()->id, 'amount_cents' => $cents]);
        app(ReceiptPoster::class)->post($receipt->fresh('applications'));

        return $receipt->fresh();
    };
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('exports the undeposited receipts as CSV in the displayed order', function () {
    ($this->makeReceipt)('Zed Co', '2026-01-05', 5000, '1');
    ($this->makeReceipt)('Ada Co', '2026-01-02', 9000, '2');

    $response = Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->instance()
        ->exportCsv();

    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toContain('Selected,Date,"Receipt #",From,"Payment type",Ref,Amount')
        // Default sort is by date ascending, so Ada (Jan 2) precedes Zed (Jan 5).
        ->and($lines[1])->toContain('2026-01-02')->toContain('REC-X-2')->toContain('90.00')
        ->and($lines[2])->toContain('2026-01-05')->toContain('REC-X-1')->toContain('50.00')
        ->and($lines[1])->toContain('Ada Co')
        ->and(end($lines))->toContain('TOTAL')->toContain('140.00');
});

it('follows the picker sort and tick state into the CSV', function () {
    ($this->makeReceipt)('Zed Co', '2026-01-05', 5000, '1');
    ($this->makeReceipt)('Ada Co', '2026-01-02', 9000, '2');

    $component = Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->call('sortBy', 'amount')
        ->set('availableReceipts.0.included', true);

    $response = $component->instance()->exportCsv();

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // Sorted by amount ascending: 50.00 first.
    expect($lines[1])->toContain('50.00')->toContain('Zed Co')
        ->and($lines[2])->toContain('90.00')->toContain('Ada Co')
        // Receipts load by date, so index 0 is Ada Co — the ticked row travels
        // with its own receipt even though sorting moved it to the bottom.
        ->and($lines[2])->toContain('Yes')
        ->and($lines[1])->toContain('No');
});

it('exports the undeposited receipts as XLSX and PDF', function () {
    ($this->makeReceipt)('Zed Co', '2026-01-05', 5000, '1');

    $component = Livewire::test('pages::deposits.form', ['company' => $this->company]);

    expect($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class)
        ->and($component->instance()->exportPdf())->toBeInstanceOf(BinaryFileResponse::class);
});

it('offers the export menu only when there are receipts to export', function () {
    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->assertDontSeeHtml('wire:click="exportCsv"');

    ($this->makeReceipt)('Zed Co', '2026-01-05', 5000, '1');

    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->assertSeeHtml('wire:click="exportPdf"')
        ->assertSeeHtml('wire:click="exportCsv"')
        ->assertSeeHtml('wire:click="exportXlsx"');
});
