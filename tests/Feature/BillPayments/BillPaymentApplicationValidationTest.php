<?php

use App\Actions\Purchasing\SaveBill;
use App\Actions\Purchasing\SaveBillPayment;
use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\BillPaymentApplication;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\User;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * A bill payment applies only to posted bills of its own organization, payee
 * and kind (vendor bill or reimbursement), for no more than each still owes.
 * The Pay bills form and the batch page build their rows on the server and take
 * only the Apply amounts from the browser; SaveBillPayment re-checks every
 * application, so the forms, the bank feed and the API share one answer, and
 * posting a draft checks again.
 */
function billPayGuardBill(Company $company, Contact $contact, int $cents, string $no, bool $post = true, BillType $type = BillType::Vendor): Bill
{
    app()->instance('current_company', $company);

    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $bill = app(SaveBill::class)->handle([
        'contact_id' => $contact->id,
        'bill_type' => $type->value,
        'bill_no' => $no,
        'bill_date' => '2026-03-01',
        'lines' => [['account_id' => $expense->id, 'description' => 'Goods', 'quantity' => '1', 'unit_price_cents' => $cents]],
    ]);

    if ($post) {
        app(BillPoster::class)->post($bill->fresh());
    }

    return $bill->fresh();
}

/**
 * @param  list<array{bill_id: int, amount_cents: int}>  $applications
 */
function billPayGuardData(int $amountCents, array $applications, ?Contact $payee = null, BillType $type = BillType::Vendor): array
{
    return [
        'contact_id' => ($payee ?? test()->vendor)->id,
        'payment_type' => $type->value,
        'payment_date' => '2026-03-20',
        'paid_from_account_id' => test()->bank->id,
        'amount_cents' => $amountCents,
        'applications' => $applications,
    ];
}

/**
 * Another payment of $cents against $bill, posted — under its own number, so a
 * form already open keeps the number it reserved.
 */
function billPayGuardPay(Bill $bill, int $cents): BillPayment
{
    $payment = app(SaveBillPayment::class)->handle([
        ...billPayGuardData($cents, [['bill_id' => $bill->id, 'amount_cents' => $cents]], Contact::query()->findOrFail($bill->contact_id)),
        'payment_no' => 'ELSEWHERE-'.uniqid(),
    ]);

    app(BillPaymentPoster::class)->post($payment);

    return $payment->fresh();
}

function billPayGuardApi(string $method, string $uri, array $payload = []): TestResponse
{
    app()->forgetInstance('current_company');

    return test()->json($method, $uri, $payload, ['Authorization' => 'Bearer '.test()->apiKey]);
}

function billPayGuardRow(array $rows, int $billId): int
{
    return (int) collect($rows)->search(fn (array $row) => $row['bill_id'] === $billId);
}

beforeEach(function () {
    // Another organization, with an open $500.00 bill of its own.
    $this->victim = Company::factory()->create();
    app()->instance('current_company', $this->victim);
    $victimVendor = Contact::create(['display_name' => 'Victim Vendor', 'is_vendor' => true]);
    $this->victimBill = billPayGuardBill($this->victim, $victimVendor, 50000, 'VIC-1');

    // The organization under test: two vendors, a $100.00 bill each.
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'Own Vendor', 'is_vendor' => true]);
    $this->ownBill = billPayGuardBill($this->company, $this->vendor, 10000, 'OWN-1');
    $this->otherVendor = Contact::create(['display_name' => 'Other Vendor', 'is_vendor' => true]);
    $this->otherVendorBill = billPayGuardBill($this->company, $this->otherVendor, 10000, 'OWN-2');

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    ['plaintext' => $this->apiKey] = CompanyApiKey::mint($this->company, 'Bill payment guard');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

