<?php

/*
 * Employee SINs are validated on entry (audit finding D6Payroll-20): nine
 * digits that pass the SIN check digit. 000 000 000, the value the CRA's T4
 * and T4A specifications reserve for "the employee has failed to provide a
 * SIN", records that no SIN is on file.
 */

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\Payroll\SocialInsuranceNumber;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $this->company);
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function sinValidationForm(Contact $employee, string $sin): Testable
{
    return Livewire::test('pages::payroll.employees.form', ['company' => test()->company, 'contact' => $employee])
        ->set('province_of_employment', 'ON')
        ->set('pay_basis', 'salary')
        ->set('annual_salary', '52000.00')
        ->set('td1_federal_claim', '16452.00')
        ->set('td1_provincial_claim', '12989.00')
        ->set('sin', $sin);
}

it('accepts only nine-digit SINs that pass the check digit', function (string $sin, bool $valid) {
    expect(SocialInsuranceNumber::isValid($sin))->toBe($valid);
})->with([
    'a valid SIN' => ['046454286', true],
    'with spaces' => ['046 454 286', true],
    'with hyphens' => ['123-456-782', true],
    'a temporary-resident SIN' => ['927 456 780', true],
    'a failed check digit' => ['123456789', false],
    'two digits swapped' => ['046454268', false],
    'eight digits' => ['12345678', false],
    'ten digits' => ['0464542860', false],
    'letters' => ['A12345678', false],
    'the CRA no-SIN placeholder' => ['000000000', false],
    'blank' => ['', false],
]);

it('rejects a SIN that is not 9 digits on the employee setup form', function () {
    $hire = Contact::create(['display_name' => 'Typo Sin', 'is_employee' => true]);

    sinValidationForm($hire, '123-456-78')
        ->call('save')
        ->assertHasErrors('sin');

    expect($hire->fresh()->payrollProfile)->toBeNull();
});

it('rejects a SIN that fails the check digit on the employee setup form', function () {
    $hire = Contact::create(['display_name' => 'Transposed Sin', 'is_employee' => true]);

    sinValidationForm($hire, '046 454 268')
        ->call('save')
        ->assertHasErrors(['sin' => __('Enter a valid 9-digit Social Insurance Number, or 000 000 000 if the employee hasn’t given you one yet.')]);
});

it('saves a SIN that passes the check digit', function () {
    $hire = Contact::create(['display_name' => 'Valid Sin', 'is_employee' => true]);

    sinValidationForm($hire, '046 454 286')
        ->call('save')
        ->assertHasNoErrors();

    $profile = $hire->fresh()->payrollProfile;

    expect($profile->sinOrNull())->toBe('046454286')
        ->and($profile->sin_last4)->toBe('4286');
});

it('records no SIN when 000 000 000 is entered', function () {
    $hire = Contact::create(['display_name' => 'No Sin Yet', 'is_employee' => true]);

    sinValidationForm($hire, '046454286')->call('save')->assertHasNoErrors();
    sinValidationForm($hire->fresh(), '000 000 000')->call('save')->assertHasNoErrors();

    $profile = $hire->fresh()->payrollProfile;

    expect($profile->sinOrNull())->toBeNull()
        ->and($profile->sin_last4)->toBeNull();
});

it('rejects an invalid SIN in the shared save action too', function () {
    $hire = Contact::create(['display_name' => 'Api Sin', 'is_employee' => true]);

    expect(fn () => app(SaveEmployeePayrollProfile::class)->handle([
        'contact_id' => $hire->id,
        'sin' => '123456789',
        'province_of_employment' => 'ON',
        'pay_basis' => 'salary',
        'annual_salary_cents' => 5200000,
        'td1_federal_claim_cents' => 1645200,
        'td1_provincial_claim_cents' => 1298900,
        'vacation_policy' => 'accrue',
    ]))->toThrow(ValidationException::class);

    expect($hire->fresh()->payrollProfile)->toBeNull();
});
