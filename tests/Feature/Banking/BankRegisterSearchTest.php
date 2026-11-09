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

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $this->cheque = function (string $no, string $date, int $cents, string $payee): void {
        $cheque = Cheque::create([
            'bank_account_id' => $this->bank->id,
            'cheque_no' => $no,
            'cheque_date' => $date,
            'payee_name' => $payee,
            'memo' => $payee.' invoice',
        ]);
        $cheque->lines()->create(['account_id' => $this->expense->id, 'description' => $payee.' work', 'amount_cents' => $cents, 'line_order' => 0]);
        app(ChequePoster::class)->post($cheque);
    };

    ($this->cheque)('3001', '2026-03-05', 12345, 'Alpha Plumbing');
    ($this->cheque)('3002', '2026-04-10', 67890, 'Bravo Electrical');
    ($this->cheque)('3003', '2026-05-15', 22222, 'Charlie Timber');

    $this->ids = fn () => Livewire::actingAs($this->user)
        ->test('pages::banking.register', ['company' => $this->company])
        ->set('account_id', $this->bank->id);

    $this->bankLineCents = fn ($component) => collect($component->instance()->lines->items())
        ->map(fn (JournalLine $l) => max((int) $l->credit_cents, (int) $l->debit_cents))->sort()->values()->all();
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('finds rows by description', function () {
    $c = ($this->ids)()->set('search', 'bravo');

    expect(($this->bankLineCents)($c))->toBe([67890]);
});

it('finds rows by amount, however it is typed', function () {
    expect(($this->bankLineCents)(($this->ids)()->set('search', '123.45')))->toBe([12345])
        ->and(($this->bankLineCents)(($this->ids)()->set('search', '678.90')))->toBe([67890]);
});

it('finds rows by entry number', function () {
    $entryNo = JournalLine::query()->where('account_id', $this->bank->id)->where('credit_cents', 22222)->first()->journalEntry->entry_no;

    expect(($this->bankLineCents)(($this->ids)()->set('search', $entryNo)))->toContain(22222);
});

it('limits rows to a date range, either end optional', function () {
    expect(($this->bankLineCents)(($this->ids)()->set('dateFrom', '2026-04-01')->set('dateTo', '2026-04-30')))->toBe([67890])
        ->and(($this->bankLineCents)(($this->ids)()->set('dateFrom', '2026-04-11')))->toBe([22222])
        ->and(($this->bankLineCents)(($this->ids)()->set('dateTo', '2026-03-31')))->toBe([12345]);
});

it('combines text and dates, and clears back to every row', function () {
    $c = ($this->ids)()->set('search', 'invoice')->set('dateFrom', '2026-05-01');
    expect(($this->bankLineCents)($c))->toBe([22222]);

    $c->call('clearFilters');
    expect(($this->bankLineCents)($c))->toBe([12345, 22222, 67890])
        ->and($c->get('search'))->toBe('');
});

it('does not change the balances when filtering', function () {
    $all = ($this->ids)();
    $filtered = ($this->ids)()->set('search', 'bravo');

    expect($filtered->instance()->ledgerBalanceCents())->toBe($all->instance()->ledgerBalanceCents());
});

it('ignores an invalid date instead of failing', function () {
    expect(($this->bankLineCents)(($this->ids)()->set('dateFrom', 'not-a-date')))->toBe([12345, 22222, 67890]);
});
