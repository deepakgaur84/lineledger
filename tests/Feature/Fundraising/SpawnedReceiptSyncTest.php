<?php

/**
 * A receipt spawned from a donation follows it. The donation owns the gift —
 * donor, gift type, gift date and amount — and the receipt adds only what the
 * CRA asks of the receipt itself. While the receipt is a draft, saving the
 * donation updates it; an issued receipt is never edited — a change to the gift
 * voids it and opens a corrected draft (Reissue), and voiding the donation voids
 * the receipt. A receipt is issued only for a posted donation: a draft donation
 * may still be a pledge, which the CRA does not allow a receipt for.
 */

use App\Actions\Charity\SaveDonationReceipt;
use App\Actions\Fundraising\EnsureFundraisingAccounts;
use App\Actions\Fundraising\SaveDonation;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\DonationReceiptStatus;
use App\Enums\DonationStatus;
use App\Enums\GiftType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\User;
use App\Services\Charity\DonationReceiptIssuer;
use App\Services\Charity\T3010Calculator;
use App\Services\EditLocks\EditLockManager;
use App\Services\Fundraising\DonationPoster;
use Carbon\CarbonImmutable;
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

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->revenue = Account::query()->where('type', AccountType::Income->value)->where('name', 'like', '%Donation%')->firstOrFail();

    $this->donationData = [
        'donation_date' => '2026-03-01',
        'amount_cents' => 40000,
        'gift_type' => 'cash',
        'deposit_to_account_id' => $this->bank->id,
        'revenue_account_id' => $this->revenue->id,
        'issue_receipt' => true,
    ];
});

afterEach(fn () => app()->forgetInstance('current_company'));

function spawnedReceiptSyncReceipt(Donation $donation): DonationReceipt
{
    return DonationReceipt::query()->findOrFail($donation->fresh()->donation_receipt_id);
}

function spawnedReceiptSyncReceipted(Company $company): int
{
    return app(T3010Calculator::class)
        ->summary($company, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'))['total_eligible_receipted_cents'];
}

/**
 * Data saved before the fix: the spawned receipt was issued while its donation
 * was still a draft, which the issuer no longer allows.
 */
function spawnedReceiptSyncIssueEarly(DonationReceipt $receipt): DonationReceipt
{
    $receipt->forceFill([
        'status' => DonationReceiptStatus::Issued,
        'issued_date' => '2026-03-02',
        'eligible_amount_cents' => $receipt->amount_cents - $receipt->advantage_cents,
    ])->save();

    return $receipt->fresh();
}

it('issues the linked receipt for the donation as finally posted, not as first keyed', function () {
    $donation = app(SaveDonation::class)->handle(['amount_cents' => 50000] + $this->donationData);

    // Corrected to $50.00 before posting.
    $donation = app(SaveDonation::class)->handle(['amount_cents' => 5000, 'issue_receipt' => false] + $this->donationData, $donation->fresh());
    expect(spawnedReceiptSyncReceipt($donation)->amount_cents)->toBe(5000);

    app(DonationPoster::class)->post($donation->fresh());
    $receipt = spawnedReceiptSyncReceipt($donation);
    app(DonationReceiptIssuer::class)->issue($receipt);

    $issued = $receipt->fresh();
    expect([$issued->amount_cents, $issued->eligible_amount_cents])->toBe([5000, 5000]);
});

it('copies a corrected donor, gift date and gift type onto the draft receipt', function () {
    $keyed = Contact::factory()->create(['display_name' => 'Keyed In Error']);
    $donor = Contact::factory()->create(['display_name' => 'Real Donor', 'billing_city' => 'Halifax']);

    $donation = app(SaveDonation::class)->handle(['contact_id' => $keyed->id] + $this->donationData);
    $receipt = spawnedReceiptSyncReceipt($donation);
    $receipt->forceFill(['advantage_cents' => 5000, 'advantage_description' => 'Gala dinner'])->save();

    app(SaveDonation::class)->handle([
        'contact_id' => $donor->id,
        'donation_date' => '2026-02-14',
        'gift_type' => 'in_kind',
        'amount_cents' => 30000,
    ] + $this->donationData, $donation->fresh());

    $receipt = $receipt->fresh();
    expect($receipt->status)->toBe(DonationReceiptStatus::Draft)
        ->and($receipt->contact_id)->toBe($donor->id)
        ->and($receipt->donor_name)->toBe('Real Donor')
        ->and($receipt->donor_city)->toBe('Halifax')
        ->and($receipt->gift_date->toDateString())->toBe('2026-02-14')
        ->and($receipt->gift_type)->toBe(GiftType::InKind)
        ->and($receipt->amount_cents)->toBe(30000)
        // The receipt's own details survive the sync.
        ->and($receipt->advantage_cents)->toBe(5000)
        ->and($receipt->advantage_description)->toBe('Gala dinner')
        ->and($receipt->eligible_amount_cents)->toBe(25000);
});

it('issues a draft that drifted from its donation for the donation\'s figures', function () {
    $donation = app(SaveDonation::class)->handle(['amount_cents' => 5000] + $this->donationData);
    app(DonationPoster::class)->post($donation->fresh());

    // A draft left stale before the fix: still showing the first-keyed $500.
    $receipt = spawnedReceiptSyncReceipt($donation);
    $receipt->forceFill(['amount_cents' => 50000, 'eligible_amount_cents' => 50000, 'gift_date' => '2026-01-01'])->save();

    app(DonationReceiptIssuer::class)->issue($receipt->fresh());

    $issued = $receipt->fresh();
    expect([$issued->amount_cents, $issued->eligible_amount_cents])->toBe([5000, 5000])
        ->and($issued->gift_date->toDateString())->toBe('2026-03-01');
});

it('refuses to issue a spawned receipt before its donation is posted', function () {
    $donation = app(SaveDonation::class)->handle($this->donationData);
    $receipt = spawnedReceiptSyncReceipt($donation);

    expect(fn () => app(DonationReceiptIssuer::class)->issue($receipt))
        ->toThrow(InvalidArgumentException::class, $donation->donation_no);

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Draft);

    app(DonationPoster::class)->post($donation->fresh());
    app(DonationReceiptIssuer::class)->issue($receipt->fresh());

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Issued);
});

