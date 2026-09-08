<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->undep = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->firstOrFail();

    // Post an invoice and a receipt against it, parked in Undeposited Funds.
    $this->makeReceipt = function (string $name, string $date, int $cents, string $suffix) use ($income): CustomerReceipt {
        $contact = Contact::create(['display_name' => $name, 'is_customer' => true]);

        $inv = Invoice::create([
            'contact_id' => $contact->id,
            'invoice_no' => 'INV-P-'.$suffix,
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
            'receipt_no' => 'REC-P-'.$suffix,
            'receipt_date' => $date,
            'deposit_to_account_id' => $this->undep->id,
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

it('leaves every undeposited receipt unchecked on a new deposit', function () {
    ($this->makeReceipt)('Zed Co', '2026-01-05', 5000, '1');
    ($this->makeReceipt)('Ada Co', '2026-01-02', 9000, '2');

    $rows = Livewire::test('pages::deposits.form', ['company' => $this->company])->get('availableReceipts');

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('included')->all())->toBe([false, false]);
});

it('refuses to save a new deposit while no receipt is ticked', function () {
    ($this->makeReceipt)('Ada Co', '2026-01-02', 9000, '1');

    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->call('save')
        ->assertHasErrors('deposit');
});

it('ticks every receipt and then clears them all from the header checkbox', function () {
    ($this->makeReceipt)('Zed Co', '2026-01-05', 5000, '1');
    ($this->makeReceipt)('Ada Co', '2026-01-02', 9000, '2');

    $component = Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->call('toggleAllReceipts');

    expect(collect($component->get('availableReceipts'))->pluck('included')->all())->toBe([true, true]);

    $component->call('toggleAllReceipts');

    expect(collect($component->get('availableReceipts'))->pluck('included')->all())->toBe([false, false]);
});

it('sorts the picker by a clicked column and flips direction on a second click', function () {
    ($this->makeReceipt)('Zed Co', '2026-01-05', 5000, '1');
    ($this->makeReceipt)('Ada Co', '2026-01-02', 9000, '2');

    $component = Livewire::test('pages::deposits.form', ['company' => $this->company]);

    // Defaults to date ascending.
    expect(collect($component->get('availableReceipts'))->pluck('date')->all())
        ->toBe(['2026-01-02', '2026-01-05']);

    $component->call('sortBy', 'contact');
    expect(collect($component->get('availableReceipts'))->pluck('contact')->all())
        ->toBe(['Ada Co', 'Zed Co']);

    $component->call('sortBy', 'contact')->assertSet('sortDir', 'desc');
    expect(collect($component->get('availableReceipts'))->pluck('contact')->all())
        ->toBe(['Zed Co', 'Ada Co']);

    $component->call('sortBy', 'amount')->assertSet('sortDir', 'asc');
    expect(collect($component->get('availableReceipts'))->pluck('amount')->all())
        ->toBe([5000, 9000]);
});

it('ignores a sort on an unknown column', function () {
    ($this->makeReceipt)('Ada Co', '2026-01-02', 9000, '1');

    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->call('sortBy', 'amount_cents; drop table')
        ->assertSet('sortField', 'date')
        ->assertSet('sortDir', 'asc');
});

it('keeps a receipt ticked against its own row after the picker is re-sorted', function () {
    ($this->makeReceipt)('Zed Co', '2026-01-05', 5000, '1');
    $ada = ($this->makeReceipt)('Ada Co', '2026-01-02', 9000, '2');

    $component = Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->set('availableReceipts.0.included', true)   // Ada, first under date-asc
        ->call('sortBy', 'date');                     // flip to date-desc

    $rows = collect($component->get('availableReceipts'));

    expect($rows->firstWhere('receipt_id', $ada->id)['included'])->toBeTrue()
        ->and($rows->first()['receipt_id'])->not->toBe($ada->id);
});
