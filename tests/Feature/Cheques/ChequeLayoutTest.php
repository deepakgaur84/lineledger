<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Services\Posting\ChequePoster;
use App\Services\Printing\ChequePdfRenderer;

/**
 * The cheque PDF has to drop onto Intuit/QuickBooks pre-printed voucher stock,
 * so its geometry is a compatibility contract, not a style choice. Every
 * coordinate below is measured off a reference Intuit cheque; PDF y runs from
 * the bottom, so a baseline of 82.32 pt from the top is written 709.68 here.
 *
 * If one of these fails, the printed cheque has moved on the page.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * TCPDF flate-compresses its page content; the drawing operators are what we
 * need to assert against.
 */
function chequeDrawOperators(string $pdf): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

    return array_reduce($matches[1], function (string $carry, string $raw): string {
        $inflated = @gzuncompress($raw);

        return $inflated === false ? $carry : $carry.$inflated;
    }, '');
}

function laidOutCheque(): string
{
    $cheque = Cheque::create([
        'bank_account_id' => test()->bank->id,
        'cheque_no' => '29878',
        'cheque_date' => '2026-09-15',
        'payee_name' => 'Estate of Joan Paddick',
        'memo' => 'Refund: excess funds from Trustage (preneed) policy',
    ]);

    $cheque->lines()->create([
        'account_id' => test()->expense->id,
        'description' => 'trustage refund',
        'amount_cents' => 55267,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    // Posting is what totals the cheque, and the total prints on the face.
    app(ChequePoster::class)->post($cheque);

    return chequeDrawOperators(app(ChequePdfRenderer::class)->render($cheque->fresh(['bankAccount', 'lines.account'])));
}

it('sets each band of the cheque in the size Intuit uses', function () {
    $ops = laidOutCheque();

    expect($ops)
        ->toContain('/F1 10.020000 Tf')  // amount lines + everything on the stubs
        ->toContain('/F1 9.000000 Tf')   // payee and address
        ->toContain('/F1 7.980000 Tf')   // DATE / MEMO labels and the memo text
        ->toContain('/F1 6.000000 Tf');  // Y Y Y Y M M D D legend
});

it('lands the cheque band on the Intuit baselines', function () {
    $ops = laidOutCheque();

    expect($ops)
        // DATE label, then the eight date digits — YYYYMMDD — on an 11.142 pt pitch.
        ->toContain('463.020000 709.260000 Td [(DATE)]')
        ->toContain('495.000000 709.680000 Td [(2)]')
        ->toContain('506.142000 709.680000 Td [(0)]')
        ->toContain('528.426000 709.680000 Td [(6)]')
        ->toContain('539.568000 709.680000 Td [(0)]')
        ->toContain('550.710000 709.680000 Td [(9)]')
        ->toContain('572.994000 709.680000 Td [(5)]')
        ->toContain('496.020000 699.780000 Td [(Y    Y    Y    Y    M    M    D    D)]')
        // PAY line (five stars, then the words) and the numeric box, one baseline.
        ->toContain('72.000000 674.700000 Td [(*****Five Hundred Fifty-Two and 67/100)]')
        ->toContain('487.980000 674.700000 Td [(**552.67)]')
        // Payee, then MEMO and its text in the small face.
        ->toContain('72.000000 633.480000 Td [(Estate of Joan Paddick)]')
        ->toContain('19.980000 583.260000 Td [(MEMO)]')
        ->toContain('72.000000 583.260000 Td [(Refund: excess funds from Trustage \\(preneed\\) policy)]');
});

it('repeats the voucher band 252 pt lower, on the Intuit baselines', function () {
    $ops = laidOutCheque();

    expect($ops)
        // Payee/date band, detail row, summary band — voucher 1.
        ->toContain('60.000000 508.680000 Td [(Estate of Joan Paddick)]')
        ->toContain('432.000000 508.680000 Td [(9/15/2026)]')
        ->toContain('234.000000 490.680000 Td [(trustage refund)]')
        ->toContain('36.000000 298.680000 Td [')
        // …and voucher 2, exactly 252 pt down.
        ->toContain('60.000000 256.680000 Td [(Estate of Joan Paddick)]')
        ->toContain('432.000000 256.680000 Td [(9/15/2026)]')
        ->toContain('234.000000 238.680000 Td [(trustage refund)]')
        ->toContain('36.000000 46.680000 Td [');
});

it('right-aligns the amount columns to x 568', function () {
    $ops = laidOutCheque();

    // "552.67" is 30.64 pt wide at 10.02 pt, so its left edge lands at 537.36.
    expect($ops)
        ->toContain('537.358840 490.680000 Td [(552.67)]')   // voucher 1 detail row
        ->toContain('537.358840 298.680000 Td [(552.67)]');  // voucher 1 summary row
});

it('names the account by code and title on the voucher', function () {
    $ops = laidOutCheque();

    // TCPDF writes the middle dot as a raw WinAnsi byte, the same as Intuit does.
    expect($ops)->toContain($this->expense->code.' '.chr(0xB7).' '.$this->expense->name);
});

it('clips a stub column rather than printing over the next one', function () {
    $this->bank->update(['name' => 'Bank of Montreal Operating Chequing Main Branch']);

    $ops = laidOutCheque();

    expect($ops)
        // The memo is cut mid-word where the column ends — no ellipsis, as Intuit does it.
        ->toContain('144.000000 298.680000 Td [(Refund: excess funds from Trustage \\(preneed\\) )]')
        ->not->toContain('144.000000 298.680000 Td [(Refund: excess funds from Trustage \\(preneed\\) policy)]')
        // Same for a bank account name too long for the column to its left.
        ->toContain('36.000000 298.680000 Td [(Bank of Montreal Oper')
        ->not->toContain('(Bank of Montreal Operating Chequing Main Branch)');
});

it('prints nothing on the cheque that we did not put there', function () {
    expect(laidOutCheque())->not->toContain('Powered by TCPDF');
});

it('prints five stars before the PAY amount whatever its length', function () {
    $ops = laidOutCheque();

    expect($ops)
        ->toContain('[(*****Five Hundred Fifty-Two and 67/100)]')
        ->not->toContain('******Five');
});

it('orders the date comb and its legend from the configured format', function () {
    config(['cheque.date_comb_format' => 'dmY']);

    expect(laidOutCheque())
        ->toContain('495.000000 709.680000 Td [(1)]')   // 15 09 2026
        ->toContain('506.142000 709.680000 Td [(5)]')
        ->toContain('496.020000 699.780000 Td [(D    D    M    M    Y    Y    Y    Y)]');
});
