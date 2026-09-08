<?php

use App\Actions\MasterData\SaveItem;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

/*
 * Picking a catalog item on an invoice line fills the blanks but never
 * rewrites a description or unit price the line already carries. Invoices
 * posted over the API by external systems arrive with their own wording and
 * prices; re-tagging such a line with an item afterwards must not change what
 * was billed. The account and tax codes still follow the item.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->forCountry(Country::Canada, 'BC')->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
    $this->pst = TaxCode::where('code', 'PST-BC')->firstOrFail();

    $this->item = app(SaveItem::class)->handle([
        'name' => 'No Service',
        'description' => 'Simple cremation, no service',
        'type' => 'service',
        'income_account_id' => $this->income->id,
        'default_price_cents' => 99300,
        'default_tax_code_id' => $this->gst->id,
        'default_secondary_tax_code_id' => $this->pst->id,
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('fills a blank line from the item', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.item_id', $this->item->id)
        ->assertSet('lines.0.description', 'Simple cremation, no service')
        ->assertSet('lines.0.unit_price', '993.00')
        ->assertSet('lines.0.account_id', $this->income->id);
});

it('keeps an existing unit price and description when the item changes', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.unit_price', '3495.00')
        ->set('lines.0.description', 'Professional & Staff Services - Simple Cremation')
        ->set('lines.0.item_id', $this->item->id)
        ->assertSet('lines.0.item_id', $this->item->id)
        ->assertSet('lines.0.unit_price', '3495.00')
        ->assertSet('lines.0.description', 'Professional & Staff Services - Simple Cremation')
        // The account still follows the item.
        ->assertSet('lines.0.account_id', $this->income->id);
});

it('still applies the item tax codes to a line that already has a price', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.unit_price', '3495.00')
        ->set('lines.0.item_id', $this->item->id)
        ->assertSet('lines.0.tax_code_id', $this->gst->id)
        ->assertSet('lines.0.secondary_tax_code_id', $this->pst->id)
        ->assertSet('lines.0.unit_price', '3495.00');
});

it('treats a zero price as blank and fills it from the item', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.unit_price', '0.00')
        ->set('lines.0.item_id', $this->item->id)
        ->assertSet('lines.0.unit_price', '993.00');
});
