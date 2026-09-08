<?php

declare(strict_types=1);

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Mcp\Tools\SalesReportTool;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use Laravel\Mcp\Request;

it('SalesReport: renders a sales-by-customer report without error', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company);

    $response = (new SalesReportTool)->handle(new Request([
        'period' => 'this_year',
        'group_by' => 'customer',
    ]));

    expect($response->isError())->toBeFalse();
    expect((string) $response->content())->toContain('Sales by customer');
});

it('SalesReport: renders a sales-by-item report without error', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company);

    $response = (new SalesReportTool)->handle(new Request([
        'period' => 'this_year',
        'group_by' => 'item',
    ]));

    expect($response->isError())->toBeFalse();
});

it('SalesReport: denies access without the sales:read ability', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company, ['purchases:read']);

    $response = (new SalesReportTool)->handle(new Request(['period' => 'this_year']));

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('sales:read');
});

it('SalesReport: groups by sales rep, crediting the rep on the invoice', function (): void {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $customer = Contact::create(['company_id' => $company->id, 'display_name' => 'Acme', 'is_customer' => true]);
    $rep = Contact::create(['company_id' => $company->id, 'display_name' => 'Jane Rep', 'is_employee' => true]);

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'contact_id' => $customer->id,
        'sales_rep_id' => $rep->id,
        'invoice_no' => 'INV-REP-1',
        // The company's own clock, not the server's — resolvePeriod() windows on
        // currentDateTime(), which can trail UTC by a day.
        'invoice_date' => $company->currentDateTime()->startOfMonth()->toDateString(),
        'due_date' => $company->currentDateTime()->startOfMonth()->toDateString(),
        'status' => InvoiceStatus::Posted->value,
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id, 'description' => 'x', 'quantity' => '1',
        'unit_price_cents' => 40000, 'line_subtotal_cents' => 40000,
        'line_tax_cents' => 0, 'line_total_cents' => 40000, 'line_order' => 0,
    ]);

    bindMcpTenant($company);

    $response = (new SalesReportTool)->handle(new Request([
        'period' => 'this_year',
        'group_by' => 'rep',
    ]));

    expect($response->isError())->toBeFalse();

    $content = (string) $response->content();

    expect($content)->toContain('Sales by sales rep')
        ->and($content)->toContain('Jane Rep')
        ->and($content)->toContain('400.00');
});

it('SalesReport: falls back to customer for an unknown group_by', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company);

    $response = (new SalesReportTool)->handle(new Request([
        'period' => 'this_year',
        'group_by' => 'arranger',
    ]));

    expect($response->isError())->toBeFalse();
    expect((string) $response->content())->toContain('Sales by customer');
});
