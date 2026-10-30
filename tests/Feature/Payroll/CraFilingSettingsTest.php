<?php

/*
 * The filer details the CRA T4/T4A XML reads from Payroll settings (audit
 * finding D6Payroll-06): the payroll program account (BN + RP), the CRA
 * contact, the box 45 dental benefits code and an optional representative ID.
 */

use App\Enums\CompanyRole;
use App\Enums\DentalBenefitsCode;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true, 'tax_number' => '123456789RT0001']);
    app()->instance('current_company', $this->company);
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('combines the Business Number and RP account into the payroll account number', function (?string $bn, ?string $rp, ?string $expected) {
    $this->company->forceFill(['payroll_business_number' => $bn, 'payroll_rp_account' => $rp]);

    expect($this->company->payrollAccountNumber())->toBe($expected);
})->with([
    'BN and RP' => ['123456789', 'RP0001', '123456789RP0001'],
    'spaces and lower case' => ['123 456 789', 'rp 0001', '123456789RP0001'],
    'the reference number alone' => ['123456789', '0002', '123456789RP0002'],
    'the full account in the BN field' => ['123456789RP0003', null, '123456789RP0003'],
    'the full account in the RP field' => ['123456789', '123456789RP0004', '123456789RP0004'],
    'the GST/HST program instead of RP' => ['123456789', 'RT0001', null],
    'an eight-digit BN' => ['12345678', 'RP0001', null],
    'no RP account' => ['123456789', null, null],
    'nothing set' => [null, null, null],
    'two different roots' => ['123456789', '987654321RP0001', null],
]);

it('never falls back to the GST/HST number for the payroll account', function () {
    expect($this->company->payrollAccountNumber())->toBeNull();
});

it('normalizes the CRA payroll program fields when saving Payroll settings', function () {
    Livewire::test('pages::settings.payroll', ['company' => $this->company])
        ->set('f_business_number', '123 456 789')
        ->set('f_rp_account', '0001')
        ->set('f_contact_phone', '(613) 555-0101 ext. 22')
        ->set('f_rep_id', 'a1b2c3d')
        ->set('f_dental_benefits_code', (string) DentalBenefitsCode::PayeeOnly->value)
        ->call('save')
        ->assertHasNoErrors();

    $this->company->refresh();

    expect($this->company->payroll_business_number)->toBe('123456789')
        ->and($this->company->payroll_rp_account)->toBe('RP0001')
        ->and($this->company->payrollAccountNumber())->toBe('123456789RP0001')
        ->and($this->company->payroll_cra_rep_id)->toBe('A1B2C3D')
        ->and($this->company->payroll_dental_benefits_code)->toBe(DentalBenefitsCode::PayeeOnly);
});

it('splits a full payroll account typed into the Business Number field', function () {
    Livewire::test('pages::settings.payroll', ['company' => $this->company])
        ->set('f_business_number', '123456789RP0002')
        ->set('f_rp_account', '')
        ->call('save')
        ->assertHasNoErrors();

    $this->company->refresh();

    expect($this->company->payroll_business_number)->toBe('123456789')
        ->and($this->company->payroll_rp_account)->toBe('RP0002');
});

it('rejects payroll program details the CRA would not accept', function () {
    Livewire::test('pages::settings.payroll', ['company' => $this->company])
        ->set('f_business_number', '12345678')
        ->set('f_rp_account', 'RT0001')
        ->set('f_contact_phone', '555-0101')
        ->set('f_rep_id', 'TOO-LONG-ID')
        ->call('save')
        ->assertHasErrors(['f_business_number', 'f_rp_account', 'f_contact_phone', 'f_rep_id']);

    expect($this->company->fresh()->payroll_business_number)->toBeNull();
});

it('saves an employee box 45 override from the payroll setup form', function () {
    $employee = Contact::create(['display_name' => 'Family Plan', 'is_employee' => true]);

    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $employee])
        ->set('province_of_employment', 'ON')
        ->set('pay_basis', 'salary')
        ->set('annual_salary', '52000.00')
        ->set('td1_federal_claim', '16452.00')
        ->set('td1_provincial_claim', '12989.00')
        ->set('dental_benefits_code', (string) DentalBenefitsCode::PayeeSpouseAndChildren->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($employee->fresh()->payrollProfile->dental_benefits_code)->toBe(DentalBenefitsCode::PayeeSpouseAndChildren);

    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $employee->fresh()])
        ->assertSet('dental_benefits_code', (string) DentalBenefitsCode::PayeeSpouseAndChildren->value)
        ->set('dental_benefits_code', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($employee->fresh()->payrollProfile->dental_benefits_code)->toBeNull();
});
