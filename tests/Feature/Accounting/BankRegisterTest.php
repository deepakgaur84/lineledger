<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Posting\ChequePoster;
use Livewire\Livewire;

it('offers Reconcile, Import statement and Bank rules under the Actions menu', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    Livewire::actingAs($user)
        ->test('pages::banking.register', ['company' => $company])
        ->assertSee('Actions')
        ->assertSee('Reconcile')
        ->assertSee('Import statement')
        ->assertSee('Bank rules')
        ->assertSeeHtml(route('banking.import', ['company' => $company->slug]))
        ->assertSeeHtml(route('banking.rules', ['company' => $company->slug]));
});

it('shows cleared state read-only — the register cannot clear or unclear a line', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    $cheque = Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => '2001',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Test',
    ]);

    $cheque->lines()->create([
        'account_id' => $expense->id,
        'description' => 'X',
        'amount_cents' => 1000,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque);

    $bankLine = JournalLine::query()
        ->where('account_id', $bank->id)
        ->where('credit_cents', 1000)
        ->first();

    expect($bankLine->cleared_at)->toBeNull();

    $this->actingAs($user);

    $response = $this->get(route('banking.register', ['company' => $company->slug, 'account' => $bank->id]));
    $response->assertOk();

    // Clearing belongs to the reconciliation, so the register offers no way to
    // do it: no per-row toggle, no bulk actions, and no statement-balance field
    // to keep in step by hand.
    $register = Livewire::test('pages::banking.register', ['company' => $company])
        ->set('account_id', $bank->id);

    foreach (['toggleClear', 'clearAll', 'uncleared'] as $method) {
        expect(method_exists($register->instance(), $method))->toBeFalse();
    }

    $register->assertDontSee('Statement balance')
        ->assertDontSee('Clear all')
        ->assertDontSee('Unclear all')
        // The Show cleared filter stays — it is how you hide accounted-for rows.
        ->assertSee('Show cleared');

    expect($bankLine->fresh()->cleared_at)->toBeNull();

    // A line cleared by a reconciliation still reads as cleared here.
    $bankLine->forceFill(['cleared_at' => now()])->save();

    Livewire::test('pages::banking.register', ['company' => $company])
        ->set('account_id', $bank->id)
        ->assertSeeHtml('data-test="register-cleared-mark"');

    app()->forgetInstance('current_company');
});