it('does not leave an official receipt issued for a voided donation', function () {
    $donation = app(SaveDonation::class)->handle($this->donationData);
    app(DonationPoster::class)->post($donation->fresh());
    $receipt = spawnedReceiptSyncReceipt($donation);
    app(DonationReceiptIssuer::class)->issue($receipt);
    expect(spawnedReceiptSyncReceipted($this->company))->toBe(40000);

    // The donor's cheque is returned NSF; the donation is voided.
    app(DonationPoster::class)->void($donation->fresh());

    $receipt = $receipt->fresh();
    expect($receipt->status)->toBe(DonationReceiptStatus::Void)
        ->and($receipt->void_reason)->toContain($donation->donation_no)
        ->and($receipt->receipt_no)->toBe('DR-000001')
        ->and(spawnedReceiptSyncReceipted($this->company))->toBe(0);
});

it('voids the draft receipt of a voided donation so it can never be issued', function () {
    $donation = app(SaveDonation::class)->handle($this->donationData);
    app(DonationPoster::class)->post($donation->fresh());
    $receipt = spawnedReceiptSyncReceipt($donation);

    app(DonationPoster::class)->void($donation->fresh());

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Void)
        ->and($receipt->fresh()->void_reason)->toContain($donation->donation_no)
        ->and(fn () => app(DonationReceiptIssuer::class)->issue($receipt->fresh()))->toThrow(InvalidArgumentException::class);
});

it('replaces an issued receipt when its draft donation is changed', function () {
    $donation = app(SaveDonation::class)->handle(['amount_cents' => 50000] + $this->donationData);
    $original = spawnedReceiptSyncIssueEarly(spawnedReceiptSyncReceipt($donation));

    app(SaveDonation::class)->handle(['amount_cents' => 5000, 'issue_receipt' => false] + $this->donationData, $donation->fresh());

    // The issued receipt is never edited: it is voided, serial kept, and a
    // corrected draft replaces it.
    $original = $original->fresh();
    expect($original->status)->toBe(DonationReceiptStatus::Void)
        ->and($original->amount_cents)->toBe(50000)
        ->and($original->void_reason)->toContain($donation->donation_no);

    $replacement = spawnedReceiptSyncReceipt($donation);
    expect($replacement->id)->not->toBe($original->id)
        ->and($replacement->status)->toBe(DonationReceiptStatus::Draft)
        ->and($replacement->reissued_from_id)->toBe($original->id)
        ->and($replacement->donation_id)->toBe($donation->id)
        ->and($replacement->amount_cents)->toBe(5000);

    app(DonationPoster::class)->post($donation->fresh());
    app(DonationReceiptIssuer::class)->issue($replacement);

    expect(spawnedReceiptSyncReceipted($this->company))->toBe(5000);
});

it('keeps an issued receipt when a donation edit leaves the gift unchanged', function () {
    $donation = app(SaveDonation::class)->handle($this->donationData);
    $receipt = spawnedReceiptSyncIssueEarly(spawnedReceiptSyncReceipt($donation));

    app(SaveDonation::class)->handle(['notes' => 'Thank-you card sent', 'issue_receipt' => false] + $this->donationData, $donation->fresh());

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Issued)
        ->and($donation->fresh()->donation_receipt_id)->toBe($receipt->id)
        ->and(DonationReceipt::query()->count())->toBe(1);
});

it('refuses to reissue a receipt whose donation was voided', function () {
    $donation = app(SaveDonation::class)->handle($this->donationData);
    $receipt = spawnedReceiptSyncIssueEarly(spawnedReceiptSyncReceipt($donation));
    // Voided before the fix, which left the receipt issued.
    $donation->forceFill(['status' => DonationStatus::Void])->save();

    expect(fn () => app(DonationReceiptIssuer::class)->reissue($receipt->fresh()))
        ->toThrow(InvalidArgumentException::class, $donation->donation_no);

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Issued)
        ->and(DonationReceipt::query()->count())->toBe(1);

    // Voiding it is still the way out.
    app(DonationReceiptIssuer::class)->void($receipt->fresh(), 'Cheque returned NSF');
    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Void);
});

