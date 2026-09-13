<?php

use App\Enums\ChequeStatus;
use App\Enums\CompanyRole;
use App\Enums\DepositStatus;
use App\Enums\TimeOffAccrualMethod;
use App\Enums\TimeOffCategory;
use App\Enums\TimeOffUnit;
use App\Models\Account;
use App\Models\Company;
use App\Models\OpeningBalanceState;
use App\Models\PayrollSchedule;
use App\Models\TimeOffPolicy;
use App\Services\EditLocks\EditLockManager;
use App\Services\OpeningBalances\DepositInTransitSync;
use App\Services\OpeningBalances\OutstandingChequeSync;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

afterEach(function () {
    app()->forgetInstance('current_company');
});

describe('payroll setup dialogs', function () {
    beforeEach(function () {
        $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
        $this->jane = editLockMember($this->company, CompanyRole::Accountant);
        $this->bob = editLockMember($this->company, CompanyRole::Accountant);
        app()->instance('current_company', $this->company);
    });

    it('locks the record while its dialog is open, and releases it on save', function (string $component, string $kind) {
        $record = $kind === 'schedule'
            ? PayrollSchedule::factory()->create(['company_id' => $this->company->id, 'name' => 'Biweekly staff'])
            : TimeOffPolicy::query()->create([
                'name' => 'Sick',
                'code' => 'sick',
                'category' => TimeOffCategory::Sick,
                'unit' => TimeOffUnit::Hours,
                'accrual_method' => TimeOffAccrualMethod::PerPayPeriod,
                'rate_hours' => 1.5,
            ]);

        $this->actingAs($this->jane);
        $janes = Livewire::test($component, ['company' => $this->company])
            ->call('openEdit', $record->id)
            ->assertSet('editingId', $record->id);

        expect($janes->get('editLockToken'))->toHaveLength(40)
            ->and(app(EditLockManager::class)->holderOtherThan($record, $this->bob)?->user->is($this->jane))->toBeTrue();

        $this->actingAs($this->bob);
        Livewire::test($component, ['company' => $this->company])
            ->call('openEdit', $record->id)
            ->assertSet('editingId', null)
            ->assertSet('editLockPendingTakeover', null)
            ->assertDispatched('toast-show');

        $this->actingAs($this->jane);
        $janes->set('f_name', 'Renamed by Jane')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editLockToken', null);

        expect($record->fresh()->name)->toBe('Renamed by Jane')
            ->and(app(EditLockManager::class)->holderOtherThan($record, $this->bob))->toBeNull();
    })->with([
        'pay schedules' => ['pages::payroll.schedules.index', 'schedule'],
        'time-off policies' => ['pages::payroll.time-off-policies.index', 'policy'],
    ]);
});

describe('opening-balance dialogs', function () {
    beforeEach(function () {
        $this->company = Company::factory()->create();
        // The opening-balance workspace is owner-only, so both editors are owners.
        $this->jane = editLockMember($this->company, CompanyRole::Owner);
        $this->bob = editLockMember($this->company, CompanyRole::Owner);
        app()->instance('current_company', $this->company);

        $this->state = OpeningBalanceState::query()->create(['company_id' => $this->company->id, 'as_of_date' => '2026-06-30']);
        $this->bank = Account::query()->where('code', '1000')->firstOrFail();

        $this->cheque = app(OutstandingChequeSync::class)->create($this->state, [
            'bank_account_id' => $this->bank->id,
            'cheque_no' => '4021',
            'cheque_date' => CarbonImmutable::parse('2026-05-14'),
            'payee_name' => 'Acme Roofing',
            'amount_cents' => 20000,
        ]);

        $this->deposit = app(DepositInTransitSync::class)->create($this->state, [
            'bank_account_id' => $this->bank->id,
            'deposit_date' => CarbonImmutable::parse('2026-06-29'),
            'amount_cents' => 5000,
        ]);
    });

    it('locks an outstanding cheque while its dialog is open and refuses removing it', function () {
        $this->actingAs($this->jane);
        $janes = Livewire::test('pages::opening-balances.cheques', ['company' => $this->company])
            ->call('openEdit', $this->cheque->id)
            ->assertSet('editingId', $this->cheque->id);

        expect($janes->get('editLockToken'))->toHaveLength(40);

        $this->actingAs($this->bob);
        $bobs = Livewire::test('pages::opening-balances.cheques', ['company' => $this->company])
            ->call('openEdit', $this->cheque->id)
            ->assertSet('editingId', null);

        expect($bobs->get('editLockPendingTakeover')['name'])->toBe($this->jane->name);

        $bobs->call('remove', $this->cheque->id)->assertDispatched('toast-show');

        expect($this->cheque->fresh()->status)->not->toBe(ChequeStatus::Void);

        $this->actingAs($this->jane);
        $janes->set('f_payee_name', 'Acme Roofing Ltd')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editLockToken', null);

        expect($this->cheque->fresh()->payee_name)->toBe('Acme Roofing Ltd')
            ->and(app(EditLockManager::class)->holderOtherThan($this->cheque, $this->bob))->toBeNull();
    });

    it('locks a deposit in transit while its dialog is open and refuses removing it', function () {
        $this->actingAs($this->jane);
        $janes = Livewire::test('pages::opening-balances.deposits', ['company' => $this->company])
            ->call('openEdit', $this->deposit->id)
            ->assertSet('editingId', $this->deposit->id);

        expect($janes->get('editLockToken'))->toHaveLength(40);

        $this->actingAs($this->bob);
        $bobs = Livewire::test('pages::opening-balances.deposits', ['company' => $this->company])
            ->call('openEdit', $this->deposit->id)
            ->assertSet('editingId', null);

        expect($bobs->get('editLockPendingTakeover')['name'])->toBe($this->jane->name);

        $bobs->call('remove', $this->deposit->id)->assertDispatched('toast-show');

        expect($this->deposit->fresh()->status)->not->toBe(DepositStatus::Void);
    });

    it('removes an opening-balance cheque nobody is editing', function () {
        $this->actingAs($this->bob);

        Livewire::test('pages::opening-balances.cheques', ['company' => $this->company])
            ->call('remove', $this->cheque->id);

        expect($this->cheque->fresh()->status)->toBe(ChequeStatus::Void);
    });

    it('blocks the cheque edit page while the opening-balance dialog holds the cheque', function () {
        $this->actingAs($this->jane);
        Livewire::test('pages::opening-balances.cheques', ['company' => $this->company])
            ->call('openEdit', $this->cheque->id);

        $this->actingAs($this->bob);
        Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $this->cheque])
            ->assertSet('editLockBlocked', true)
            ->assertSee($this->jane->name)
            ->set('memo', 'Bob was here')
            ->call('saveDraft')
            ->assertDispatched('toast-show');

        expect($this->cheque->fresh()->memo)->not->toBe('Bob was here');
    });

    it('keeps the opening-balance dialog closed while the cheque edit page holds the cheque', function () {
        $this->actingAs($this->jane);
        $form = Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $this->cheque])
            ->assertSet('editLockBlocked', false);

        expect($form->get('editLockToken'))->toHaveLength(40);

        $this->actingAs($this->bob);
        Livewire::test('pages::opening-balances.cheques', ['company' => $this->company])
            ->call('openEdit', $this->cheque->id)
            ->assertSet('editingId', null)
            ->call('remove', $this->cheque->id);

        expect($this->cheque->fresh()->status)->not->toBe(ChequeStatus::Void);
    });
});
