<?php

use App\Actions\Banking\SaveDeposit;
use App\Actions\Sales\RecordCustomerRefund;
use App\Actions\Sales\SaveSalesReceipt;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\DepositStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\SalesReceipt;
use App\Models\User;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use App\Services\Posting\SalesReceiptPoster;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * A receipt-source deposit line credits Undeposited Funds for the receipt, so
 * the receipt must be a posted one of this organization that was received into
 * Undeposited Funds and isn't already on another deposit that stands, and the
 * line must carry the receipt's own amount. SaveDeposit checks every line, so
 * the form, the API and posting a draft all refuse the same things, and the
 * deposit form rebuilds the ticked rows from the database rather than trusting
 * the browser's copy.
 */
function depositGuardReceipt(string $suffix, int $cents, ?int $depositToAccountId = null, bool $post = true): CustomerReceipt
{
    // The bound organization's own accounts, so a receipt can be made for another one too.
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $contact = Contact::create(['display_name' => 'Customer '.$suffix, 'is_customer' => true]);

    $invoice = Invoice::create([
        'contact_id' => $contact->id,
        'invoice_no' => 'INV-DG-'.$suffix,
        'invoice_date' => '2026-05-01',
        'due_date' => '2026-05-01',
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id, 'description' => 'Services', 'quantity' => '1',
        'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $receipt = CustomerReceipt::create([
        'contact_id' => $contact->id,
        'receipt_no' => 'REC-DG-'.$suffix,
        'receipt_date' => '2026-05-02',
        'deposit_to_account_id' => $depositToAccountId ?? $undeposited->id,
        'amount_cents' => $cents,
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => $cents]);

    if ($post) {
        app(ReceiptPoster::class)->post($receipt->fresh('applications'));
    }

    return $receipt->fresh();
}

/**
 * @param  list<array<string, mixed>>  $lines
 */
function depositGuardData(array $lines): array
{
    return [
        'bank_account_id' => test()->bank->id,
        'deposit_date' => '2026-05-03',
        'lines' => $lines,
    ];
}

function depositGuardApi(string $method, string $uri, array $payload = []): TestResponse
{
    app()->forgetInstance('current_company');

    return test()->json($method, $uri, $payload, ['Authorization' => 'Bearer '.test()->apiKey]);
}

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

    ['plaintext' => $this->apiKey] = CompanyApiKey::mint($this->company, 'Deposit guard');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

