<?php

use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Exceptions\EditLocks\EditLockLostException;
use App\Http\Middleware\RejectEditLockedRecords;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\StockAdjustment;
use App\Models\TaxReturnPayment;
use App\Services\EditLocks\EditLockManager;
use App\Support\EditLocks\EditLockables;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->headers = ['Authorization' => "Bearer {$plain}"];

    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->invoice = editLockDraftInvoice($this->company);
    $this->employee = Contact::query()->create(['display_name' => 'Edit Lock Employee', 'is_employee' => true]);
    app()->forgetInstance('current_company');

    $this->locks = app(EditLockManager::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('answers 423 with Retry-After to writes on an invoice someone is editing, and never names them', function () {
    $this->locks->acquire($this->invoice, $this->jane);

    $patch = $this->patchJson("/api/v1/invoices/{$this->invoice->id}", ['memo' => 'From the API'], $this->headers)
        ->assertStatus(423)
        ->assertExactJson(['message' => 'This invoice is being edited by someone else in LineLedger. Try again shortly.']);

    expect((int) $patch->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(config('edit_locks.ttl_seconds'))
        ->and($patch->getContent())->not->toContain($this->jane->name);

    $this->postJson("/api/v1/invoices/{$this->invoice->id}/post", [], $this->headers)->assertStatus(423);
    $this->deleteJson("/api/v1/invoices/{$this->invoice->id}", [], $this->headers)->assertStatus(423);

    $this->getJson("/api/v1/invoices/{$this->invoice->id}", $this->headers)->assertOk();

    $fresh = $this->invoice->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(InvoiceStatus::Draft)
        ->and($fresh->journal_entry_id)->toBeNull();
});

it('answers 423 on a contact resource whatever its parameter is called', function () {
    $this->locks->acquire($this->employee, $this->jane);

    $this->patchJson("/api/v1/employees/{$this->employee->id}", ['display_name' => 'Renamed'], $this->headers)
        ->assertStatus(423)
        ->assertJsonPath('message', 'This employee is being edited by someone else in LineLedger. Try again shortly.');
});

it('lets the write through once the lease lapses, and the lapsed web page can no longer save', function () {
    $lease = $this->locks->acquire($this->invoice, $this->jane);

    $this->travel(config('edit_locks.ttl_seconds') + 1)->seconds();

    $this->patchJson("/api/v1/invoices/{$this->invoice->id}", [
        'contact_id' => $this->invoice->contact_id,
        'invoice_date' => '2026-06-01',
        'memo' => 'From the API',
        'lines' => [[
            'account_id' => $this->invoice->lines()->value('account_id'),
            'quantity' => '1',
            'unit_price_cents' => 10000,
        ]],
    ], $this->headers)->assertStatus(200);

    expect(fn () => $this->locks->verify($this->invoice, $lease->token, $lease->version, $this->jane))
        ->toThrow(EditLockLostException::class);
});

it('still creates records while an unrelated record is being edited', function () {
    $this->locks->acquire($this->invoice, $this->jane);

    $this->postJson('/api/v1/customers', ['display_name' => 'Brand New'], $this->headers)->assertStatus(201);
});

it('attaches the lock check after route-model binding on every update, delete and action route', function () {
    $router = app('router');
    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'api.v1.') || ! str_contains($route->uri(), '{')) {
            continue;
        }

        $isWrite = array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE', 'POST']) !== [];

        if (! $isWrite) {
            continue;
        }

        $middleware = $router->gatherRouteMiddleware($route);
        $bindings = array_search(SubstituteBindings::class, $middleware, true);
        $lock = array_search(RejectEditLockedRecords::class, $middleware, true);

        expect($lock)->not->toBeFalse("{$name} is missing RejectEditLockedRecords")
            ->and($bindings)->not->toBeFalse()
            ->and($lock)->toBeGreaterThan($bindings, "{$name} checks the lock before binding");

        $checked++;
    }

    expect($checked)->toBeGreaterThan(50);
});

it('leaves resources with no web editor out of the lock', function () {
    expect(EditLockables::supports(BankReconciliation::class))->toBeFalse()
        ->and(EditLockables::supports(StockAdjustment::class))->toBeFalse()
        ->and(EditLockables::supports(TaxReturnPayment::class))->toBeFalse();
});

it('answers 404, not 423, for a contact of the wrong role, without marking it changed', function () {
    $this->locks->acquire($this->employee, $this->jane);
    $version = $this->locks->currentVersion($this->employee);

    $this->patchJson("/api/v1/customers/{$this->employee->id}", ['display_name' => 'Renamed'], $this->headers)
        ->assertStatus(404);

    expect($this->locks->currentVersion($this->employee))->toBe($version);
});
