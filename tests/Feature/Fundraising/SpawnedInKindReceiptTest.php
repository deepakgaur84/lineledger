<?php

/**
 * An official receipt spawned from a donation is record-only: the donation books
 * the gift (DR deposit-to / CR donation revenue), so issuing the receipt must
 * never post it again — whatever edits are made to the draft first. An in-kind
 * receipt has to be edited before it can be issued (the CRA wants the property
 * described), so that edit must not be able to add a debit account.
 */

use App\Actions\Charity\SaveDonationReceipt;
use App\Actions\Fundraising\EnsureFundraisingAccounts;
use App\Actions\Fundraising\SaveDonation;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\DonationReceiptStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Charity\DonationReceiptIssuer;
use App\Services\Fundraising\DonationPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create([
        'address_country' => 'CA',
        'organization_type' => 'charity',
        'charity_registration_number' => '123456789RR0001',
        'features_fundraising' => true,
        'fiscal_year_start_month' => 1,
    ]);
    app()->instance('current_company', $this->company);
    app(EnsureFundraisingAccounts::class)->handle($this->company);

    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    $this->equipment = Account::query()->where('subtype', AccountSubtype::FixedAsset->value)->orderBy('code')->firstOrFail();
    $this->revenue = Account::query()->where('type', AccountType::Income->value)->where('name', 'like', '%Donation%')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

/**
 * A donated laptop recorded as an $800 in-kind donation with "Create an
 * official donation receipt" on, then posted.
 */
function spawnedInKindPostedDonation(Account $equipment, Account $revenue): Donation
{
    $donation = app(SaveDonation::class)->handle([
        'donation_date' => '2026-03-01',
        'amount_cents' => 80000,
        'gift_type' => 'in_kind',
        'deposit_to_account_id' => $equipment->id,
        'revenue_account_id' => $revenue->id,
        'issue_receipt' => true,
    ]);
    app(DonationPoster::class)->post($donation);

    return $donation->fresh();
}

function spawnedInKindPostedCents(Account $account, string $column): int
{
    return (int) JournalLine::query()
        ->where('account_id', $account->id)
        ->where('is_posted', true)
        ->sum($column);
}

it('books an in-kind gift once when its spawned receipt is completed and issued', function () {
    $donation = spawnedInKindPostedDonation($this->equipment, $this->revenue);
    $receipt = DonationReceipt::query()->findOrFail($donation->donation_receipt_id);

    // Issuing straight away is refused: an in-kind receipt needs a description.
    expect(fn () => app(DonationReceiptIssuer::class)->issue($receipt->fresh()))
        ->toThrow(InvalidArgumentException::class, 'description of the property');

    // So the draft is completed on the receipt form — and a debit account is
    // sent along with the description, as the old form offered.
    Livewire::test('pages::donation-receipts.form', ['company' => $this->company, 'donationReceipt' => $receipt])
        ->set('amount', '800.00')
        ->set('in_kind_description', 'Used laptop')
        ->set('debit_account_id', $this->equipment->id)
        ->call('save')
        ->assertHasNoErrors();

    app(DonationReceiptIssuer::class)->issue($receipt->fresh());

    $issued = $receipt->fresh();
    expect($issued->status)->toBe(DonationReceiptStatus::Issued)
        ->and($issued->journal_entry_id)->toBeNull()
        ->and($issued->debit_account_id)->toBeNull()
        ->and($issued->in_kind_description)->toBe('Used laptop')
        ->and($issued->eligible_amount_cents)->toBe(80000)
        ->and(spawnedInKindPostedCents($this->revenue, 'credit_cents'))->toBe(80000)
        ->and(spawnedInKindPostedCents($this->equipment, 'debit_cents'))->toBe(80000);
});