it('keeps the donation\'s figures on the receipt form, whatever is posted back', function () {
    $other = Contact::factory()->create(['display_name' => 'Someone Else']);
    $donation = app(SaveDonation::class)->handle($this->donationData);
    $receipt = spawnedReceiptSyncReceipt($donation);

    Livewire::test('pages::donation-receipts.form', ['company' => $this->company, 'donationReceipt' => $receipt])
        ->set('contact_id', $other->id)
        ->set('gift_type', 'in_kind')
        ->set('gift_date', '2026-12-31')
        ->set('amount', '1.00')
        ->set('advantage', '25.00')
        ->set('advantage_description', 'Concert ticket')
        ->call('save')
        ->assertHasNoErrors();

    $receipt = $receipt->fresh();
    expect($receipt->contact_id)->toBeNull()
        ->and($receipt->gift_type)->toBe(GiftType::Cash)
        ->and($receipt->gift_date->toDateString())->toBe('2026-03-01')
        ->and($receipt->amount_cents)->toBe(40000)
        ->and($receipt->advantage_cents)->toBe(2500)
        ->and($receipt->eligible_amount_cents)->toBe(37500);
});

it('says on the donation page that its receipt was voided with it', function () {
    $donation = app(SaveDonation::class)->handle($this->donationData);
    app(DonationPoster::class)->post($donation->fresh());
    $receipt = spawnedReceiptSyncReceipt($donation);
    app(DonationReceiptIssuer::class)->issue($receipt);

    Livewire::test('pages::donations.show', ['company' => $this->company, 'donation' => $donation->fresh()])
        ->call('void')
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'] ?? '', $receipt->receipt_no));

    expect($donation->fresh()->status)->toBe(DonationStatus::Void)
        ->and($receipt->fresh()->status)->toBe(DonationReceiptStatus::Void);
});

it('waits to void a donation while someone else is editing its receipt', function () {
    $donation = app(SaveDonation::class)->handle($this->donationData);
    app(DonationPoster::class)->post($donation->fresh());
    $receipt = spawnedReceiptSyncReceipt($donation);

    $jane = editLockMember($this->company);
    app(EditLockManager::class)->acquire($receipt, $jane);

    Livewire::test('pages::donations.show', ['company' => $this->company, 'donation' => $donation->fresh()])
        ->call('void')
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'] ?? '', $jane->name));

    expect($donation->fresh()->status)->toBe(DonationStatus::Posted)
        ->and($receipt->fresh()->status)->toBe(DonationReceiptStatus::Draft);
});

it('asks for the donation to be posted when its receipt is issued from the receipt page', function () {
    $donation = app(SaveDonation::class)->handle($this->donationData);
    $receipt = spawnedReceiptSyncReceipt($donation);

    Livewire::test('pages::donation-receipts.show', ['company' => $this->company, 'donationReceipt' => $receipt])
        ->assertSeeHtml('data-test="donation-receipt-post-donation-first"')
        ->call('issue')
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'] ?? '', $donation->donation_no));

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Draft);
});

it('tells the donation form which receipt follows it', function () {
    $donation = app(SaveDonation::class)->handle($this->donationData);
    $receipt = spawnedReceiptSyncReceipt($donation);

    Livewire::test('pages::donations.form', ['company' => $this->company, 'donation' => $donation->fresh()])
        ->assertSeeHtml('data-test="donation-linked-receipt-note"')
        ->assertSee($receipt->receipt_no);
});

it('warns on the donation form when saving replaced an issued receipt', function () {
    $donation = app(SaveDonation::class)->handle(['amount_cents' => 50000] + $this->donationData);
    $original = spawnedReceiptSyncIssueEarly(spawnedReceiptSyncReceipt($donation));

    Livewire::test('pages::donations.form', ['company' => $this->company, 'donation' => $donation->fresh()])
        ->set('amount', '50.00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'] ?? '', $original->receipt_no)
            && str_contains($params['slots']['text'] ?? '', spawnedReceiptSyncReceipt($donation)->receipt_no));

    expect($original->fresh()->status)->toBe(DonationReceiptStatus::Void)
        ->and(spawnedReceiptSyncReceipt($donation)->amount_cents)->toBe(5000);
});

it('leaves a receipt that was not spawned from a donation free to edit', function () {
    $receipt = app(SaveDonationReceipt::class)->handle([
        'donor_name' => 'Jane Donor',
        'gift_type' => 'cash',
        'gift_date' => '2026-03-01',
        'amount_cents' => 10000,
    ]);

    app(SaveDonationReceipt::class)->handle([
        'donor_name' => 'Jane Donor',
        'gift_type' => 'cash',
        'gift_date' => '2026-04-01',
        'amount_cents' => 12000,
    ], $receipt);

    app(DonationReceiptIssuer::class)->issue($receipt->fresh());

    expect($receipt->fresh()->amount_cents)->toBe(12000)
        ->and($receipt->fresh()->status)->toBe(DonationReceiptStatus::Issued);
});
