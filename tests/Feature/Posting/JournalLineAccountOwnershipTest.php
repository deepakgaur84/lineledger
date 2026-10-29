<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\OpeningBalances\OpeningBalanceJournalSynchronizer;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use Illuminate\Support\Facades\File;

/*
 * journal_lines carry no company_id: a line belongs to an organization only
 * through its entry. So the ledger itself refuses a line on another
 * organization's account — when an entry is posted, when a document reposts
 * its entry in place, and when a journal entry's lines are saved — whatever
 * form, API call or job built it.
 */

beforeEach(function () {
    app()->forgetInstance('current_company');
    $this->other = Company::factory()->create();
    $this->otherIncome = Account::withoutGlobalScopes()
        ->where('company_id', $this->other->id)
        ->where('type', AccountType::Income->value)
        ->orderBy('code')
        ->firstOrFail();

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('refuses to post an entry with a line on another organization\'s account', function () {
    $entry = JournalEntry::create(['entry_no' => 'JE-X1', 'entry_date' => '2026-06-15']);
    $entry->lines()->create(['account_id' => $this->bank->id, 'debit_cents' => 50_000, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $this->otherIncome->id, 'debit_cents' => 0, 'credit_cents' => 50_000, 'line_order' => 1]);

    expect(fn () => app(JournalPoster::class)->post($entry->fresh()))
        ->toThrow(PostingValidationException::class, 'A line uses an account that belongs to another organization.');

    expect($entry->fresh()->isPosted())->toBeFalse()
        ->and(JournalLine::query()->where('account_id', $this->otherIncome->id)->where('is_posted', true)->count())->toBe(0)
        ->and((int) $this->otherIncome->fresh()->balance_cents)->toBe(0);
});

it('refuses to save journal lines on another organization\'s account', function () {
    $save = fn (?JournalEntry $entry = null) => app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-06-15',
        'lines' => [
            ['account_id' => $this->bank->id, 'debit_cents' => 50_000, 'credit_cents' => 0],
            ['account_id' => $this->otherIncome->id, 'debit_cents' => 0, 'credit_cents' => 50_000],
        ],
    ], $entry);

    expect(fn () => $save())->toThrow(PostingValidationException::class);
    expect(JournalEntry::query()->count())->toBe(0);

    // Rewriting a posted entry in place is refused the same way, and keeps its lines.
    $posted = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-06-15',
        'lines' => [
            ['account_id' => $this->bank->id, 'debit_cents' => 50_000, 'credit_cents' => 0],
            ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 50_000],
        ],
    ]);
    app(JournalPoster::class)->post($posted);

    expect(fn () => $save($posted->fresh()))->toThrow(PostingValidationException::class);
    expect($posted->fresh()->lines->pluck('account_id')->all())->toBe([$this->bank->id, $this->income->id])
        ->and((int) $this->otherIncome->fresh()->balance_cents)->toBe(0);
});

it('refuses to repost a document whose line was moved to another organization\'s account', function () {
    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $invoice = Invoice::create(['contact_id' => $customer->id, 'invoice_no' => 'INV-X1', 'invoice_date' => '2026-06-01', 'due_date' => '2026-06-30']);
    $line = $invoice->lines()->create([
        'account_id' => $this->income->id, 'description' => 'Service', 'quantity' => '1',
        'unit_price_cents' => 40_000, 'line_subtotal_cents' => 40_000, 'line_tax_cents' => 0,
        'line_total_cents' => 40_000, 'line_order' => 0,
    ]);
    $entry = app(InvoicePoster::class)->post($invoice);
    $postedAccounts = $entry->fresh()->lines->pluck('account_id')->sort()->values()->all();

    // An edit that reached the document without going through a form.
    $line->forceFill(['account_id' => $this->otherIncome->id])->save();

    expect(fn () => app(InvoicePoster::class)->repost($invoice->fresh()))->toThrow(PostingValidationException::class);

    expect($entry->fresh()->lines->pluck('account_id')->sort()->values()->all())->toBe($postedAccounts)
        ->and(JournalLine::query()->where('account_id', $this->otherIncome->id)->count())->toBe(0)
        ->and((int) $this->otherIncome->fresh()->balance_cents)->toBe(0);
});

it('checks the accounts of every entry rebuilt in place', function () {
    // repost() rewrites a posted entry's lines without coming back through
    // JournalPoster::post(), so each one has to run the check itself.
    $rebuilders = collect(File::files(app_path('Services/Posting')))
        ->map(fn (SplFileInfo $file): string => 'App\\Services\\Posting\\'.$file->getBasename('.php'))
        ->filter(fn (string $class): bool => method_exists($class, 'repost'))
        ->map(fn (string $class): array => [$class, 'repost'])
        ->push([OpeningBalanceJournalSynchronizer::class, 'repostEntry'])
        ->values();

    expect($rebuilders)->toHaveCount(10);

    foreach ($rebuilders as [$class, $method]) {
        $reflection = new ReflectionMethod($class, $method);
        $body = implode('', array_slice(
            file((string) $reflection->getFileName()),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        expect(str_contains($body, '->ensureLineAccountsInCompany('))
            ->toBeTrue("{$class}::{$method}() rebuilds journal lines without checking their accounts' organization");
    }
});
