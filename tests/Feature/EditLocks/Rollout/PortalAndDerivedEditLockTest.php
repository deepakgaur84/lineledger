<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BankRuleMatchType;
use App\Enums\CompanyRole;
use App\Enums\EditLockState;
use App\Enums\PayBasis;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\EditLocks\EditLockLostException;
use App\Models\Account;
use App\Models\BankRule;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EditLock;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Services\Classification\Support\MerchantKey;
use App\Services\EditLocks\EditLockManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

afterEach(function () {
    app()->forgetInstance('current_company');
});

/*
|--------------------------------------------------------------------------
| Employee portal: an employee editing their own info vs. staff editing them
|--------------------------------------------------------------------------
*/

describe('employee portal edit-info', function () {
    beforeEach(function () {
        $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
        app()->instance('current_company', $this->company);

        $this->staff = editLockMember($this->company, CompanyRole::Accountant);

        $this->employee = Contact::create([
            'display_name' => 'Portal Employee',
            'email' => 'portal-employee@emp.test',
            'is_employee' => true,
            'is_active' => true,
            'billing_line1' => '1 Original Street',
        ]);

        EmployeePayrollProfile::factory()->create([
            'contact_id' => $this->employee->id,
            'province_of_employment' => 'AB',
            'pay_basis' => PayBasis::Salary->value,
            'annual_salary_cents' => 6000000,
            'payroll_schedule_id' => PayrollSchedule::factory()->create()->id,
            'td1_federal_claim_cents' => 1612900,
            'td1_provincial_claim_cents' => 2232300,
            'is_active' => true,
        ]);

        $this->locks = app(EditLockManager::class);
        $this->actingAs($this->employee->fresh(), 'customer');
    });

    it('refuses the save while staff are editing the employee, leaving their details untouched', function () {
        $page = Livewire::test('pages::employee-portal.edit-info', ['company' => $this->company]);

        $this->locks->acquire($this->employee, $this->staff);

        $page->set('billing_line1', '99 Portal Avenue')
            ->set('td1_federal_claim', '15705.00')
            ->call('save')
            ->assertHasErrors(['save'])
            ->assertSee(__('Your employer is updating your details right now. Please try again in a few minutes.'))
            ->assertNoRedirect();

        $fresh = $this->employee->fresh();

        expect($fresh->billing_line1)->toBe('1 Original Street')
            ->and($fresh->payrollProfile->td1_federal_claim_cents)->toBe(1612900);
    });

    it('refuses the save when staff opened and closed the employee after the page loaded', function () {
        $page = Livewire::test('pages::employee-portal.edit-info', ['company' => $this->company])
            ->assertSet('editLockVersion', null);

        $lease = $this->locks->acquire($this->employee, $this->staff);
        $this->locks->release($lease->token, $this->staff);

        $page->set('billing_line1', '99 Portal Avenue')
            ->call('save')
            ->assertHasErrors(['save'])
            ->assertSee(__('Your details were opened or updated by your employer while this page was open. Reload to see the latest before saving.'))
            ->assertNoRedirect();

        expect($this->employee->fresh()->billing_line1)->toBe('1 Original Street');
    });

    it('refuses the save when staff saved the employee after the page loaded, even at the same version', function () {
        $lease = $this->locks->acquire($this->employee, $this->staff);

        $page = Livewire::test('pages::employee-portal.edit-info', ['company' => $this->company])
            ->assertSet('editLockVersion', $lease->version);

        $this->travel(1)->seconds();
        $this->locks->afterHolderCall($this->employee, $lease->token); // staff saved
        $this->locks->release($lease->token, $this->staff);

        $page->set('billing_line1', '99 Portal Avenue')
            ->call('save')
            ->assertHasErrors(['save']);

        expect($this->employee->fresh()->billing_line1)->toBe('1 Original Street');
    });

    it('saves when nothing changed since the page loaded, and moves the version on', function () {
        $lease = $this->locks->acquire($this->employee, $this->staff);
        $this->locks->release($lease->token, $this->staff);

        $page = Livewire::test('pages::employee-portal.edit-info', ['company' => $this->company])
            ->assertSet('editLockVersion', $lease->version)
            ->set('billing_line1', '99 Portal Avenue')
            ->call('save')
            ->assertHasNoErrors();

        $current = $this->locks->currentVersion($this->employee);

        expect($this->employee->fresh()->billing_line1)->toBe('99 Portal Avenue')
            ->and($current)->not->toBe($lease->version)
            ->and($page->get('editLockVersion'))->toBe($current);
    });
});

/*
|--------------------------------------------------------------------------
| Payment reminders: "turn off reminders" writes the customer record
|--------------------------------------------------------------------------
*/

