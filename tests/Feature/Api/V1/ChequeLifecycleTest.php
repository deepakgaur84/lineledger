<?php

use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\JournalLine;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function chequeAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function chequePayload(array $overrides = []): array
{
    return array_merge([
        'bank_account_id' => test()->bank->id,
        'cheque_no' => '1001',
        'cheque_date' => '2026-05-20',
        'payee_name' => 'Hydro One',
        'lines' => [[
            'account_id' => test()->expense->id,
            'description' => 'Utilities',
            'amount_cents' => 7500,
        ]],
    ], $overrides);
}

it('lists cheques with pagination meta', function () {
    $this->postJson('/api/v1/cheques', chequePayload(), chequeAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/cheques', chequeAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single cheque', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(), chequeAuthHeader())->json('data.id');

    $this->getJson("/api/v1/cheques/{$id}", chequeAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.amount_cents', 7500);
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/cheques', chequePayload(['post' => false]), chequeAuthHeader());

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(['post' => false]), chequeAuthHeader())->json('data.id');

    $this->postJson("/api/v1/cheques/{$id}/post", [], chequeAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(Cheque::withoutGlobalScopes()->find($id)->journal_entry_id)->not->toBeNull();
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(['post' => false]), chequeAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/cheques/{$id}", chequePayload([
        'memo' => 'Updated memo',
        'lines' => [[
            'account_id' => $this->expense->id, 'amount_cents' => 9900,
        ]],
    ]), chequeAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated memo')
        ->assertJsonPath('data.amount_cents', 9900)
        ->assertJsonPath('data.status', 'draft');
});

it('reposts a posted cheque in place on update', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(), chequeAuthHeader())->json('data.id');
    $entryId = Cheque::withoutGlobalScopes()->find($id)->journal_entry_id;

    $this->patchJson("/api/v1/cheques/{$id}", chequePayload([
        'memo' => 'Corrected',
        'lines' => [['account_id' => $this->expense->id, 'amount_cents' => 12500]],
    ]), chequeAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.amount_cents', 12500)
        ->assertJsonPath('data.journal_entry_id', $entryId);

    // The same journal entry was rebuilt — no reversal, no second entry.
    $lines = JournalLine::withoutGlobalScopes()->where('journal_entry_id', $entryId)->get();

    expect($lines->sum('debit_cents'))->toBe(12500)
        ->and($lines->sum('credit_cents'))->toBe(12500);
});

it('returns 409 when updating a voided cheque', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(), chequeAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/cheques/{$id}", [], chequeAuthHeader())->assertStatus(200);

    $this->patchJson("/api/v1/cheques/{$id}", chequePayload([
        'lines' => [['account_id' => $this->expense->id, 'amount_cents' => 100]],
    ]), chequeAuthHeader())
        ->assertStatus(409);
});

it('voids a posted cheque and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(), chequeAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/cheques/{$id}", [], chequeAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(Cheque::withoutGlobalScopes()->find($id)->status)->toBe(ChequeStatus::Void);
});

it('deletes a draft cheque', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(['post' => false]), chequeAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/cheques/{$id}", [], chequeAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/cheques/{$id}", chequeAuthHeader())->assertStatus(404);
});

it('returns 404 for another company\'s cheque', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/cheques', chequePayload(), chequeAuthHeader())->json('data.id');

    $this->getJson("/api/v1/cheques/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a banking:read key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['banking:read']);

    $this->getJson('/api/v1/cheques', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/cheques', chequePayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});

/**
 * An Accounts Receivable / Payable line carries its own customer or vendor —
 * the payee is who the cheque is made out to, not necessarily whose balance it
 * settles. Mirrors the Livewire form's rule (ChequeLineContactTest).
 */
it('stamps a line contact on the AR leg and the payee on the bank leg', function () {
    app()->instance('current_company', $this->company);
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->orderBy('code')->firstOrFail();
    $payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);
    app()->forgetInstance('current_company');

    $response = $this->withHeaders(chequeAuthHeader())->postJson('/api/v1/cheques', chequePayload([
        'payee_contact_id' => $payee->id,
        'lines' => [[
            'account_id' => $ar->id,
            'contact_id' => $customer->id,
            'description' => 'Refund on account',
            'amount_cents' => 100000,
        ]],
    ]));

    $response->assertCreated()->assertJsonPath('data.lines.0.contact_id', $customer->id);

    expect((int) JournalLine::query()->where('account_id', $ar->id)->value('contact_id'))->toBe($customer->id)
        ->and((int) JournalLine::query()->where('account_id', $this->bank->id)->value('contact_id'))->toBe($payee->id);
});

it('rejects posting an Accounts Receivable line with no customer', function () {
    app()->instance('current_company', $this->company);
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->orderBy('code')->firstOrFail();
    app()->forgetInstance('current_company');

    $this->withHeaders(chequeAuthHeader())
        ->postJson('/api/v1/cheques', chequePayload([
            'lines' => [['account_id' => $ar->id, 'amount_cents' => 100000]],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.contact_id');

    expect(Cheque::query()->count())->toBe(0);
});

it('accepts an unattributed Accounts Receivable line on a draft', function () {
    app()->instance('current_company', $this->company);
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->orderBy('code')->firstOrFail();
    app()->forgetInstance('current_company');

    $this->withHeaders(chequeAuthHeader())
        ->postJson('/api/v1/cheques', chequePayload([
            'post' => false,
            'lines' => [['account_id' => $ar->id, 'amount_cents' => 100000]],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.status', ChequeStatus::Draft->value);
});

/**
 * The mailing address is snapshotted onto the cheque: an explicit payee_address
 * wins, and omitting it defaults from the payee contact's billing address.
 */
it('stores an explicit payee address and echoes it back', function () {
    $response = $this->withHeaders(chequeAuthHeader())->postJson('/api/v1/cheques', chequePayload([
        'payee_address' => [
            'line1' => '500 New Avenue',
            'city' => 'Winnipeg',
            'region' => 'MB',
            'postal_code' => 'R3C 1A1',
            'country' => 'ca',
        ],
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.payee_address.line1', '500 New Avenue')
        ->assertJsonPath('data.payee_address.country', 'CA');

    expect(Cheque::query()->firstOrFail()->payee_city)->toBe('Winnipeg');
});

it('defaults the payee address from the linked contact', function () {
    app()->instance('current_company', $this->company);
    $vendor = Contact::factory()->vendor()->create([
        'company_id' => $this->company->id,
        'billing_line1' => '12 Old Street',
        'billing_city' => 'Brandon',
    ]);
    app()->forgetInstance('current_company');

    $this->withHeaders(chequeAuthHeader())
        ->postJson('/api/v1/cheques', chequePayload(['payee_contact_id' => $vendor->id]))
        ->assertCreated()
        ->assertJsonPath('data.payee_address.line1', '12 Old Street')
        ->assertJsonPath('data.payee_address.city', 'Brandon');
});
