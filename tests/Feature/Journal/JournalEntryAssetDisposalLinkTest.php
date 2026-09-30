<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->assetAccount = Account::query()->where('subtype', AccountSubtype::FixedAsset->value)->where('name', 'Office Equipment')->firstOrFail();
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * A posted journal entry crediting $this->assetAccount for $cents, offset by
 * a debit to Accounts Payable — simulating a manual disposal write-off entry.
 */
function creditAssetAccount(int $cents, string $date = '2026-05-15'): JournalEntry
{
    $entry = JournalEntry::create(['entry_no' => 'JE-DISPOSE-1', 'entry_date' => $date, 'memo' => 'Disposal write-off']);
    $entry->lines()->create(['account_id' => test()->ap->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => test()->assetAccount->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);

    return app(JournalPoster::class)->post($entry);
}

it('offers to mark an asset disposed when exactly one in-service asset uses the credited account', function () {
    $asset = Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'status' => 'in-service']);
    $entry = creditAssetAccount(50000);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->assertSeeHtml('data-test="dispose-asset-from-journal-line"')
        ->assertSeeHtml("assets/{$asset->id}/edit");
});

it('offers nothing when more than one in-service asset uses the credited account', function () {
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'status' => 'in-service']);
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'status' => 'in-service']);
    $entry = creditAssetAccount(50000);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->assertDontSeeHtml('data-test="dispose-asset-from-journal-line"');
});

it('offers nothing when no asset uses the credited account', function () {
    $entry = creditAssetAccount(50000);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->assertDontSeeHtml('data-test="dispose-asset-from-journal-line"');
});

it('does not offer disposal for an asset that is already disposed', function () {
    Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'status' => 'disposed', 'disposed_at' => '2026-01-01']);
    $entry = creditAssetAccount(50000);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->assertDontSeeHtml('data-test="dispose-asset-from-journal-line"');
});

it('does not offer disposal for a debit line, even to a fixed-asset account', function () {
    $asset = Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'status' => 'in-service']);

    // A normal acquisition entry — debits the asset account, the opposite side.
    $entry = JournalEntry::create(['entry_no' => 'JE-ACQUIRE-1', 'entry_date' => '2026-05-15', 'memo' => 'Purchase']);
    $entry->lines()->create(['account_id' => $this->assetAccount->id, 'debit_cents' => 50000, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $this->ap->id, 'debit_cents' => 0, 'credit_cents' => 50000, 'line_order' => 1]);
    $entry = app(JournalPoster::class)->post($entry);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->assertDontSeeHtml('data-test="dispose-asset-from-journal-line"')
        ->assertSeeHtml('data-test="create-asset-from-journal-line"');
});

it('matches on the accumulated depreciation account too, not just the cost account', function () {
    // The direction here (a credit to Accum Dep) isn't the realistic one for
    // actually clearing it — that would be a debit — but the detector's own
    // rule is deliberately generic (any credit to a FixedAsset-type account),
    // matching the account the button checks rather than the accounting
    // direction, so this still correctly exercises that it checks BOTH of an
    // asset's accounts, not just its cost account.
    $accumDep = Account::query()->where('subtype', AccountSubtype::FixedAsset->value)->where('name', 'Accumulated Depreciation')->firstOrFail();
    $asset = Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'accumulated_depreciation_account_id' => $accumDep->id, 'status' => 'in-service']);

    $entry = JournalEntry::create(['entry_no' => 'JE-CLEAR-1', 'entry_date' => '2026-05-15', 'memo' => 'Accum dep entry']);
    $entry->lines()->create(['account_id' => $accumDep->id, 'debit_cents' => 0, 'credit_cents' => 30000, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $this->ap->id, 'debit_cents' => 30000, 'credit_cents' => 0, 'line_order' => 1]);
    $entry = app(JournalPoster::class)->post($entry);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->assertSeeHtml('data-test="dispose-asset-from-journal-line"')
        ->assertSeeHtml("assets/{$asset->id}/edit");
});

it('pre-fills the asset as disposed, dated to the journal entry, when opened from the link', function () {
    $asset = Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'status' => 'in-service']);

    Livewire::withQueryParams(['dispose_date' => '2026-05-15'])
        ->test('pages::assets.form', ['company' => $this->company, 'asset' => $asset])
        ->assertSet('status', 'disposed')
        ->assertSet('disposed_at', '2026-05-15');
});

it('never overwrites an asset that is already disposed, even if dispose_date is present', function () {
    $asset = Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'status' => 'disposed', 'disposed_at' => '2026-01-01']);

    Livewire::withQueryParams(['dispose_date' => '2026-05-15'])
        ->test('pages::assets.form', ['company' => $this->company, 'asset' => $asset])
        ->assertSet('status', 'disposed')
        ->assertSet('disposed_at', '2026-01-01');
});

it('saves the disposal when the pre-filled form is submitted', function () {
    $asset = Asset::factory()->create(['asset_account_id' => $this->assetAccount->id, 'status' => 'in-service']);

    Livewire::withQueryParams(['dispose_date' => '2026-05-15'])
        ->test('pages::assets.form', ['company' => $this->company, 'asset' => $asset])
        ->call('save')
        ->assertHasNoErrors();

    $asset = $asset->fresh();

    expect($asset->status->value)->toBe('disposed')
        ->and($asset->disposed_at->toDateString())->toBe('2026-05-15');
});