it('never posts a receipt spawned from a donation, even with a debit account already on it', function () {
    $donation = spawnedInKindPostedDonation($this->equipment, $this->revenue);
    $receipt = DonationReceipt::query()->findOrFail($donation->donation_receipt_id);

    // A draft saved before the fix (or written by anything that skips the save
    // action) can already carry a debit account.
    $receipt->forceFill(['in_kind_description' => 'Used laptop', 'debit_account_id' => $this->equipment->id])->save();

    app(DonationReceiptIssuer::class)->issue($receipt->fresh());

    expect($receipt->fresh()->journal_entry_id)->toBeNull()
        ->and($receipt->fresh()->debit_account_id)->toBeNull()
        ->and(spawnedInKindPostedCents($this->revenue, 'credit_cents'))->toBe(80000);
});

it('drops a debit account sent to the save action for a receipt spawned from a donation', function () {
    $donation = spawnedInKindPostedDonation($this->equipment, $this->revenue);
    $receipt = DonationReceipt::query()->findOrFail($donation->donation_receipt_id);

    app(SaveDonationReceipt::class)->handle([
        'gift_type' => 'in_kind',
        'gift_date' => '2026-03-01',
        'amount_cents' => 80000,
        'in_kind_description' => 'Used laptop',
        'debit_account_id' => $this->equipment->id,
        'revenue_account_id' => $this->revenue->id,
    ], $receipt);

    expect($receipt->fresh()->debit_account_id)->toBeNull()
        ->and($receipt->fresh()->in_kind_description)->toBe('Used laptop');
});

it('completes a spawned in-kind receipt without offering a debit account', function () {
    $donation = spawnedInKindPostedDonation($this->equipment, $this->revenue);
    $receipt = DonationReceipt::query()->findOrFail($donation->donation_receipt_id);

    Livewire::test('pages::donation-receipts.form', ['company' => $this->company, 'donationReceipt' => $receipt])
        ->assertSee($donation->donation_no)
        ->assertSeeHtml('data-test="donation-receipt-in-kind-description"')
        ->assertDontSeeHtml('data-test="donation-receipt-debit-account"')
        ->assertDontSeeHtml('data-test="donation-receipt-amount"')
        ->set('in_kind_description', 'Used laptop')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    Livewire::test('pages::donation-receipts.show', ['company' => $this->company, 'donationReceipt' => $receipt->fresh()])
        ->assertSee($donation->donation_no)
        ->call('issue');

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Issued)
        ->and($receipt->fresh()->journal_entry_id)->toBeNull()
        ->and(spawnedInKindPostedCents($this->revenue, 'credit_cents'))->toBe(80000);
});

it('reissues a spawned receipt as a draft that stays linked to the donation and posts nothing', function () {
    $donation = spawnedInKindPostedDonation($this->equipment, $this->revenue);
    $receipt = DonationReceipt::query()->findOrFail($donation->donation_receipt_id);
    $receipt->forceFill(['in_kind_description' => 'Used laptop'])->save();
    app(DonationReceiptIssuer::class)->issue($receipt->fresh());

    $draft = app(DonationReceiptIssuer::class)->reissue($receipt->fresh());

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Void)
        ->and($draft->donation_id)->toBe($donation->id)
        ->and($draft->debit_account_id)->toBeNull()
        ->and($draft->in_kind_description)->toBe('Used laptop')
        ->and($donation->fresh()->donation_receipt_id)->toBe($draft->id);

    app(DonationReceiptIssuer::class)->issue($draft->fresh());

    expect($draft->fresh()->journal_entry_id)->toBeNull()
        ->and(spawnedInKindPostedCents($this->revenue, 'credit_cents'))->toBe(80000);
});

it('still posts a stand-alone in-kind receipt that no donation booked', function () {
    $receipt = app(SaveDonationReceipt::class)->handle([
        'donor_name' => 'Jane Donor',
        'gift_type' => 'in_kind',
        'gift_date' => '2026-03-01',
        'amount_cents' => 50000,
        'in_kind_description' => 'Donated projector',
        'debit_account_id' => $this->equipment->id,
        'revenue_account_id' => $this->revenue->id,
    ]);

    app(DonationReceiptIssuer::class)->issue($receipt);

    expect($receipt->fresh()->journal_entry_id)->not->toBeNull()
        ->and(spawnedInKindPostedCents($this->revenue, 'credit_cents'))->toBe(50000);
});
