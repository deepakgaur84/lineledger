<?php

use App\Enums\TaxReturnPaymentDirection;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PayrollCheque;
use App\Models\PayrollRemittance;
use App\Models\SalesReceipt;
use App\Models\TaxReturnPayment;
use App\Models\Transfer;
use App\Support\Banking\BankLineMemo;

// The label half of each of these is what the poster used to write on its own;
// the resolver has to reproduce it exactly, or the backfill can't recognise an
// old row and rewrite it.
dataset('bank documents', [
    'deposit' => [fn () => new Deposit(['memo' => 'August 2026 Interac Deposits']), 'Deposit', 'Deposit: August 2026 Interac Deposits'],
    'cheque' => [fn () => new Cheque(['cheque_no' => '2001', 'memo' => 'September hydro']), 'Cheque 2001', 'Cheque 2001: September hydro'],
    'expense with reference' => [fn () => new Expense(['reference' => 'DEBIT-77', 'memo' => 'Chairs']), 'Expense DEBIT-77', 'Expense DEBIT-77: Chairs'],
    'expense without reference' => [fn () => new Expense(['memo' => 'Chairs']), 'Expense', 'Expense: Chairs'],
    'transfer' => [fn () => new Transfer(['transfer_no' => 'TR-9', 'memo' => 'Sweep']), 'Transfer TR-9', 'Transfer TR-9: Sweep'],
    'customer receipt' => [fn () => new CustomerReceipt(['amount_cents' => 5000, 'memo' => 'e-transfer']), 'Deposit', 'Deposit: e-transfer'],
    'customer refund' => [fn () => new CustomerReceipt(['amount_cents' => -5000, 'memo' => 'overpayment back']), 'Refund', 'Refund: overpayment back'],
    'sales receipt' => [fn () => new SalesReceipt(['memo' => 'Counter sale']), 'Deposit', 'Deposit: Counter sale'],
    'bill payment' => [fn () => new BillPayment(['memo' => 'EFT batch 4']), 'Payment', 'Payment: EFT batch 4'],
    'tax payment' => [fn () => new TaxReturnPayment(['direction' => TaxReturnPaymentDirection::Outgoing, 'notes' => 'Q3 GST']), 'Tax payment', 'Tax payment: Q3 GST'],
    'tax refund' => [fn () => new TaxReturnPayment(['direction' => TaxReturnPaymentDirection::Incoming, 'notes' => 'Q3 GST']), 'Tax refund', 'Tax refund: Q3 GST'],
    'payroll remittance' => [fn () => new PayrollRemittance(['notes' => 'August source deductions']), 'Remittance payment', 'Remittance payment: August source deductions'],
    // A payroll cheque has no memo of its own — the payee is on the entry.
    'payroll cheque' => [fn () => new PayrollCheque(['cheque_no' => '5501']), 'Payroll cheque 5501', 'Payroll cheque 5501'],
]);

it('resolves the bare label and the composed memo for every bank document', function ($make, string $label, string $memo) {
    $document = $make();

    expect(BankLineMemo::labelFor($document))->toBe($label)
        ->and(BankLineMemo::forSource($document))->toBe($memo);
})->with('bank documents');

it('ignores a document type that writes no bank leg', function () {
    expect(BankLineMemo::labelFor(new Invoice))->toBeNull()
        ->and(BankLineMemo::forSource(new Invoice))->toBeNull();
});