describe('payment reminders disableReminders', function () {
    beforeEach(function () {
        $this->company = Company::factory()->create(['timezone' => 'UTC']);
        app()->instance('current_company', $this->company);

        $this->jane = editLockMember($this->company, CompanyRole::Accountant);
        $this->bob = editLockMember($this->company, CompanyRole::Owner);
        $this->customer = Contact::create(['display_name' => 'Acme', 'email' => 'buyer@acme.test', 'is_customer' => true, 'reminder_emails_enabled' => true]);

        $this->locks = app(EditLockManager::class);
    });

    it('refuses to turn off reminders for a customer someone is editing', function () {
        $this->locks->acquire($this->customer, $this->jane);

        $this->actingAs($this->bob);
        Livewire::test('pages::reminders.index', ['company' => $this->company])
            ->call('disableReminders', $this->customer->id)
            ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'] ?? '', $this->jane->name));

        expect($this->customer->fresh()->reminder_emails_enabled)->toBeTrue();
    });

    it('turns reminders off when nobody is editing, invalidating a lapsed editor', function () {
        $lease = $this->locks->acquire($this->customer, $this->jane);
        $this->locks->release($lease->token, $this->jane);

        $this->actingAs($this->bob);
        Livewire::test('pages::reminders.index', ['company' => $this->company])
            ->call('disableReminders', $this->customer->id);

        expect($this->customer->fresh()->reminder_emails_enabled)->toBeFalse()
            ->and($this->locks->currentVersion($this->customer))->not->toBe($lease->version);
    });
});

/*
|--------------------------------------------------------------------------
| "Always do this" bank rules: updating an existing rule touches its lock
|--------------------------------------------------------------------------
*/

describe('bank rule from a statement line', function () {
    beforeEach(function () {
        Storage::fake('local');

        $this->company = Company::factory()->create();
        app()->instance('current_company', $this->company);

        $this->jane = editLockMember($this->company, CompanyRole::Accountant);
        $this->bob = editLockMember($this->company, CompanyRole::Owner);

        $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
        $this->expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
        $this->expenseB = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->skip(1)->firstOrFail();

        $this->locks = app(EditLockManager::class);

        $this->ruleFor = fn (string $description): BankRule => BankRule::create([
            'name' => 'Old rule',
            'match_type' => BankRuleMatchType::MerchantKey->value,
            'match_pattern' => MerchantKey::from($description),
            'action_account_id' => $this->expenseB->id,
            'priority' => 0,
            'is_active' => true,
        ]);
    });

    it('does not block, but stops an open bank-rule dialog saving over a rule the review feed updated', function () {
        $import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
        $line = BankStatementLine::factory()->create([
            'bank_statement_import_id' => $import->id,
            'account_id' => $this->bank->id,
            'txn_date' => '2026-06-10',
            'amount_cents' => -252000,
            'description' => 'L SOCIO DIGITAL FEE/FRA REF 8812',
            'match_status' => StatementLineMatchStatus::Unmatched->value,
        ]);
        $rule = ($this->ruleFor)($line->description);

        $held = $this->locks->acquire($rule, $this->jane);

        $this->actingAs($this->bob);
        Livewire::test('pages::banking.review', ['company' => $this->company])
            ->set("categories.{$line->id}", $this->expense->id)
            ->call('createRule', $line->id)
            ->assertHasNoErrors();

        expect(BankRule::query()->count())->toBe(1)
            ->and($rule->fresh()->action_account_id)->toBe($this->expense->id)
            ->and($this->locks->currentVersion($rule))->not->toBe($held->version);

        try {
            $this->locks->verify($rule, $held->token, $held->version, $this->jane);
            $this->fail('The dialog holding the rule should no longer be able to save.');
        } catch (EditLockLostException $e) {
            expect($e->state)->toBe(EditLockState::Changed);
        }
    });

    it('touches an existing rule updated from the import wizard, and leaves a brand-new rule without a lock row', function () {
        $csv = "Date,Description,Amount\n2026-02-03,\"Pre-Authorized Payment, L SOCIO DIGITAL FEE/FRA    ,\",-2520.00\n";

        $this->actingAs($this->bob);
        $wizard = Livewire::test('pages::banking.import', ['company' => $this->company])
            ->set('account_id', $this->bank->id)
            ->set('upload', UploadedFile::fake()->createWithContent('statement.csv', $csv))
            ->call('startImport');

        $line = BankStatementImport::query()->latest('id')->firstOrFail()->lines()->firstOrFail();

        // First run creates the rule: nothing to invalidate, no lock row appears.
        $wizard->set("lineCategory.{$line->id}", $this->expenseB->id)->call('createRule', $line->id);
        $rule = BankRule::query()->sole();
        expect(EditLock::query()->count())->toBe(0);

        $lease = $this->locks->acquire($rule, $this->jane);
        $this->locks->release($lease->token, $this->jane);

        // Second run updates the same rule: the version moves on.
        $wizard->set("lineCategory.{$line->id}", $this->expense->id)->call('createRule', $line->id);

        expect(BankRule::query()->sole()->action_account_id)->toBe($this->expense->id)
            ->and($this->locks->currentVersion($rule))->not->toBe($lease->version);
    });
});