describe('Deposits API', function () {
    it('refuses to deposit the same receipt twice', function () {
        $receipt = depositGuardReceipt('A', 10000);

        $first = depositGuardApi('POST', '/api/v1/deposits', depositGuardData([['customer_receipt_id' => $receipt->id]]))
            ->assertStatus(201)
            ->json('data.deposit_no');

        // An ordinary retry of the same request.
        depositGuardApi('POST', '/api/v1/deposits', depositGuardData([['customer_receipt_id' => $receipt->id]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Receipt REC-DG-A is already on deposit '.$first.'.');

        app()->instance('current_company', $this->company);

        expect((int) $this->undeposited->fresh()->balance_cents)->toBe(0)
            ->and((int) $this->bank->fresh()->balance_cents)->toBe(10000)
            ->and(Deposit::query()->count())->toBe(1);
    });

    it('refuses a receipt that was received straight into the bank', function () {
        $receipt = depositGuardReceipt('B', 10000, $this->bank->id);

        depositGuardApi('POST', '/api/v1/deposits', depositGuardData([['customer_receipt_id' => $receipt->id]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Receipt REC-DG-B was not received into Undeposited Funds, so it can\'t be deposited from there.');

        app()->instance('current_company', $this->company);

        expect((int) $this->undeposited->fresh()->balance_cents)->toBe(0)
            ->and((int) $this->bank->fresh()->balance_cents)->toBe(10000);
    });

    it('refuses a line amount that differs from the receipt', function () {
        $receipt = depositGuardReceipt('C', 10000);

        depositGuardApi('POST', '/api/v1/deposits', depositGuardData([
            ['customer_receipt_id' => $receipt->id, 'amount_cents' => 1000000],
        ]))->assertStatus(422)
            ->assertJsonPath('message', 'Receipt REC-DG-C is for 100.00, so it must be deposited for that amount.');
    });

    it('checks a draft again when it is posted', function () {
        $receipt = depositGuardReceipt('D', 10000);

        $draftId = depositGuardApi('POST', '/api/v1/deposits', [
            ...depositGuardData([['customer_receipt_id' => $receipt->id]]),
            'post' => false,
        ])->assertStatus(201)->json('data.id');

        // The receipt is edited after it was put on the draft.
        app()->instance('current_company', $this->company);
        $receipt->forceFill(['amount_cents' => 12000])->save();

        depositGuardApi('POST', "/api/v1/deposits/{$draftId}/post")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Receipt REC-DG-D is for 120.00, so it must be deposited for that amount.');

        expect(Deposit::withoutGlobalScopes()->findOrFail($draftId)->status)->toBe(DepositStatus::Draft);
    });
});

describe('Make deposit form', function () {
    it('refuses a browser update that changes a picked receipt\'s amount', function () {
        depositGuardReceipt('E', 10000);

        $form = Livewire::test('pages::deposits.form', ['company' => $this->company])
            ->set('bank_account_id', $this->bank->id)
            ->set('availableReceipts.0.included', true);

        expect(fn () => $form->set('availableReceipts.0.amount', 1000000))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        expect(fn () => $form->set('availableReceipts.0.receipt_id', 999999))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        expect((int) $this->undeposited->fresh()->balance_cents)->toBe(10000)
            ->and((int) $this->bank->fresh()->balance_cents)->toBe(0);
    });

    it('deposits a ticked receipt for its current amount', function () {
        $receipt = depositGuardReceipt('F', 10000);

        $form = Livewire::test('pages::deposits.form', ['company' => $this->company])
            ->set('bank_account_id', $this->bank->id)
            ->set('availableReceipts.0.included', true);

        // The receipt is corrected after the picker loaded: the deposit follows the receipt.
        $receipt->forceFill(['amount_cents' => 12000])->save();

        $form->call('save')->assertHasNoErrors();

        $line = Deposit::query()->sole()->lines()->sole();

        expect($line->customer_receipt_id)->toBe($receipt->id)
            ->and($line->amount_cents)->toBe(12000)
            ->and($line->description)->toBe('Receipt REC-DG-F — Customer F');
    });

    it('refuses a receipt someone else deposited after the picker loaded', function () {
        $receipt = depositGuardReceipt('G', 10000);

        $form = Livewire::test('pages::deposits.form', ['company' => $this->company])
            ->set('bank_account_id', $this->bank->id)
            ->set('availableReceipts.0.included', true);

        $other = app(SaveDeposit::class)->handle([
            ...depositGuardData([['customer_receipt_id' => $receipt->id]]),
            'deposit_no' => 'DEP-ELSEWHERE',
        ]);
        app(DepositPoster::class)->post($other);

        $form->call('save')->assertHasErrors('deposit');

        expect(Deposit::query()->count())->toBe(1)
            ->and((int) $this->bank->fresh()->balance_cents)->toBe(10000)
            ->and((int) $this->undeposited->fresh()->balance_cents)->toBe(0);
    });

    it('reports the same refusal when saving a draft', function () {
        $receipt = depositGuardReceipt('H', 10000);

        $form = Livewire::test('pages::deposits.form', ['company' => $this->company])
            ->set('bank_account_id', $this->bank->id)
            ->set('availableReceipts.0.included', true);

        app(SaveDeposit::class)->handle([
            ...depositGuardData([['customer_receipt_id' => $receipt->id]]),
            'deposit_no' => 'DEP-ELSEWHERE',
        ]);

        $form->call('saveDraft')->assertHasErrors('deposit');

        expect(Deposit::query()->count())->toBe(1);
    });
});

describe('SaveDeposit', function () {
    it('refuses a receipt of another organization', function () {
        $other = Company::factory()->create();
        app()->instance('current_company', $other);
        $foreignReceipt = depositGuardReceipt('X', 10000);

        app()->instance('current_company', $this->company);

        expect(fn () => app(SaveDeposit::class)->handle(depositGuardData([['customer_receipt_id' => $foreignReceipt->id]])))
            ->toThrow(PostingValidationException::class, 'That receipt could not be found.');

        expect(Deposit::withoutGlobalScopes()->count())->toBe(0);
    });

    it('refuses a receipt that is not posted', function () {
        $draft = depositGuardReceipt('I', 10000, post: false);
        $voided = depositGuardReceipt('J', 10000);
        app(ReceiptPoster::class)->void($voided);

        expect(fn () => app(SaveDeposit::class)->handle(depositGuardData([['customer_receipt_id' => $draft->id]])))
            ->toThrow(PostingValidationException::class, 'Receipt REC-DG-I is not posted, so it can\'t be deposited.');

        expect(fn () => app(SaveDeposit::class)->handle(depositGuardData([['customer_receipt_id' => $voided->id]])))
            ->toThrow(PostingValidationException::class, 'Receipt REC-DG-J is not posted, so it can\'t be deposited.');
    });

    it('refuses a receipt that is on another draft deposit, and frees it once that draft is gone', function () {
        $receipt = depositGuardReceipt('K', 10000);
        $draft = app(SaveDeposit::class)->handle(depositGuardData([['customer_receipt_id' => $receipt->id]]));

        expect(fn () => app(SaveDeposit::class)->handle(depositGuardData([['customer_receipt_id' => $receipt->id]])))
            ->toThrow(PostingValidationException::class, 'Receipt REC-DG-K is already on deposit '.$draft->deposit_no.'.');

        $draft->lines()->delete();
        $draft->delete();

        $deposit = app(SaveDeposit::class)->handle(depositGuardData([['customer_receipt_id' => $receipt->id]]));

        expect($deposit->amount_cents)->toBe(10000);
    });

    it('frees a receipt once the deposit holding it is voided', function () {
        $receipt = depositGuardReceipt('L', 10000);
        $first = app(SaveDeposit::class)->handle(depositGuardData([['customer_receipt_id' => $receipt->id]]));
        app(DepositPoster::class)->post($first);
        app(DepositPoster::class)->void($first->fresh());

        $second = app(SaveDeposit::class)->handle(depositGuardData([['customer_receipt_id' => $receipt->id]]));
        app(DepositPoster::class)->post($second);

        expect((int) $this->bank->fresh()->balance_cents)->toBe(10000)
            ->and((int) $this->undeposited->fresh()->balance_cents)->toBe(0);
    });

    it('refuses the same receipt twice on one deposit', function () {
        $receipt = depositGuardReceipt('M', 10000);

        expect(fn () => app(SaveDeposit::class)->handle(depositGuardData([
            ['customer_receipt_id' => $receipt->id],
            ['customer_receipt_id' => $receipt->id],
        ])))->toThrow(PostingValidationException::class, 'Receipt REC-DG-M appears on this deposit more than once.');
    });

    it('lets an edit keep the deposit\'s own receipts', function () {
        $receipt = depositGuardReceipt('N', 10000);
        $deposit = app(SaveDeposit::class)->handle(depositGuardData([['customer_receipt_id' => $receipt->id]]));
        app(DepositPoster::class)->post($deposit);

        $edited = app(SaveDeposit::class)->handle([
            ...depositGuardData([['customer_receipt_id' => $receipt->id, 'amount_cents' => 10000]]),
            'memo' => 'Corrected memo',
        ], $deposit->fresh());
        app(DepositPoster::class)->repost($edited);

        expect($edited->fresh()->memo)->toBe('Corrected memo')
            ->and((int) $this->bank->fresh()->balance_cents)->toBe(10000);
    });

    it('deposits a card refund for its own negative amount', function () {
        // A $100.00 overpayment, $40.00 of it refunded by card: a -40.00 receipt in Undeposited Funds.
        $customer = Contact::create(['display_name' => 'Refunded Customer', 'is_customer' => true]);
        $payment = overpaymentReceipt($customer, 10000);
        $refund = app(RecordCustomerRefund::class)->handle($payment, [
            'method' => 'card',
            'amount_cents' => 4000,
            'refund_date' => now()->toDateString(),
            'deposit_to_account_id' => $this->undeposited->id,
        ]);

        $deposit = app(SaveDeposit::class)->handle(depositGuardData([
            ['customer_receipt_id' => $payment->id],
            ['customer_receipt_id' => $refund->id],
        ]));
        app(DepositPoster::class)->post($deposit);

        expect($deposit->fresh()->amount_cents)->toBe(6000)
            ->and($deposit->lines->firstWhere('customer_receipt_id', $refund->id)->amount_cents)->toBe(-4000)
            ->and((int) $this->undeposited->fresh()->balance_cents)->toBe(0);
    });

    it('applies the same checks to pay-now sales receipts', function () {
        $customer = Contact::create(['display_name' => 'Walk-in', 'is_customer' => true]);
        $sale = fn (int $accountId) => tap(app(SaveSalesReceipt::class)->handle([
            'contact_id' => $customer->id,
            'receipt_date' => '2026-05-02',
            'deposit_to_account_id' => $accountId,
            'lines' => [['account_id' => $this->income->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
        ]), fn (SalesReceipt $receipt) => app(SalesReceiptPoster::class)->post($receipt))->fresh();

        $parked = $sale($this->undeposited->id);
        $banked = $sale($this->bank->id);

        expect(fn () => app(SaveDeposit::class)->handle(depositGuardData([
            ['sales_receipt_id' => $banked->id],
        ])))->toThrow(PostingValidationException::class, 'was not received into Undeposited Funds');

        expect(fn () => app(SaveDeposit::class)->handle(depositGuardData([
            ['sales_receipt_id' => $parked->id, 'amount_cents' => 99999],
        ])))->toThrow(PostingValidationException::class, 'so it must be deposited for that amount');

        $deposit = app(SaveDeposit::class)->handle(depositGuardData([['sales_receipt_id' => $parked->id]]));

        expect($deposit->amount_cents)->toBe((int) $parked->total_cents);

        expect(fn () => app(SaveDeposit::class)->handle(depositGuardData([['sales_receipt_id' => $parked->id]])))
            ->toThrow(PostingValidationException::class, 'is already on deposit '.$deposit->deposit_no.'.');
    });

    it('refuses to post a draft whose receipt is already on another deposit', function () {
        $receipt = depositGuardReceipt('Q', 10000);
        $posted = app(SaveDeposit::class)->handle(depositGuardData([['customer_receipt_id' => $receipt->id]]));
        app(DepositPoster::class)->post($posted);

        // A draft written around SaveDeposit, as an older release could leave behind.
        $draft = Deposit::create(['bank_account_id' => $this->bank->id, 'deposit_no' => 'DEP-OLD', 'deposit_date' => '2026-05-04']);
        $draft->lines()->create(['customer_receipt_id' => $receipt->id, 'description' => 'Receipt', 'amount_cents' => 10000, 'line_order' => 0]);

        expect(fn () => app(DepositPoster::class)->post($draft->fresh()))
            ->toThrow(PostingValidationException::class, 'Receipt REC-DG-Q is already on deposit '.$posted->deposit_no.'.');

        expect((int) $this->bank->fresh()->balance_cents)->toBe(10000)
            ->and((int) $this->undeposited->fresh()->balance_cents)->toBe(0);
    });
});
