<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
|--------------------------------------------------------------------------
| Transactions report — tenant isolation
|--------------------------------------------------------------------------
| journal_lines has no company_id and JournalLine carries no CompanyScope, so
| the report must confine itself to the current company's entries. Otherwise
| an unfiltered range pulls in every tenant's lines (their entries resolve to
| null under the scope and the page errors), exports and group totals list
| them outright, and a foreign ?account= or ?contact= id reads another
| company's ledger.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    $this->otherCompany = Company::factory()->create();

    app()->instance('current_company', $this->otherCompany);
    $this->foreignAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->foreignContact = Contact::factory()->vendor()->create(['display_name' => 'Foreign Payee']);
    tenantIsolationLine($this->foreignAccount, $this->foreignContact, 'FOREIGN-MEMO', 777700);

    app()->instance('current_company', $this->company);
    $this->account = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->contact = Contact::factory()->vendor()->create(['display_name' => 'Own Payee']);
    tenantIsolationLine($this->account, $this->contact, 'OWN-MEMO', 12300);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * One posted journal line in the currently bound company, dated inside 2026.
 */
function tenantIsolationLine(Account $account, Contact $contact, string $memo, int $cents): JournalLine
{
    $entry = JournalEntry::create([
        'entry_no' => 'JE-'.$memo,
        'entry_date' => '2026-05-01',
        'memo' => $memo,
        'is_posted' => true,
    ]);

    return JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $account->id,
        'contact_id' => $contact->id,
        'debit_cents' => $cents,
        'credit_cents' => 0,
        'entry_date' => '2026-05-01',
        'is_posted' => true,
        'memo' => $memo,
    ]);
}

function tenantIsolationReport(array $params = []): Testable
{
    return Livewire::withQueryParams(['start' => '2026-01-01', 'end' => '2026-12-31', ...$params])
        ->test('pages::reports.transactions', ['company' => test()->company]);
}

it('lists only the current company\'s lines over an unfiltered range', function () {
    tenantIsolationReport()
        ->assertOk()
        ->assertSee('OWN-MEMO')
        ->assertDontSee('FOREIGN-MEMO')
        ->assertDontSee('7,777.00');
});

it('shows nothing for another company\'s account or contact id', function () {
    tenantIsolationReport(['account' => $this->foreignAccount->id])
        ->assertOk()
        ->assertDontSee('FOREIGN-MEMO')
        ->assertSee('No transactions match these filters.');

    tenantIsolationReport(['contact' => $this->foreignContact->id])
        ->assertOk()
        ->assertDontSee('FOREIGN-MEMO')
        ->assertSee('No transactions match these filters.');
});

it('keeps other companies\' lines out of the CSV export', function () {
    $response = tenantIsolationReport()->instance()->exportCsv();
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('OWN-MEMO')
        ->and($csv)->not->toContain('FOREIGN-MEMO')
        ->and($csv)->not->toContain('7777.00');
});

it('keeps other companies\' lines out of the group totals', function () {
    $totals = tenantIsolationReport(['group' => 'month'])->instance()->groupTotals;

    expect($totals)->toBe(['2026-05' => ['debit' => 12300, 'credit' => 0, 'count' => 1]]);
});
