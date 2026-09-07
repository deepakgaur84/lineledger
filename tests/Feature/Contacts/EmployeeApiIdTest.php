<?php

use App\Models\Company;
use App\Models\Contact;
use Livewire\Livewire;

/**
 * The Employees page shows an employee's API id.
 *
 * `sales_rep_id` on an invoice or credit memo is a contact id, and it is the one
 * thing an integrator cannot work out from the UI — the "Employee ID" field on
 * the same form is a free-text payroll code, not this. Mirrors "Account ID
 * (API)" on the Chart of Accounts.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->employee = Contact::create(['display_name' => 'Annika Anderson', 'is_employee' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('shows the API contact id when editing an employee', function () {
    Livewire::test('pages::employees.index', ['company' => $this->company])
        ->call('openEdit', $this->employee->id)
        ->assertSeeHtml('data-test="employee-api-id"')
        ->assertSee('Contact ID (API)')
        ->assertSee('sales_rep_id')
        ->assertSee((string) $this->employee->id);
});

it('does not offer an API id on a new employee, which has none yet', function () {
    Livewire::test('pages::employees.index', ['company' => $this->company])
        ->call('openCreate')
        ->assertDontSeeHtml('data-test="employee-api-id"');
});
