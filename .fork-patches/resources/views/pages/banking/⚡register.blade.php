<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\Banking\LastBankAccount;
use App\Support\Preferences\UserPreferences;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Bank register')] class extends Component {
    use WithPagination;

    /** Sortable columns => the direction a first click sorts in. */
    public const SORT_FIELDS = [
        'date' => 'desc',
        'entry' => 'desc',
        'memo' => 'asc',
        'payment' => 'desc',
        'deposit' => 'desc',
        'cleared' => 'asc',
    ];

    public const PER_PAGE = 50;

    public Company $company;

    #[Url(as: 'sort')]
    public string $sortField = 'date';

    #[Url(as: 'dir')]
    public string $sortDir = 'desc';

    public ?int $account_id = null;

    public bool $showCleared = true;

    public function mount(Company $company): void
    {
        $this->company = $company;

        if (request('account')) {
            $this->account_id = (int) request('account');
        } else {
            // Reopen on the account this operator last worked in anywhere in
            // Banking; fall back to the lowest-numbered active account.
            $first = Account::query()
                ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
                ->where('is_active', true)
                ->orderBy('code')
                ->first();
            $this->account_id = LastBankAccount::recall($company, $this->bankAccounts) ?? $first?->id;
        }

        LastBankAccount::remember($company, $this->account_id);

        $this->showCleared = (bool) UserPreferences::get($company, 'banking.register.show_cleared', true);
    }

    public function updatedAccountId(): void
    {
        LastBankAccount::remember($this->company, $this->account_id);
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! array_key_exists($field, self::SORT_FIELDS)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = self::SORT_FIELDS[$field];
        }

        $this->resetPage();
    }

    public function updatedShowCleared(): void
    {
        UserPreferences::put($this->company, 'banking.register.show_cleared', $this->showCleared);
        $this->resetPage();
    }

    protected function linesQuery()
    {
        return JournalLine::query()
            ->where('account_id', $this->account_id)
            ->where('is_posted', true);
    }

    #[Computed]
    public function lines()
    {
        return $this->linesQuery()
            ->with(['journalEntry'])
            // Hiding cleared rows leaves what is still outstanding, and a
            // voided cheque and its reversal never are: neither reaches the bank.
            ->when(! $this->showCleared, fn ($q) => $q->whereNull('cleared_at')->withoutUnsettledVoids())
            ->tap(fn ($q) => $this->applySort($q))
            ->paginate(self::PER_PAGE);
    }

    /**
     * Every sort ends on a unique tiebreaker so rows sharing a date or amount
     * keep a stable order from one page load (and page) to the next.
     */
    protected function applySort($query): void
    {
        $field = array_key_exists($this->sortField, self::SORT_FIELDS) ? $this->sortField : 'date';
        $dir = $this->sortDir === 'asc' ? 'asc' : 'desc';

        match ($field) {
            'entry' => $query->orderBy(
                JournalEntry::query()->select('entry_no')->whereColumn('journal_entries.id', 'journal_lines.journal_entry_id'),
                $dir,
            ),
            // A line without its own memo shows (and so sorts by) its entry's memo.
            'memo' => $query->orderByRaw(
                "COALESCE(journal_lines.memo, (SELECT je.memo FROM journal_entries je WHERE je.id = journal_lines.journal_entry_id)) {$dir}"
            ),
            'payment' => $query->orderBy('journal_lines.credit_cents', $dir),
            'deposit' => $query->orderBy('journal_lines.debit_cents', $dir),
            // asc = outstanding rows first, then cleared.
            'cleared' => $query->orderByRaw("CASE WHEN journal_lines.cleared_at IS NULL THEN 0 ELSE 1 END {$dir}"),
            default => $query->orderBy('journal_lines.entry_date', $dir),
        };

        $tieDir = $field === 'date' || $field === 'entry' ? $dir : 'desc';

        if ($field !== 'date') {
            $query->orderBy('journal_lines.entry_date', $tieDir);
        }

        $query->orderBy('journal_lines.id', $tieDir);
    }

    /**
     * The account's balance after each line on the current page, always taken
     * in date order over every posted line — so it stays true whatever the
     * sort, page, or Show cleared setting.
     *
     * @return array<int, int> line id => running balance in cents
     */
    #[Computed]
    public function runningBalances(): array
    {
        $ids = $this->lines->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $running = $this->linesQuery()
            ->select('journal_lines.id')
            ->selectRaw('SUM(journal_lines.debit_cents - journal_lines.credit_cents) OVER (ORDER BY journal_lines.entry_date, journal_lines.id) AS balance_cents');

        return DB::query()
            ->fromSub($running, 'rb')
            ->whereIn('rb.id', $ids)
            ->pluck('rb.balance_cents', 'rb.id')
            ->map(fn ($cents) => (int) $cents)
            ->all();
    }

    /**
     * Where each row on the page came from (fork): a bank feed or an imported
     * statement (the row was created from one of their lines), or entered by hand.
     * A hand-entered row that was later matched to a feed/imported line stays
     * "Manual" but says what it was matched to.
     *
     * @return array<int, array{origin: string, matched: ?string}> line id => origin
     */
    #[Computed]
    public function lineSources(): array
    {
        $entryIds = $this->lines->pluck('journal_entry_id')->unique()->values()->all();
        $lineIds = $this->lines->pluck('id')->all();

        if ($lineIds === []) {
            return [];
        }

        $statementLines = BankStatementLine::query()
            ->with('import:id,bank_feed_account_id')
            ->where(function ($q) use ($entryIds, $lineIds) {
                $q->whereIn('created_journal_entry_id', $entryIds)
                    ->orWhereIn('matched_journal_line_id', $lineIds);
            })
            ->get(['id', 'bank_statement_import_id', 'created_journal_entry_id', 'matched_journal_line_id']);

        $createdBy = [];
        $matchedTo = [];

        foreach ($statementLines as $sl) {
            $kind = $sl->import?->bank_feed_account_id !== null ? 'feed' : 'import';

            if ($sl->created_journal_entry_id !== null) {
                $createdBy[(int) $sl->created_journal_entry_id] = $kind;
            }

            if ($sl->matched_journal_line_id !== null) {
                $matchedTo[(int) $sl->matched_journal_line_id] = $kind;
            }
        }

        $sources = [];

        foreach ($this->lines as $line) {
            $created = $createdBy[(int) $line->journal_entry_id] ?? null;

            $sources[(int) $line->id] = $created !== null
                ? ['origin' => $created, 'matched' => null]
                : ['origin' => 'manual', 'matched' => $matchedTo[(int) $line->id] ?? null];
        }

        return $sources;
    }

    /**
     * Ids of the rows that are one half of a void nobody has cleared, so the
     * register can mark them void rather than as waiting to clear.
     *
     * @return array<int, true>
     */
    #[Computed]
    public function voidLineIds(): array
    {
        return $this->linesQuery()
            ->unsettledVoids()
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    #[Computed]
    public function bankAccounts()
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'subtype']);
    }

    public function clearedBalanceCents(): int
    {
        return (int) $this->linesQuery()
            ->whereNotNull('cleared_at')
            ->selectRaw('COALESCE(SUM(debit_cents - credit_cents), 0) AS bal')
            ->value('bal');
    }

    public function ledgerBalanceCents(): int
    {
        return (int) $this->linesQuery()
            ->selectRaw('COALESCE(SUM(debit_cents - credit_cents), 0) AS bal')
            ->value('bal');
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Bank register') }}</flux:heading>
            <flux:subheading>{{ __(':label. Cleared rows are ticked off during reconciliation.', ['label' => $company->jurisdiction->chequeLabel('checkbook')]) }}</flux:subheading>
        </div>

        <div class="flex items-end gap-3">
            <flux:select wire:model.live="account_id" :label="__('Account')" class="min-w-[260px]">
                @foreach ($this->bankAccounts as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->displayName() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:dropdown align="end">
                <flux:button variant="primary" icon:trailing="chevron-down" data-test="bank-register-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="clipboard-document-check" :href="route('banking.review', ['company' => $company->slug])" wire:navigate data-test="for-review-menu-item">
                        {{ __('For Review') }}
                    </flux:menu.item>
                    <flux:menu.item icon="check-circle" :href="route('banking.reconcile', ['company' => $company->slug, 'account' => $account_id])" wire:navigate data-test="reconcile-menu-item">
                        {{ __('Reconcile') }}
                    </flux:menu.item>
                    <flux:menu.item icon="arrow-up-tray" :href="route('banking.import', ['company' => $company->slug])" wire:navigate data-test="import-statement-menu-item">
                        {{ __('Import statement') }}
                    </flux:menu.item>
                    <flux:menu.item icon="funnel" :href="route('banking.rules', ['company' => $company->slug])" wire:navigate data-test="bank-rules-menu-item">
                        {{ __('Bank rules') }}
                    </flux:menu.item>
                    <flux:menu.item icon="link" :href="route('banking.feeds', ['company' => $company->slug])" wire:navigate data-test="bank-feeds-menu-item">
                        {{ __('Bank feeds') }}
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Ledger balance') }}</flux:text>
            <div class="text-lg font-mono">{{ number_format($this->ledgerBalanceCents() / 100, 2) }}</div>
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Cleared balance') }}</flux:text>
            <div class="text-lg font-mono" data-test="cleared-balance">{{ number_format($this->clearedBalanceCents() / 100, 2) }}</div>
        </div>
    </div>

    <div class="mb-4 flex items-center gap-4">
        <flux:switch wire:model.live="showCleared" :label="__('Show cleared')" />
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-3 py-2 w-24"><x-sort-header field="cleared" :current-field="$sortField" :current-dir="$sortDir" :label="__('Cleared')" /></th>
                    <th class="px-3 py-2 text-left"><x-sort-header field="date" :current-field="$sortField" :current-dir="$sortDir" :label="__('Date')" /></th>
                    <th class="px-3 py-2 text-left"><x-sort-header field="entry" :current-field="$sortField" :current-dir="$sortDir" :label="__('Entry #')" /></th>
                    <th class="px-3 py-2 text-left"><x-sort-header field="memo" :current-field="$sortField" :current-dir="$sortDir" :label="__('Memo')" /></th>
                    <th class="px-3 py-2 text-left">{{ __('Source') }}</th>
                    <th class="px-3 py-2 text-right"><x-sort-header field="payment" :current-field="$sortField" :current-dir="$sortDir" :label="__('Payment')" align="right" /></th>
                    <th class="px-3 py-2 text-right"><x-sort-header field="deposit" :current-field="$sortField" :current-dir="$sortDir" :label="__('Deposit')" align="right" /></th>
                    <th class="px-3 py-2 text-right">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->lines as $line)
                    @php $isVoid = isset($this->voidLineIds[$line->id]); @endphp
                    <tr data-test="register-row" class="@if ($line->cleared_at) bg-green-50 dark:bg-green-900/10 @elseif ($isVoid) text-muted-foreground @endif">
                        <td class="px-3 py-2 text-center">
                            @if ($line->cleared_at)
                                <flux:icon name="check" variant="micro" class="size-4 text-green-600" title="{{ __('Cleared') }}" data-test="register-cleared-mark" />
                            @elseif ($isVoid)
                                <flux:icon name="no-symbol" variant="micro" class="size-4 text-muted-foreground" title="{{ __('Voided — this and its reversal cancel out and never reach the bank') }}" data-test="register-void-mark" />
                            @else
                                <span class="inline-block size-4 rounded border border-border" title="{{ __('Not cleared') }}"></span>
                            @endif
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $line->journalEntry->entry_date->toDateString() }}</td>
                        <td class="px-3 py-2 font-mono">
                            <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $line->journalEntry->id]) }}" wire:navigate class="underline">{{ $line->journalEntry->entry_no }}</a>
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">{{ $line->memo ?? $line->journalEntry->memo }}</td>
                        @php $source = $this->lineSources[$line->id] ?? ['origin' => 'manual', 'matched' => null]; @endphp
                        <td class="px-3 py-2 whitespace-nowrap" data-test="register-source" data-source="{{ $source['origin'] }}">
                            @if ($source['origin'] === 'feed')
                                <flux:badge size="sm" color="sky">{{ __('Bank feed') }}</flux:badge>
                            @elseif ($source['origin'] === 'import')
                                <flux:badge size="sm" color="zinc">{{ __('Statement import') }}</flux:badge>
                            @else
                                <span class="text-muted-foreground">{{ __('Manual') }}</span>
                                @if ($source['matched'] !== null)
                                    <span class="block text-xs text-muted-foreground" data-test="register-source-matched">{{ $source['matched'] === 'feed' ? __('matched to bank feed') : __('matched to statement') }}</span>
                                @endif
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right font-mono">{{ $line->credit_cents > 0 ? number_format($line->credit_cents / 100, 2) : '' }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $line->debit_cents > 0 ? number_format($line->debit_cents / 100, 2) : '' }}</td>
                        <td class="px-3 py-2 text-right font-mono" data-test="register-balance">{{ number_format(($this->runningBalances[$line->id] ?? 0) / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-muted-foreground">{{ __('No transactions on this account.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->lines->links() }}</div>
</section>
