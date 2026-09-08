<?php

use App\Actions\Purchasing\SaveBillPayment;
use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;

/**
 * The AP mirror of the receipt case: SaveBillPayment rewrites the application
 * rows before BillPaymentPoster::repost() runs, so the bills the payment USED
 * to apply to must be recomputed too, or they stay "paid" with no payment
 * behind them.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'Casket Supplier', 'is_vendor' => true]);
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    $this->billA = billReassignPostedBill($this->vendor, $this->expense, 'BILL-A', 50000);
    $this->billB = billReassignPostedBill($this->vendor, $this->expense, 'BILL-B', 5000);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function billReassignPostedBill(Contact $vendor, Account $expense, string $no, int $cents): Bill
{
    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => $no,
        'bill_date' => '2026-09-01',
        'due_date' => '2026-10-01',
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id, 'description' => 'x', 'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents, 'line_tax_cents' => 0, 'line_total_cents' => $cents,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('recomputes the bill a payment no longer applies to when the application moves', function () {
    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-MOVE',
        'payment_date' => '2026-09-04',
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 5000,
    ]);
    $payment->applications()->create(['bill_id' => $this->billA->id, 'amount_cents' => 5000]);
    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    expect($this->billA->fresh()->amount_paid_cents)->toBe(5000);
    expect($this->billA->fresh()->status)->toBe(BillStatus::Partial);

    $payment = app(SaveBillPayment::class)->handle([
        'contact_id' => $this->vendor->id,
        'payment_no' => 'PAY-MOVE',
        'payment_date' => '2026-09-04',
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 5000,
        'applications' => [['bill_id' => $this->billB->id, 'amount_cents' => 5000]],
    ], $payment->fresh());
    app(BillPaymentPoster::class)->repost($payment);

    expect($this->billB->fresh()->amount_paid_cents)->toBe(5000);
    expect($this->billB->fresh()->status)->toBe(BillStatus::Paid);
    expect($this->billA->fresh()->amount_paid_cents)->toBe(0);
    expect($this->billA->fresh()->status)->toBe(BillStatus::Posted);
    expect($this->vendor->fresh()->ap_balance_cents)->toBe(50000);
});