describe('Pay bills form', function () {
    it('refuses a browser update that points an apply row at another organization\'s bill', function () {
        $form = Livewire::test('pages::bill-payments.form', ['company' => $this->company])
            ->set('contact_id', $this->vendor->id);

        expect(fn () => $form->set('applyTable.0.bill_id', $this->victimBill->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        $victimBill = Bill::withoutGlobalScopes()->findOrFail($this->victimBill->id);

        expect(BillPaymentApplication::query()->where('bill_id', $this->victimBill->id)->exists())->toBeFalse()
            ->and($victimBill->amount_paid_cents)->toBe(0)
            ->and($victimBill->status)->toBe(BillStatus::Posted);
    });

    it('refuses a browser update that inflates a row\'s open balance', function () {
        $form = Livewire::test('pages::bill-payments.form', ['company' => $this->company])
            ->set('contact_id', $this->vendor->id);

        expect(fn () => $form->set('applyTable.0.balance', 99999999))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('pays the bill for the amount typed', function () {
        Livewire::test('pages::bill-payments.form', ['company' => $this->company])
            ->set('contact_id', $this->vendor->id)
            ->set('applyTable.0.apply', '100.00')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->ownBill->fresh()->status)->toBe(BillStatus::Paid);
    });

    it('checks the open balance on the server when the table has gone stale', function () {
        $form = Livewire::test('pages::bill-payments.form', ['company' => $this->company])
            ->set('contact_id', $this->vendor->id);

        expect($form->get('applyTable.0.balance'))->toBe(10000);

        // Someone else pays $60.00 of the bill meanwhile.
        app()->instance('current_company', $this->company);
        billPayGuardPay($this->ownBill, 6000);

        $form->set('applyTable.0.apply', '100.00')
            ->call('save')
            ->assertHasErrors('applyTable');

        expect($this->ownBill->fresh()->amount_paid_cents)->toBe(6000)
            ->and(BillPayment::query()->count())->toBe(1);
    });
});

describe('Batch Pay bills page', function () {
    it('refuses a browser update that points a row at another organization\'s bill', function () {
        $page = Livewire::test('pages::bill-payments.batch', ['company' => $this->company]);

        expect(fn () => $page->set('rows.0.bill_id', $this->victimBill->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        expect(fn () => $page->set('rows.0.contact_id', $this->otherVendor->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        $victimBill = Bill::withoutGlobalScopes()->findOrFail($this->victimBill->id);

        expect($victimBill->amount_paid_cents)->toBe(0)
            ->and($victimBill->status)->toBe(BillStatus::Posted);
    });

    it('pays each bill from its own vendor, one payment per vendor', function () {
        $page = Livewire::test('pages::bill-payments.batch', ['company' => $this->company]);
        $rows = $page->get('rows');

        $page->set('rows.'.billPayGuardRow($rows, $this->ownBill->id).'.apply', '100.00')
            ->set('rows.'.billPayGuardRow($rows, $this->otherVendorBill->id).'.apply', '40.00')
            ->call('pay')
            ->assertHasNoErrors();

        expect(BillPayment::query()->where('contact_id', $this->vendor->id)->sole()->amount_cents)->toBe(10000)
            ->and(BillPayment::query()->where('contact_id', $this->otherVendor->id)->sole()->amount_cents)->toBe(4000)
            ->and($this->ownBill->fresh()->status)->toBe(BillStatus::Paid)
            ->and($this->otherVendorBill->fresh()->status)->toBe(BillStatus::Partial);
    });

    it('checks the open balance on the server when the rows have gone stale', function () {
        $page = Livewire::test('pages::bill-payments.batch', ['company' => $this->company]);
        $row = billPayGuardRow($page->get('rows'), $this->ownBill->id);

        app()->instance('current_company', $this->company);
        billPayGuardPay($this->ownBill, 6000);

        $page->set("rows.$row.apply", '100.00')
            ->call('pay')
            ->assertHasErrors('rows');

        expect($this->ownBill->fresh()->amount_paid_cents)->toBe(6000);
    });
});

describe('SaveBillPayment', function () {
    it('refuses a bill of another organization and writes nothing', function () {
        expect(fn () => app(SaveBillPayment::class)->handle(billPayGuardData(50000, [
            ['bill_id' => $this->victimBill->id, 'amount_cents' => 50000],
        ])))->toThrow(PostingValidationException::class, 'That bill could not be found.');

        expect(BillPayment::withoutGlobalScopes()->count())->toBe(0)
            ->and(BillPaymentApplication::query()->count())->toBe(0);
    });

    it('refuses another vendor\'s bill', function () {
        expect(fn () => app(SaveBillPayment::class)->handle(billPayGuardData(10000, [
            ['bill_id' => $this->otherVendorBill->id, 'amount_cents' => 10000],
        ])))->toThrow(PostingValidationException::class, 'Bill OWN-2 belongs to a different payee.');
    });

    it('refuses a bill that is still a draft or has been voided', function () {
        $draft = billPayGuardBill($this->company, $this->vendor, 10000, 'DRAFT-1', post: false);
        $voided = billPayGuardBill($this->company, $this->vendor, 10000, 'VOID-1');
        app(BillPoster::class)->void($voided);

        expect(fn () => app(SaveBillPayment::class)->handle(billPayGuardData(10000, [
            ['bill_id' => $draft->id, 'amount_cents' => 10000],
        ])))->toThrow(PostingValidationException::class, 'Bill DRAFT-1 is not open for payment.');

        expect(fn () => app(SaveBillPayment::class)->handle(billPayGuardData(10000, [
            ['bill_id' => $voided->id, 'amount_cents' => 10000],
        ])))->toThrow(PostingValidationException::class, 'Bill VOID-1 is not open for payment.');
    });

    it('refuses a reimbursement claim on a vendor payment', function () {
        $both = Contact::create(['display_name' => 'Vendor And Employee', 'is_vendor' => true, 'is_employee' => true]);
        $claim = billPayGuardBill($this->company, $both, 10000, 'CLAIM-1', type: BillType::Reimbursement);

        expect(fn () => app(SaveBillPayment::class)->handle(billPayGuardData(10000, [
            ['bill_id' => $claim->id, 'amount_cents' => 10000],
        ], $both)))->toThrow(PostingValidationException::class, "Bill CLAIM-1 can't be paid by this type of payment.");

        $payment = app(SaveBillPayment::class)->handle(billPayGuardData(10000, [
            ['bill_id' => $claim->id, 'amount_cents' => 10000],
        ], $both, BillType::Reimbursement));

        expect($payment->payment_type)->toBe(BillType::Reimbursement);
    });

    it('caps an application at the bill\'s open balance, counting other payments', function () {
        expect(fn () => app(SaveBillPayment::class)->handle(billPayGuardData(90000, [
            ['bill_id' => $this->ownBill->id, 'amount_cents' => 90000],
        ])))->toThrow(PostingValidationException::class, "Cannot apply more than bill OWN-1's open balance of 100.00.");

        billPayGuardPay($this->ownBill, 6000);

        expect(fn () => app(SaveBillPayment::class)->handle(billPayGuardData(4001, [
            ['bill_id' => $this->ownBill->id, 'amount_cents' => 4001],
        ])))->toThrow(PostingValidationException::class, 'open balance of 40.00');

        billPayGuardPay($this->ownBill, 4000);

        expect($this->ownBill->fresh()->status)->toBe(BillStatus::Paid);

        expect(fn () => app(SaveBillPayment::class)->handle(billPayGuardData(100, [
            ['bill_id' => $this->ownBill->id, 'amount_cents' => 100],
        ])))->toThrow(PostingValidationException::class, 'Bill OWN-1 has nothing left to pay.');
    });

    it('lets an edit re-apply what the payment itself already paid, and no more', function () {
        $payment = billPayGuardPay($this->ownBill, 10000);

        $edited = app(SaveBillPayment::class)->handle([
            ...billPayGuardData(10000, [['bill_id' => $this->ownBill->id, 'amount_cents' => 10000]]),
            'memo' => 'Corrected memo',
        ], $payment);
        app(BillPaymentPoster::class)->repost($edited);

        expect($this->ownBill->fresh()->amount_paid_cents)->toBe(10000);

        expect(fn () => app(SaveBillPayment::class)->handle(billPayGuardData(10001, [
            ['bill_id' => $this->ownBill->id, 'amount_cents' => 10001],
        ]), $edited->fresh()))->toThrow(PostingValidationException::class, 'open balance of 100.00');
    });
});

describe('Bill payments API', function () {
    it('refuses an application above the bill\'s open balance with a 422', function () {
        billPayGuardApi('POST', '/api/v1/bill-payments', billPayGuardData(90000, [
            ['bill_id' => $this->ownBill->id, 'amount_cents' => 90000],
        ]))->assertStatus(422)
            ->assertJsonPath('message', "Cannot apply more than bill OWN-1's open balance of 100.00.");

        expect(BillPaymentApplication::query()->where('bill_id', $this->ownBill->id)->exists())->toBeFalse();
    });

    it('still takes a payment larger than what it applies, leaving the rest on account', function () {
        billPayGuardApi('POST', '/api/v1/bill-payments', billPayGuardData(15000, [
            ['bill_id' => $this->ownBill->id, 'amount_cents' => 10000],
        ]))->assertStatus(201);

        expect($this->ownBill->fresh()->status)->toBe(BillStatus::Paid);
    });

    it('checks a draft again when it is posted, in case the bill was paid meanwhile', function () {
        $draftId = billPayGuardApi('POST', '/api/v1/bill-payments', [
            ...billPayGuardData(10000, [['bill_id' => $this->ownBill->id, 'amount_cents' => 10000]]),
            'post' => false,
        ])->assertStatus(201)->json('data.id');

        billPayGuardApi('POST', '/api/v1/bill-payments', billPayGuardData(10000, [
            ['bill_id' => $this->ownBill->id, 'amount_cents' => 10000],
        ]))->assertStatus(201);

        billPayGuardApi('POST', "/api/v1/bill-payments/{$draftId}/post")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bill OWN-1 has nothing left to pay.');

        expect(BillPayment::withoutGlobalScopes()->findOrFail($draftId)->journal_entry_id)->toBeNull();
    });
});
