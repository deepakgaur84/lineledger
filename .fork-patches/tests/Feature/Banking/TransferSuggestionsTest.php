<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\StatementLineMatchStatus;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Banking\TransferPairMatcher;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->card = Account::query()->where('subtype', AccountSubtype::CreditCard->value)->orderBy('code')->firstOrFail();
    $this->importBank = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
    $this->importCard = BankStatementImport::factory()->create(['account_id' => $this->card->id]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function xferLine(Account $account, BankStatementImport $import, int $cents, string $date): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $account->id,
        'txn_date' => $date,
        'amount_cents' => $cents,
        'description' => 'XFER '.abs($cents),
        'match_status' => StatementLineMatchStatus::Unmatched->value,
        'created_journal_entry_id' => null,
    ]);
}

it('pairs each inflow once, closest-dated first, and ignores same-account and out-of-window lines', function () {
    $out1 = xferLine($this->bank, $this->importBank, -50000, '2026-06-10');
    $out2 = xferLine($this->bank, $this->importBank, -50000, '2026-06-12');
    $near = xferLine($this->card, $this->importCard, 50000, '2026-06-12');   // 0 days from out2, 2 from out1
    $far = xferLine($this->card, $this->importCard, 50000, '2026-06-13');    // 1 day from out2, 3 from out1
    xferLine($this->bank, $this->importBank, 50000, '2026-06-10');           // same account as the outflows
    xferLine($this->card, $this->importCard, 50000, '2026-06-30');           // outside the window

    $pairs = app(TransferPairMatcher::class)->candidates($this->company);

    // Oldest outflow first: out1 takes the closest free inflow ($near, 2 days), out2 then takes $far (1 day).
    expect($pairs)->toHaveCount(2)
        ->and($pairs[0]['out']->id)->toBe($out1->id)->and($pairs[0]['in']->id)->toBe($near->id)->and($pairs[0]['days'])->toBe(2)
        ->and($pairs[1]['out']->id)->toBe($out2->id)->and($pairs[1]['in']->id)->toBe($far->id)->and($pairs[1]['days'])->toBe(1)
        ->and($pairs[0]['out']->account?->id)->toBe($this->bank->id)
        ->and($pairs[0]['in']->account?->id)->toBe($this->card->id);
});

it('does not pair across currencies', function () {
    $this->card->forceFill(['currency_code' => 'USD'])->save();
    xferLine($this->bank, $this->importBank, -1000, '2026-06-10');
    xferLine($this->card, $this->importCard, 1000, '2026-06-10');

    expect(app(TransferPairMatcher::class)->candidates($this->company))->toHaveCount(0);
});

it('records the ticked suggested transfers in one go', function () {
    $pairs = [];
    foreach ([10000, 20000, 30000] as $i => $cents) {
        $pairs[] = [
            xferLine($this->bank, $this->importBank, -$cents, '2026-06-1'.$i),
            xferLine($this->card, $this->importCard, $cents, '2026-06-1'.$i),
        ];
    }

    $page = Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set('selectedTransfers', [$pairs[0][0]->id.':'.$pairs[0][1]->id, $pairs[2][0]->id.':'.$pairs[2][1]->id])
        ->call('recordSelectedTransfers')
        ->assertHasNoErrors()
        ->assertSet('selectedTransfers', []);

    expect(Transfer::count())->toBe(2)
        ->and($pairs[0][0]->fresh()->created_journal_entry_id)->not->toBeNull()
        ->and($pairs[1][0]->fresh()->created_journal_entry_id)->toBeNull()
        ->and($pairs[2][1]->fresh()->created_journal_entry_id)->not->toBeNull()
        ->and($page->instance()->transferCandidates)->toHaveCount(1);
});

it('records every suggestion with Record all', function () {
    foreach ([10000, 20000, 30000] as $i => $cents) {
        xferLine($this->bank, $this->importBank, -$cents, '2026-06-1'.$i);
        xferLine($this->card, $this->importCard, $cents, '2026-06-1'.$i);
    }

    $page = Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('recordAllTransfers')
        ->assertHasNoErrors();

    expect(Transfer::count())->toBe(3)
        ->and($page->instance()->transferCandidates)->toHaveCount(0)
        ->and(BankStatementLine::query()->forReview()->count())->toBe(0);
});

it('carries on past a pair that can no longer be recorded', function () {
    $good = [xferLine($this->bank, $this->importBank, -10000, '2026-06-10'), xferLine($this->card, $this->importCard, 10000, '2026-06-10')];
    $stale = [xferLine($this->bank, $this->importBank, -20000, '2026-06-11'), xferLine($this->card, $this->importCard, 20000, '2026-06-11')];

    $page = Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set('selectedTransfers', [$stale[0]->id.':'.$stale[1]->id, $good[0]->id.':'.$good[1]->id]);

    // One leg gets categorized elsewhere before the button is pressed.
    $stale[0]->forceFill(['match_status' => StatementLineMatchStatus::Ignored->value])->save();

    $page->call('recordSelectedTransfers')->assertHasNoErrors();

    expect(Transfer::count())->toBe(1)
        ->and($good[0]->fresh()->created_journal_entry_id)->not->toBeNull()
        ->and($stale[1]->fresh()->created_journal_entry_id)->toBeNull();
});

it('asks for a tick when nothing is selected', function () {
    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('recordSelectedTransfers')
        ->assertHasNoErrors();

    expect(Transfer::count())->toBe(0);
});
