<?php

use App\Actions\Banking\BulkCategorizeStatementLines;
use App\Actions\Banking\BulkSetStatementLineStatus;
use App\Actions\Banking\CreateBankRuleFromLine;
use App\Actions\Banking\PairStatementTransfer;
use App\Actions\Banking\RecordStatementLine;
use App\Actions\Banking\SplitStatementLine;
use App\Actions\Contacts\SaveContact;
use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use App\Exceptions\Posting\PostingValidationException;
use App\Livewire\Concerns\ManagesStatementLineContacts;
use App\Livewire\Concerns\ManagesStatementLineTax;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\CustomerReceipt;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\Invoice;
use App\Services\Banking\Import\BankRuleEngine;
use App\Services\Banking\Import\OpenBillMatcher;
use App\Services\Banking\Import\OpenInvoiceMatcher;
use App\Services\Banking\TransferPairMatcher;
use App\Services\Classification\CategorySuggester;
use App\Services\EditLocks\EditLockManager;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('For Review')] class extends Component {
    use ManagesStatementLineContacts, ManagesStatementLineTax;

    public Company $company;

    public ?int $accountFilter = null;

    public bool $showExcluded = false;

    /** Fork: free-text filter over description and amount. */
    public string $search = '';

    /**
     * Fork: how many rows to render at once. Every row carries several pickers
     * that re-render on each interaction, so a few hundred feed lines made the
     * page crawl. The oldest rows come first and leave the queue as they are
     * confirmed, so the next ones slide up.
     */
    public int $limit = 50;

    /** @var array<int, int|string> selected line ids for bulk actions */
    public array $selected = [];

    public ?int $bulkCategory = null;

    public ?int $bulkContactId = null;

    /** @var array<int, int|null> lineId => chosen category account id */
    public array $categories = [];

    /** @var array<int, int|string> lineId => bill to pay ('' = record as expense) */
    public array $lineBill = [];

    /**
     * Fork: lineId => invoice to pay ('' = record as a plain journal entry,
     * 'allocations' = the set held in $lineInvoiceAllocations). Held on the page,
     * not on the line: it only matters until the line is accepted.
     *
     * @var array<int, int|string>
     */
    public array $lineInvoice = [];

    /** @var array<int, list<array{invoice_id: int, amount_cents: int}>> lineId => invoices a deposit pays */
    public array $lineInvoiceAllocations = [];

    // Split modal.
    public ?int $splittingLineId = null;

    public int $splitTargetCents = 0;

    /** @var array<int, array{account_id: ?int, amount: string, tax_code_ids: array<int, int|string>}> */
    public array $splits = [];

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * @return Collection<int, BankStatementLine>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->baseQuery()
            ->with(['account:id,code,name', 'suggestedAccount:id,code,name', 'suggestedBill:id,bill_no'])
            ->orderBy('txn_date')
            ->orderBy('id')
            ->limit($this->limit)
            ->get();
    }

    /**
     * Every line waiting in this view, including the ones not rendered yet.
     */
    #[Computed]
    public function totalRows(): int
    {
        return $this->baseQuery()->count();
    }

    /**
     * The merchant name the bank feed attached to a line, when it did.
     */
    public function merchantName(BankStatementLine $line): ?string
    {
        $raw = $line->raw;
        $name = is_array($raw) && is_array($raw['merchant'] ?? null) ? ($raw['merchant']['name'] ?? null) : null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * One click: use the feed's merchant name as the payee — an existing vendor
     * of that name, else a new one.
     */
    public function addMerchantVendor(int $lineId): void
    {
        $line = $this->lineForReview($lineId);
        $name = $line !== null ? $this->merchantName($line) : null;

        if ($line === null || $name === null) {
            return;
        }

        $contact = Contact::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($name)])
            ->first()
            ?? app(SaveContact::class)->handle(['display_name' => $name], 'is_vendor');

        $this->dispatch('contacts-changed');
        $this->selectLineContact($lineId, (int) $contact->id);
    }

    public function showMore(): void
    {
        $this->limit += 50;
        unset($this->rows);
    }

    public function showAllRows(): void
    {
        $this->limit = max(50, $this->totalRows);
        unset($this->rows);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<BankStatementLine>
     */
    private function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $base = $this->showExcluded
            ? BankStatementLine::query()
                ->where('match_status', StatementLineMatchStatus::Ignored->value)
                ->whereNull('created_journal_entry_id')
            : BankStatementLine::query()->forReview();

        $term = trim(str_replace(['%', '_'], '', $this->search));

        return $base
            ->when($this->accountFilter, fn ($q) => $q->where('account_id', $this->accountFilter))
            ->when($term !== '', function ($q) use ($term): void {
                $q->where(function ($w) use ($term): void {
                    $w->whereRaw('LOWER(description) LIKE ?', ['%'.mb_strtolower($term).'%']);

                    $number = preg_replace('/[^0-9.]/', '', $term);

                    if ($number !== '' && is_numeric($number)) {
                        $w->orWhereRaw('ABS(amount_cents) = ?', [(int) round(((float) $number) * 100)]);
                    }
                });
            });
    }

    /**
     * Open-bill offers for every vendor-tagged outflow in the feed, keyed by line id.
     *
     * @return array<int, Collection<int, \App\Models\Bill>>
     */
    #[Computed]
    public function billCandidates(): array
    {
        if ($this->showExcluded) {
            return [];
        }

        $overrides = [];
        foreach ($this->rows as $line) {
            $overrides[$line->id] = $this->lineContactId($line);
        }

        return app(OpenBillMatcher::class)->forLines($this->rows, $overrides);
    }

    /**
     * Fork: open-invoice offers for every customer-tagged deposit in the feed,
     * keyed by line id — the receivable twin of {@see billCandidates()}.
     *
     * @return array<int, Collection<int, Invoice>>
     */
    #[Computed]
    public function invoiceCandidates(): array
    {
        if ($this->showExcluded) {
            return [];
        }

        $contacts = [];
        foreach ($this->rows as $line) {
            $contacts[$line->id] = $line->isInflow() ? $this->lineContactId($line) : null;
        }

        return app(OpenInvoiceMatcher::class)->forLines($this->rows, $contacts);
    }

    /**
     * Open invoices per customer shown on the page, one grouped query — gates
     * the "Receive payment…" button.
     *
     * @return array<int, int> contact id => open invoice count
     */
    #[Computed]
    public function openInvoiceCounts(): array
    {
        $contactIds = $this->rows
            ->map(fn (BankStatementLine $l) => $l->isInflow() ? $this->lineContactId($l) : null)
            ->filter()
            ->unique()
            ->values();

        if ($contactIds->isEmpty()) {
            return [];
        }

        return Invoice::query()
            ->openWithBalance()
            ->whereIn('contact_id', $contactIds)
            ->selectRaw('contact_id, COUNT(*) AS n')
            ->groupBy('contact_id')
            ->pluck('n', 'contact_id')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    public function updatedLineInvoice(mixed $value, int|string|null $key = null): void
    {
        if ($key === null) {
            return;
        }

        $lineId = (int) $key;
        $choice = $this->lineInvoice[$lineId] ?? '';

        if ($choice === '' || $choice === null) {
            unset($this->lineInvoiceAllocations[$lineId]);
        } elseif ($choice !== 'allocations') {
            $invoice = Invoice::query()->whereKey((int) $choice)->first();

            if ($invoice === null) {
                unset($this->lineInvoiceAllocations[$lineId]);
                $this->lineInvoice[$lineId] = '';

                return;
            }

            $this->lineInvoiceAllocations[$lineId] = [[
                'invoice_id' => (int) $invoice->id,
                'amount_cents' => $invoice->balanceCents(),
            ]];
        }

        unset($this->rows);
    }

    // ---- "Receive payment…" picker: apply one deposit across several open invoices ----

    public ?int $receiveLineId = null;

    public int $receiveTargetCents = 0;

    /** @var array<int, array{invoice_id: int, invoice_no: string, due_date: string, balance: int, apply: string}> */
    public array $receiveRows = [];

    private function noReceivableInvoicesReason(BankStatementLine $line, Contact $contact): string
    {
        $open = Invoice::query()->where('contact_id', $contact->id)->openWithBalance()->get();

        if ($open->isEmpty()) {
            return __('No open invoices for :name. Only posted invoices with a balance can be paid here — a draft invoice has to be posted first.', ['name' => $contact->display_name]);
        }

        $account = $line->account()->first();
        $home = mb_strtoupper((string) ($this->company->currency_code ?? ''));
        $accountCode = mb_strtoupper((string) ($account?->currency_code ?: $home));
        $otherCurrency = $open
            ->map(fn (Invoice $i): string => mb_strtoupper((string) ($i->getAttribute('currency_code') ?: $home)))
            ->first(fn (string $code): bool => $code !== $accountCode);

        return __(':name has an open invoice in :invoice, but this transaction is in :account. An invoice can only be paid into an account in its own currency.', [
            'name' => $contact->display_name,
            'invoice' => $otherCurrency ?? $accountCode,
            'account' => $accountCode,
        ]);
    }

    public function openReceivePayment(int $lineId): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $contactId = $this->lineContactId($line);
        $contact = $contactId !== null ? Contact::query()->find($contactId) : null;

        if ($contact === null) {
            Flux::toast(variant: 'danger', text: __('Choose the customer first.'));

            return;
        }

        $invoices = app(OpenInvoiceMatcher::class)->openInvoicesFor($line, $contact);

        if ($invoices->isEmpty()) {
            Flux::toast(variant: 'danger', text: $this->noReceivableInvoicesReason($line, $contact));

            return;
        }

        $target = abs((int) $line->amount_cents);

        // Pre-fill: the held allocation, else the oldest invoices first up to
        // the deposit total (the last one partial).
        $existing = collect($this->lineInvoiceAllocations[$lineId] ?? [])->pluck('amount_cents', 'invoice_id');
        $remaining = $target;

        $this->receiveRows = [];
        foreach ($invoices as $invoice) {
            $balance = $invoice->balanceCents();

            if ($existing->isNotEmpty()) {
                $apply = (int) ($existing[$invoice->id] ?? 0);
            } else {
                $apply = min($balance, $remaining);
                $remaining -= $apply;
            }

            $this->receiveRows[] = [
                'invoice_id' => (int) $invoice->id,
                'invoice_no' => (string) ($invoice->invoice_no ?: '#'.$invoice->id),
                'due_date' => $invoice->due_date ? \Carbon\CarbonImmutable::parse($invoice->due_date)->toDateString() : '',
                'balance' => $balance,
                'apply' => $apply > 0 ? number_format($apply / 100, 2, '.', '') : '',
            ];
        }

        $this->receiveLineId = $lineId;
        $this->receiveTargetCents = $target;
        $this->resetErrorBag('receiveRows');

        Flux::modal('receive-payment')->show();
    }

    public function receiveTotal(): int
    {
        return (int) collect($this->receiveRows)->sum(fn (array $row): int => Money::tryFromString(trim((string) $row['apply']))?->cents ?? 0);
    }

    public function saveReceivePayment(): void
    {
        $line = $this->receiveLineId ? $this->lineForReview($this->receiveLineId) : null;

        if ($line === null) {
            $this->cancelReceivePayment();

            return;
        }

        $allocations = [];

        foreach ($this->receiveRows as $row) {
            $cents = Money::tryFromString(trim((string) $row['apply']))?->cents ?? 0;

            if ($cents <= 0) {
                continue;
            }

            if ($cents > (int) $row['balance']) {
                $this->addError('receiveRows', __('Invoice :no cannot be paid more than its open balance.', ['no' => $row['invoice_no']]));

                return;
            }

            $allocations[] = ['invoice_id' => (int) $row['invoice_id'], 'amount_cents' => $cents];
        }

        if (array_sum(array_column($allocations, 'amount_cents')) !== $this->receiveTargetCents) {
            $this->addError('receiveRows', __('The amounts applied must add up to the transaction total.'));

            return;
        }

        $this->lineInvoiceAllocations[$line->id] = $allocations;
        $this->lineInvoice[$line->id] = 'allocations';

        $this->cancelReceivePayment();
        unset($this->rows, $this->invoiceCandidates);

        Flux::toast(variant: 'success', text: __('Invoices chosen — accept the line to record the payment.'));
    }

    public function cancelReceivePayment(): void
    {
        $this->receiveLineId = null;
        $this->receiveTargetCents = 0;
        $this->receiveRows = [];
        Flux::modal('receive-payment')->close();
    }

    /**
     * "Pay :n invoices (:amount)" for a held allocation, or null.
     */
    public function invoiceAllocationLabel(BankStatementLine $line): ?string
    {
        $allocations = $this->lineInvoiceAllocations[$line->id] ?? [];

        if (count($allocations) < 2 && ($this->lineInvoice[$line->id] ?? '') !== 'allocations') {
            return null;
        }

        if ($allocations === []) {
            return null;
        }

        return __('Pay :n invoices (:amount)', [
            'n' => count($allocations),
            'amount' => number_format(array_sum(array_column($allocations, 'amount_cents')) / 100, 2),
        ]);
    }

    /**
     * Lines an active bank rule already covers, keyed by line id.
     *
     * @return array<int, \App\Models\BankRule>
     */
    #[Computed]
    public function ruleCoveredLines(): array
    {
        if ($this->rows->isEmpty()) {
            return [];
        }

        return app(BankRuleEngine::class)->firstMatchingForMany(
            (int) $this->company->id,
            $this->rows->mapWithKeys(fn (BankStatementLine $l) => [$l->id => $l->description])->all(),
        );
    }

    public function updatingLineBill(mixed $value, int|string|null $key = null): void
    {
        if ($key === null) {
            $this->rememberChangedKeys('lineBill', $value);
        }
    }

    public function updatedLineBill(mixed $value, int|string|null $key = null): void
    {
        foreach ($this->updatedKeys('lineBill', $key) as $lineId) {
            $this->applyLineBill((int) $lineId, $this->lineBill[$lineId] ?? null);
        }
    }

    private function applyLineBill(int $lineId, mixed $value): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $line->forceFill(match (true) {
            $value === '' || $value === null => ['suggested_bill_id' => null, 'suggested_bill_allocations' => null],
            $value === 'allocations' => ['suggested_bill_id' => null],
            default => ['suggested_bill_id' => (int) $value, 'suggested_bill_allocations' => null],
        })->save();
        unset($this->rows);
    }

    // ---- "Pay bills…" picker: apply one outflow across several open bills ----

    public ?int $payBillsLineId = null;

    public int $payBillsTargetCents = 0;

    /** @var array<int, array{bill_id: int, bill_no: string, type: string, due_date: string, balance: int, apply: string}> */
    public array $payBillsRows = [];

    /**
     * Open bills (both kinds) per payee shown on the page, one grouped query —
     * gates the "Pay bills…" button.
     *
     * @return array<int, int> contact id => open bill count
     */
    #[Computed]
    public function openBillCounts(): array
    {
        $contactIds = $this->rows
            ->map(fn (BankStatementLine $l) => $l->isOutflow() ? $this->lineContactId($l) : null)
            ->filter()
            ->unique()
            ->values();

        if ($contactIds->isEmpty()) {
            return [];
        }

        return Bill::query()
            ->openWithBalance()
            ->whereIn('contact_id', $contactIds)
            ->selectRaw('contact_id, COUNT(*) AS n')
            ->groupBy('contact_id')
            ->pluck('n', 'contact_id')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * Why "Pay bills" found nothing, so the person is told the cause rather than
     * "no open bills" when the payee does have some: those can be in a different
     * currency from the bank account, or of a kind this payee is not paid for.
     */
    private function noPayableBillsReason(BankStatementLine $line, Contact $contact): string
    {
        $open = Bill::query()
            ->where('contact_id', $contact->id)
            ->openWithBalance()
            ->get();

        if ($open->isEmpty()) {
            return __('No open bills for :name. Only posted bills with a balance can be paid here — a draft bill has to be posted first.', ['name' => $contact->display_name]);
        }

        $account = $line->account()->first();
        $home = mb_strtoupper((string) ($this->company->currency_code ?? ''));
        $accountCode = mb_strtoupper((string) ($account?->currency_code ?: $home));
        $otherCurrency = $open
            ->map(fn (Bill $b): string => mb_strtoupper((string) ($b->getAttribute('currency_code') ?: $home)))
            ->first(fn (string $code): bool => $code !== $accountCode);

        if ($otherCurrency !== null) {
            return __(':name has an open bill in :bill, but this transaction is in :account. A bill can only be paid from an account in its own currency.', [
                'name' => $contact->display_name,
                'bill' => $otherCurrency,
                'account' => $accountCode,
            ]);
        }

        return __(':name has open bills, but none of a kind this payee can be paid for here (check the contact is marked as a vendor).', ['name' => $contact->display_name]);
    }

    public function openPayBills(int $lineId): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $contactId = $this->lineContactId($line);
        $contact = $contactId !== null ? Contact::query()->find($contactId) : null;

        if ($contact === null) {
            Flux::toast(variant: 'danger', text: __('Choose the payee first.'));

            return;
        }

        $bills = app(OpenBillMatcher::class)->openBillsFor($line, $contact);

        if ($bills->isEmpty()) {
            Flux::toast(variant: 'danger', text: $this->noPayableBillsReason($line, $contact));

            return;
        }

        $target = abs((int) $line->amount_cents);

        // Pre-fill: the saved allocation, else the offered single bill, else the
        // oldest bills first up to the transaction total (last one partial).
        $existing = collect($line->suggestedBillAllocations())->pluck('amount_cents', 'bill_id');
        if ($existing->isEmpty() && $line->suggested_bill_id !== null) {
            $existing = collect([(int) $line->suggested_bill_id => $target]);
        }
        $remaining = $target;

        $this->payBillsRows = [];
        foreach ($bills as $bill) {
            $balance = $bill->balanceCents();

            if ($existing->isNotEmpty()) {
                $apply = (int) ($existing[$bill->id] ?? 0);
            } else {
                $apply = min($balance, $remaining);
                $remaining -= $apply;
            }

            $type = $bill->getAttribute('bill_type');

            $this->payBillsRows[] = [
                'bill_id' => (int) $bill->id,
                'bill_no' => (string) ($bill->bill_no ?: '#'.$bill->id),
                'type' => $type instanceof BillType ? $type->value : BillType::Vendor->value,
                'due_date' => $bill->due_date ? \Carbon\CarbonImmutable::parse($bill->due_date)->toDateString() : '',
                'balance' => $balance,
                'apply' => $apply > 0 ? number_format($apply / 100, 2, '.', '') : '',
            ];
        }

        $this->payBillsLineId = $lineId;
        $this->payBillsTargetCents = $target;
        $this->resetErrorBag('payBillsRows');

        Flux::modal('pay-bills')->show();
    }

    public function payBillsTotal(): int
    {
        return (int) collect($this->payBillsRows)->sum(fn (array $row): int => Money::tryFromString(trim((string) $row['apply']))?->cents ?? 0);
    }

    public function savePayBills(): void
    {
        $line = $this->payBillsLineId ? $this->lineForReview($this->payBillsLineId) : null;

        if ($line === null) {
            $this->cancelPayBills();

            return;
        }

        $allocations = [];

        foreach ($this->payBillsRows as $row) {
            $cents = Money::tryFromString(trim((string) $row['apply']))?->cents ?? 0;

            if ($cents <= 0) {
                continue;
            }

            if ($cents > (int) $row['balance']) {
                $this->addError('payBillsRows', __('Bill :no cannot be paid more than its open balance.', ['no' => $row['bill_no']]));

                return;
            }

            $allocations[] = ['bill_id' => (int) $row['bill_id'], 'amount_cents' => $cents];
        }

        if (array_sum(array_column($allocations, 'amount_cents')) !== $this->payBillsTargetCents) {
            $this->addError('payBillsRows', __('The amounts applied must add up to the transaction total.'));

            return;
        }

        // Always stored as a set: a partial payment of even one bill is a set.
        $line->forceFill(['suggested_bill_id' => null, 'suggested_bill_allocations' => $allocations])->save();
        $this->lineBill[$line->id] = 'allocations';

        $this->cancelPayBills();
        unset($this->rows, $this->billCandidates);

        Flux::toast(variant: 'success', text: __('Bills chosen — confirm the line to record the payment.'));
    }

    public function cancelPayBills(): void
    {
        $this->payBillsLineId = null;
        $this->payBillsTargetCents = 0;
        $this->payBillsRows = [];
        Flux::modal('pay-bills')->close();
    }

    /**
     * "Pay :n bills (:amount)" for a stored allocation, or null.
     */
    public function allocationLabel(BankStatementLine $line): ?string
    {
        $allocations = $line->suggestedBillAllocations();

        if ($allocations === []) {
            return null;
        }

        return __('Pay :n bills (:amount)', [
            'n' => count($allocations),
            'amount' => number_format(array_sum(array_column($allocations, 'amount_cents')) / 100, 2),
        ]);
    }

    protected function statementLineForContact(int $lineId): ?BankStatementLine
    {
        return $this->lineForReview($lineId);
    }

    protected function statementLineForTax(int $lineId): ?BankStatementLine
    {
        return $this->lineForReview($lineId);
    }

    protected function afterLineTaxChanged(BankStatementLine $line): void
    {
        unset($this->rows);
    }

    /**
     * @return Collection<int, int>
     */
    protected function suggestedContactIdsOnPage(): Collection
    {
        return $this->rows->pluck('suggested_contact_id')->filter()->map(fn ($id): int => (int) $id)->values();
    }

    /**
     * Picking a payee pre-fills an empty category from their default (or most
     * used) account and looks for an open bill of this amount.
     */
    protected function afterLineContactChanged(BankStatementLine $line, ?Contact $contact): void
    {
        $lineId = $line->id;

        if ($contact === null) {
            $this->lineBill[$lineId] = '';
            $this->lineInvoice[$lineId] = '';
            unset($this->lineInvoiceAllocations[$lineId], $this->lineHint[$lineId], $this->rows, $this->billCandidates, $this->invoiceCandidates);

            return;
        }

        if ($line->isInflow()) {
            $invoiceMatcher = app(OpenInvoiceMatcher::class);
            $freshInflow = $line->fresh() ?? $line;
            $invoiceOffer = $invoiceMatcher->forLine($freshInflow, (int) $contact->id);
            $invoiceSet = $invoiceOffer === null ? $invoiceMatcher->allocationFor($freshInflow, (int) $contact->id) : null;

            if ($invoiceOffer !== null) {
                $this->lineInvoice[$lineId] = $invoiceOffer->id;
                $this->lineInvoiceAllocations[$lineId] = [['invoice_id' => (int) $invoiceOffer->id, 'amount_cents' => $invoiceOffer->balanceCents()]];
            } elseif ($invoiceSet !== null) {
                $this->lineInvoice[$lineId] = 'allocations';
                $this->lineInvoiceAllocations[$lineId] = $invoiceSet;
            } else {
                $this->lineInvoice[$lineId] = '';
                unset($this->lineInvoiceAllocations[$lineId]);
            }

            unset($this->invoiceCandidates, $this->openInvoiceCounts);
        }

        $category = (int) ($this->categories[$lineId] ?? $line->suggested_account_id ?? 0);
        $suggester = app(CategorySuggester::class);
        $suggestion = null;
        $accountPrefilled = false;

        if ($category === 0) {
            $suggestion = $suggester->fromContact((int) $line->company_id, (int) $contact->id);

            if ($suggestion !== null) {
                $this->categories[$lineId] = $suggestion->accountId;
                $line->forceFill([
                    'suggested_account_id' => $suggestion->accountId,
                    'suggestion_source' => $line->suggestion_source?->value ?? StatementSuggestionSource::ContactDefault->value,
                    'match_reason' => $suggestion->reason,
                ])->save();
                $this->lineHint[$lineId] = __("Pre-filled from :name's default account.", ['name' => $contact->display_name]);
                $accountPrefilled = true;
            }
        } elseif ($line->isOutflow() && $contact->default_expense_account_id === null) {
            $this->lineHint[$lineId] = __("Will use as :name's default account.", ['name' => $contact->display_name]);
        }

        if ($line->isOutflow() && array_filter($this->lineTaxIds($line)) === []) {
            $taxCodeId = $suggestion?->taxCodeId ?? $suggester->defaultTaxCodeFor((int) $line->company_id, (int) $contact->id);

            if ($taxCodeId !== null) {
                $this->lineTax[$lineId] = [$taxCodeId];
                $line->forceFill(['suggested_tax_code_id' => $taxCodeId, 'suggested_secondary_tax_code_id' => $suggestion?->secondaryTaxCodeId])->save();
                $this->lineHint[$lineId] = match (true) {
                    $accountPrefilled => __("Pre-filled from :name's default account and tax.", ['name' => $contact->display_name]),
                    isset($this->lineHint[$lineId]) => $this->lineHint[$lineId].' '.__('Tax pre-filled from their default.'),
                    default => __("Tax pre-filled from :name's default.", ['name' => $contact->display_name]),
                };
            }
        }

        $matcher = app(OpenBillMatcher::class);
        $fresh = $line->fresh() ?? $line;
        $offer = $matcher->forLine($fresh, (int) $contact->id);
        $allocation = $offer === null ? $matcher->allocationFor($fresh, (int) $contact->id) : null;

        $this->lineBill[$lineId] = $offer?->id ?? ($allocation !== null ? 'allocations' : '');
        $line->forceFill(['suggested_bill_id' => $offer?->id, 'suggested_bill_allocations' => $allocation])->save();

        unset($this->rows, $this->billCandidates, $this->openBillCounts);
    }

    /**
     * Fork: feed lines arrive in an already-committed import, so a new rule never
     * reached them. Run the active rules over every waiting line that has no
     * category yet and carry the rule's category / vendor onto it.
     */
    private function applyRulesToWaitingLines(): int
    {
        $waiting = BankStatementLine::query()
            ->forReview()
            ->where('match_status', StatementLineMatchStatus::Unmatched->value)
            ->whereNull('suggested_account_id')
            ->get();

        if ($waiting->isEmpty()) {
            return 0;
        }

        $matches = app(BankRuleEngine::class)->firstMatchingForMany(
            (int) $this->company->id,
            $waiting->mapWithKeys(fn (BankStatementLine $l) => [$l->id => $l->description])->all(),
        );

        $applied = 0;

        foreach ($waiting as $other) {
            $rule = $matches[$other->id] ?? null;

            if ($rule === null || ! empty($this->categories[$other->id])) {
                continue;
            }

            $other->forceFill([
                'suggested_account_id' => $rule->action_account_id,
                'suggested_contact_id' => $rule->action_contact_id ?? $other->suggested_contact_id,
                'suggestion_source' => StatementSuggestionSource::Rule->value,
                'match_reason' => __('Categorized by rule ":name".', ['name' => $rule->name]),
            ])->save();

            // A row the person has not picked a payee on follows the new suggestion.
            if (isset($this->lineContact[$other->id]) && $this->lineContact[$other->id]['id'] === null && ! $this->lineContact[$other->id]['creating']) {
                unset($this->lineContact[$other->id]);
            }

            $applied++;
        }

        return $applied;
    }

    /**
     * "Always do this": write a bank rule for this payee with the row's
     * category (and vendor).
     */
    public function createRule(int $lineId): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $this->createPendingLineContact($lineId);

        $accountId = (int) ($this->categories[$lineId] ?? 0) ?: (int) $line->suggested_account_id;

        if ($accountId === 0) {
            Flux::toast(variant: 'danger', text: __('Choose a category first.'));

            return;
        }

        try {
            $rule = app(CreateBankRuleFromLine::class)->handle($line, $accountId, $this->lineContactId($line));
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first() ?? $e->getMessage());

            return;
        }

        // Re-running for the same payee updates the existing rule; make an open
        // bank-rule edit dialog notice rather than save over it.
        if (! $rule->wasRecentlyCreated) {
            app(EditLockManager::class)->touch($rule);
        }

        $applied = $this->applyRulesToWaitingLines();

        unset($this->rows, $this->ruleCoveredLines, $this->billCandidates, $this->contactNames);

        Flux::toast(variant: 'success', text: $applied > 0
            ? __('Rule ":name" created and applied to :count other transactions — edit it under Bank rules.', ['name' => $rule->name, 'count' => $applied])
            : __('Rule ":name" created — edit it under Bank rules.', ['name' => $rule->name]));
    }

    /**
     * Suggested inter-account transfer pairs hiding in the feed.
     *
     * @return Collection<int, array{out: BankStatementLine, in: BankStatementLine, days: int}>
     */
    #[Computed]
    public function transferCandidates(): Collection
    {
        if ($this->showExcluded) {
            return collect();
        }

        return app(TransferPairMatcher::class)->candidates($this->company);
    }

    public function recordTransfer(int $outId, int $inId, PairStatementTransfer $action): void
    {
        $out = $this->lineForReview($outId);
        $in = $this->lineForReview($inId);

        if ($out === null || $in === null) {
            return;
        }

        try {
            $action->handle($out, $in);
            unset($this->rows, $this->transferCandidates);
            Flux::toast(variant: 'success', text: __('Transfer recorded.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    /** @var array<int, string> "outId:inId" of the suggested transfers ticked for a bulk record */
    public array $selectedTransfers = [];

    /**
     * Fork: how many suggested transfers to draw. Every drawn row is a handful of
     * components the server renders again on each click anywhere on the page, so a
     * few hundred suggestions made every request slow. "Select all" and "Record
     * all" still cover every suggestion, drawn or not.
     */
    public int $transferLimit = 10;

    public function showMoreTransfers(): void
    {
        $this->transferLimit += 25;
    }

    /**
     * Fork: record several suggested transfers — the ticked ones, or every
     * suggestion — a few per request. Doing them all in one request ran past the
     * web server's time limit and ended in a 500 once there were a few hundred, so
     * the pairs are queued and the page asks for the next batch (the
     * `transfers-continue` event) until the queue is empty. Each pair posts on its
     * own, so one that fails (a locked period, a leg already categorized) does not
     * stop the rest; the totals are reported once, at the end.
     */
    private const TRANSFER_BATCH = 8;

    private const TRANSFER_BATCH_SECONDS = 12;

    /** @var list<string> "outId:inId" still to record */
    #[Locked]
    public array $transferQueue = [];

    #[Locked]
    public int $transferDone = 0;

    #[Locked]
    public int $transferFailed = 0;

    #[Locked]
    public ?string $transferError = null;

    public function recordSelectedTransfers(PairStatementTransfer $action): void
    {
        $this->startTransferQueue($this->selectedTransfers, $action);
    }

    public function recordAllTransfers(PairStatementTransfer $action): void
    {
        $this->startTransferQueue($this->transferCandidates
            ->map(fn (array $pair): string => $pair['out']->id.':'.$pair['in']->id)
            ->all(), $action);
    }

    /**
     * @param  array<int, string>  $pairs  "outId:inId"
     */
    private function startTransferQueue(array $pairs, PairStatementTransfer $action): void
    {
        $queue = [];

        foreach ($pairs as $pair) {
            [$outId, $inId] = array_pad(array_map('intval', explode(':', (string) $pair, 2)), 2, 0);

            if ($outId > 0 && $inId > 0) {
                $queue[] = $outId.':'.$inId;
            }
        }

        if ($queue === []) {
            Flux::toast(variant: 'danger', text: __('Tick the transfers to record first.'));

            return;
        }

        $this->transferQueue = $queue;
        $this->transferDone = 0;
        $this->transferFailed = 0;
        $this->transferError = null;

        $this->processTransferQueue($action);
    }

    /**
     * Record the next batch of queued transfers; asks the page for another batch
     * while any are left, and reports the totals when the queue is empty.
     */
    public function processTransferQueue(PairStatementTransfer $action): void
    {
        if ($this->transferQueue === []) {
            return;
        }

        $batch = array_slice($this->transferQueue, 0, self::TRANSFER_BATCH);
        $ids = [];

        foreach ($batch as $pair) {
            [$outId, $inId] = array_map('intval', explode(':', $pair, 2));
            $ids[] = $outId;
            $ids[] = $inId;
        }

        $lines = BankStatementLine::query()->forReview()->whereIn('id', $ids)->get()->keyBy('id');
        $started = microtime(true);
        $handled = 0;

        foreach ($batch as $pair) {
            if ($handled > 0 && microtime(true) - $started > self::TRANSFER_BATCH_SECONDS) {
                break;
            }

            [$outId, $inId] = array_map('intval', explode(':', $pair, 2));
            $out = $lines->get($outId);
            $in = $lines->get($inId);
            $handled++;

            if ($out === null || $in === null) {
                $this->transferFailed++;
                $this->transferError ??= __('One of these transactions has already been categorized.');

                continue;
            }

            try {
                $action->handle($out, $in);
                $this->transferDone++;
            } catch (\Throwable $e) {
                $this->transferFailed++;
                $this->transferError ??= $e->getMessage();
            }
        }

        $this->transferQueue = array_slice($this->transferQueue, $handled);
        unset($this->rows, $this->transferCandidates);

        if ($this->transferQueue !== []) {
            $this->dispatch('transfers-continue');

            return;
        }

        $this->selectedTransfers = [];

        if ($this->transferDone > 0) {
            Flux::toast(variant: 'success', text: $this->transferFailed > 0
                ? __(':done transfers recorded, :failed could not be: :error', ['done' => $this->transferDone, 'failed' => $this->transferFailed, 'error' => $this->transferError])
                : trans_choice(':count transfer recorded.|:count transfers recorded.', $this->transferDone, ['count' => $this->transferDone]));
        } else {
            Flux::toast(variant: 'danger', text: (string) $this->transferError);
        }
    }

    public function updatedShowExcluded(): void
    {
        $this->selected = [];
        $this->limit = 50;
    }

    public function updatedSearch(): void
    {
        $this->selected = [];
        $this->limit = 50;
    }

    public function updatedAccountFilter(): void
    {
        $this->selected = [];
        $this->limit = 50;
    }

    public function selectAllVisible(): void
    {
        $this->selected = $this->rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function bulkCategorize(BulkCategorizeStatementLines $action): void
    {
        if (! $this->bulkCategory || $this->selected === []) {
            return;
        }

        try {
            $count = $action->handle($this->selected, (int) $this->bulkCategory, $this->bulkContactId ?: null);
            $this->selected = [];
            $this->bulkCategory = null;
            $this->bulkContactId = null;
            unset($this->rows, $this->billCandidates, $this->contactNames);
            Flux::toast(variant: 'success', text: __(':count added to your books.', ['count' => $count]));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function bulkExclude(BulkSetStatementLineStatus $action): void
    {
        $action->exclude($this->selected);
        $this->selected = [];
        unset($this->rows);
    }

    public function bulkInclude(BulkSetStatementLineStatus $action): void
    {
        $action->include($this->selected);
        $this->selected = [];
        unset($this->rows);
    }

    public function includeLine(int $lineId, BulkSetStatementLineStatus $action): void
    {
        $action->include([$lineId]);
        unset($this->rows);
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function bankAccounts(): Collection
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * The code and name ride alongside the label for the typeable account
     * picker (<x-account-combo>), which ranks GL-number matches first.
     *
     * @return array<int, array{value: int, label: string, code: string, name: string}>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return Account::query()
            // Fork: a feed can bring in capital or take out drawings, so Equity is
            // offered too (the control accounts stay out).
            ->where(fn ($q) => $q->selectableForItemAccount()->orWhere('type', \App\Enums\AccountType::Equity->value))
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->displayName()}", 'code' => (string) $a->code, 'name' => $a->displayName()])
            ->all();
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contactOptions(): Collection
    {
        return Contact::query()->where('is_active', true)->orderBy('display_name')->get(['id', 'display_name']);
    }

    public function accept(int $lineId, RecordStatementLine $action): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $this->createPendingLineContact($lineId);

        $accountId = $this->categories[$lineId] ?? $line->suggested_account_id;
        $billRaw = $this->lineBill[$lineId] ?? ($line->suggested_bill_id ?? ($line->suggestedBillAllocations() !== [] ? 'allocations' : null));
        $allocations = $billRaw === 'allocations' ? $line->suggestedBillAllocations() : [];
        $billId = ($billRaw === '' || $billRaw === null || $billRaw === 'allocations') ? null : (int) $billRaw;

        $invoiceAllocations = ($this->lineInvoice[$lineId] ?? '') !== '' ? ($this->lineInvoiceAllocations[$lineId] ?? []) : [];

        if (! $accountId && $billId === null && $allocations === [] && $invoiceAllocations === []) {
            Flux::toast(variant: 'danger', text: __('Choose a category first.'));

            return;
        }

        [$taxCodeId, $secondaryTaxCodeId] = $this->lineTaxIds($line);

        try {
            $entry = $action->handle(
                $line,
                $accountId ? (int) $accountId : null,
                $this->lineContactId($line),
                $billId,
                taxCodeId: $taxCodeId,
                secondaryTaxCodeId: $secondaryTaxCodeId,
                billAllocations: $allocations !== [] ? $allocations : null,
                invoiceAllocations: $invoiceAllocations !== [] ? $invoiceAllocations : null,
            );

            unset($this->rows, $this->billCandidates, $this->invoiceCandidates, $this->contactNames, $this->openBillCounts, $this->openInvoiceCounts, $this->lineHint[$lineId], $this->lineInvoice[$lineId], $this->lineInvoiceAllocations[$lineId]);
            Flux::toast(variant: 'success', text: match ($entry->source_type) {
                Expense::class => __('Recorded as an expense.'),
                BillPayment::class => __('Bill payment recorded.'),
                CustomerReceipt::class => __('Invoice payment recorded.'),
                default => __('Added to your books.'),
            });
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function exclude(int $lineId): void
    {
        BankStatementLine::query()
            ->forReview()
            ->whereKey($lineId)
            ->update(['match_status' => StatementLineMatchStatus::Ignored->value]);

        unset($this->rows);
    }

    public function openSplit(int $lineId): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $this->splittingLineId = $lineId;
        $this->splitTargetCents = abs((int) $line->amount_cents);
        $this->splits = [['kind' => 'category', 'account_id' => null, 'invoice_id' => null, 'amount' => number_format($this->splitTargetCents / 100, 2, '.', ''), 'tax_code_ids' => []]];

        Flux::modal('split-line')->show();
    }

    public function addSplit(): void
    {
        $this->splits[] = ['kind' => 'category', 'account_id' => null, 'invoice_id' => null, 'amount' => '', 'tax_code_ids' => []];
    }

    /**
     * Fork: open invoices a part of an inflow split can pay — the row's customer
     * when one is chosen, otherwise every customer's, in the bank account's
     * currency, oldest due first.
     *
     * @return array<int, array{id: int, label: string, balance_cents: int}>
     */
    #[Computed]
    public function splitInvoiceOptions(): array
    {
        $line = $this->splittingLineId ? $this->lineForReview($this->splittingLineId) : null;

        if ($line === null || ! $line->isInflow()) {
            return [];
        }

        $contactId = $this->lineContactId($line);
        $account = $line->account()->first();
        $home = mb_strtoupper((string) $account?->company?->currency_code);
        $bankCode = mb_strtoupper((string) ($account?->currency_code ?: $home));

        $query = Invoice::query()
            ->where('company_id', $line->company_id)
            ->openWithBalance()
            ->with('contact:id,display_name')
            ->orderBy('due_date')
            ->orderBy('id');

        if ($contactId) {
            $query->where('contact_id', $contactId);
        }

        return $query->limit(300)->get()
            ->filter(fn (Invoice $i): bool => mb_strtoupper((string) ($i->getAttribute('currency_code') ?: $home)) === $bankCode)
            ->map(fn (Invoice $i): array => [
                'id' => (int) $i->id,
                'label' => ($i->invoice_no ?: '#'.$i->id).' · '.($i->contact?->display_name ?? '').' · '.number_format($i->balanceCents() / 100, 2),
                'balance_cents' => $i->balanceCents(),
            ])
            ->values()
            ->all();
    }

    /** Picking an invoice for a part pre-fills its amount with what's left of the line (up to the invoice's balance). */
    public function updatedSplits(mixed $value = null, ?string $key = null): void
    {
        if ($key === null || ! str_ends_with($key, '.invoice_id') || $value === null || $value === '') {
            return;
        }

        $i = (int) explode('.', $key)[0];

        if (trim((string) ($this->splits[$i]['amount'] ?? '')) !== '') {
            return;
        }

        $option = collect($this->splitInvoiceOptions)->firstWhere('id', (int) $value);
        $others = collect($this->splits)->except($i)->sum(fn ($s) => Money::tryFromString((string) ($s['amount'] ?? ''))?->cents ?? 0);
        $left = max(0, $this->splitTargetCents - $others);

        if ($option !== null) {
            $this->splits[$i]['amount'] = number_format(min($left, $option['balance_cents']) / 100, 2, '.', '');
        }
    }

    /** The payee the split will be recorded against — the row's chosen contact. */
    public function splitPayeeName(): ?string
    {
        $line = $this->splittingLineId ? $this->lineForReview($this->splittingLineId) : null;
        $contactId = $line ? $this->lineContactId($line) : null;

        return $contactId ? ($this->contactNames[$contactId] ?? null) : null;
    }

    public function removeSplit(int $index): void
    {
        unset($this->splits[$index]);
        $this->splits = array_values($this->splits);
    }

    public function saveSplit(SplitStatementLine $action): void
    {
        $line = $this->splittingLineId ? $this->lineForReview($this->splittingLineId) : null;

        if ($line === null) {
            $this->cancelSplit();

            return;
        }

        $this->createPendingLineContact($line->id);

        $payload = collect($this->splits)
            ->filter(fn (array $s): bool => (($s['kind'] ?? 'category') === 'invoice' ? ! empty($s['invoice_id']) : ! empty($s['account_id'])) && trim((string) $s['amount']) !== '')
            ->map(function (array $s) use ($line): ?array {
                // Fork: a half-typed or unparseable amount is a message, not a 500.
                $cents = Money::tryFromString((string) $s['amount'])?->cents;

                if ($cents === null) {
                    return null;
                }

                $taxIds = array_values(array_unique(array_filter(array_map('intval', (array) ($s['tax_code_ids'] ?? [])))));

                if (($s['kind'] ?? 'category') === 'invoice' && $line->isInflow()) {
                    return ['invoice_id' => (int) $s['invoice_id'], 'amount_cents' => $cents];
                }

                return [
                    'account_id' => (int) $s['account_id'],
                    'amount_cents' => $cents,
                    'tax_code_id' => $line->isOutflow() ? ($taxIds[0] ?? null) : null,
                    'secondary_tax_code_id' => $line->isOutflow() ? ($taxIds[1] ?? null) : null,
                ];
            })
            ->values();

        if ($payload->contains(null)) {
            Flux::toast(variant: 'danger', text: __('One of the amounts is not a valid number — use digits with at most two decimals, e.g. 1,150.00.'));

            return;
        }

        $payload = $payload->all();

        try {
            $action->handle($line, $payload, $this->lineContactId($line));
            $this->cancelSplit();
            unset($this->rows);
            Flux::toast(variant: 'success', text: __('Split added to your books.'));
        } catch (PostingValidationException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function cancelSplit(): void
    {
        $this->splittingLineId = null;
        $this->splits = [];
        $this->splitTargetCents = 0;
        Flux::modal('split-line')->close();
    }

    private function lineForReview(int $lineId): ?BankStatementLine
    {
        return BankStatementLine::query()->forReview()->whereKey($lineId)->first();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('For Review') }}</flux:heading>
            <flux:subheading>{{ __('Imported bank transactions waiting to be categorized, across every account.') }}</flux:subheading>
        </div>
        <div class="flex items-end gap-3">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" clearable :label="__('Search')" :placeholder="__('Description or amount…')" class="max-w-[260px]" data-test="review-search" />
            <flux:select wire:model.live="accountFilter" :label="__('Account')" class="max-w-[240px]" data-test="review-account-filter">
                <flux:select.option value="">{{ __('All accounts') }}</flux:select.option>
                @foreach ($this->bankAccounts as $acct)
                    <flux:select.option :value="$acct->id">{{ $acct->code }} — {{ $acct->displayName() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:switch wire:model.live="showExcluded" :label="__('Excluded')" data-test="review-show-excluded" />
        </div>
    </div>

    {{-- Suggested transfers --}}
    @if ($this->transferCandidates->isNotEmpty())
        <div class="mb-4 overflow-hidden rounded-lg border border-border" data-test="transfer-suggestions" x-data x-on:transfers-continue.window="$wire.processTransferQueue()">
            @php
                $transferKeys = $this->transferCandidates->map(fn (array $p): string => $p['out']->id.':'.$p['in']->id)->all();
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-muted px-4 py-2 text-sm font-medium">
                <span>{{ __('Suggested transfers') }} <span class="font-normal text-muted-foreground">({{ count($transferKeys) }})</span></span>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:button size="xs" variant="ghost" x-data x-on:click="$wire.selectedTransfers = $wire.selectedTransfers.length ? [] : {{ \Illuminate\Support\Js::from($transferKeys) }}" data-test="transfer-select-all">{{ __('Select all') }} / {{ __('none') }}</flux:button>
                    <flux:button size="xs" variant="primary" wire:click="recordSelectedTransfers" wire:loading.attr="disabled" wire:target="recordSelectedTransfers,recordAllTransfers,recordTransfer,processTransferQueue" :disabled="$transferQueue !== []" data-test="record-selected-transfers">{{ __('Record selected') }}</flux:button>
                    <flux:button size="xs" variant="outline" wire:click="recordAllTransfers" wire:confirm="{{ __('Record every suggested transfer?') }}" wire:loading.attr="disabled" wire:target="recordSelectedTransfers,recordAllTransfers,recordTransfer,processTransferQueue" :disabled="$transferQueue !== []" data-test="record-all-transfers">{{ __('Record all') }}</flux:button>
                    @if ($transferQueue !== [])
                        <span class="text-xs font-normal text-muted-foreground" data-test="transfer-progress">{{ __('Recording… :n left', ['n' => count($transferQueue)]) }}</span>
                    @else
                        <span wire:loading wire:target="recordTransfer" class="text-xs font-normal text-muted-foreground">{{ __('Recording…') }}</span>
                    @endif
                </div>
            </div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @foreach ($this->transferCandidates->take($transferLimit) as $pair)
                        <tr data-test="transfer-pair" wire:key="xfer-{{ $pair['out']->id }}-{{ $pair['in']->id }}">
                            <td class="w-8 px-4 py-2"><flux:checkbox wire:model="selectedTransfers" value="{{ $pair['out']->id }}:{{ $pair['in']->id }}" data-test="transfer-select" /></td>
                            <td class="px-4 py-2">
                                {{ $pair['out']->account?->displayName() }} → {{ $pair['in']->account?->displayName() }}
                                <span class="text-muted-foreground">· {{ $pair['out']->txn_date?->toDateString() }}</span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format(abs($pair['out']->amount_cents) / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right">
                                <flux:button size="xs" variant="primary" wire:click="recordTransfer({{ $pair['out']->id }}, {{ $pair['in']->id }})" wire:loading.attr="disabled" wire:target="recordTransfer({{ $pair['out']->id }}, {{ $pair['in']->id }})" data-test="record-transfer">{{ __('Record transfer') }}</flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if (count($transferKeys) > $transferLimit)
                <div class="border-t border-border px-4 py-2 text-center">
                    <flux:button size="xs" variant="ghost" wire:click="showMoreTransfers" data-test="transfers-show-more">{{ __('Show :n more', ['n' => min(25, count($transferKeys) - $transferLimit)]) }} ({{ count($transferKeys) - $transferLimit }} {{ __('not shown') }})</flux:button>
                </div>
            @endif
        </div>
    @endif

    {{-- Bulk action bar --}}
    @if (count($selected) > 0)
        <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted px-4 py-2" data-test="review-bulk-bar">
            <span class="text-sm font-medium">{{ __(':n selected', ['n' => count($selected)]) }}</span>
            <flux:button size="xs" variant="ghost" wire:click="clearSelection">{{ __('Clear') }}</flux:button>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                @if ($showExcluded)
                    <flux:button size="sm" variant="primary" wire:click="bulkInclude" data-test="bulk-include">{{ __('Include') }}</flux:button>
                @else
                    <flux:select wire:model.live="bulkCategory" size="sm" class="min-w-[200px]" data-test="bulk-category">
                        <flux:select.option value="">{{ __('Categorize as…') }}</flux:select.option>
                        @foreach ($this->categoryOptions as $opt)
                            <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="bulkContactId" size="sm" class="min-w-[180px]" data-test="bulk-contact">
                        <flux:select.option value="">{{ __('No vendor') }}</flux:select.option>
                        @foreach ($this->contactOptions as $contact)
                            <flux:select.option :value="$contact->id">{{ $contact->display_name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" variant="primary" wire:click="bulkCategorize" :disabled="! $bulkCategory" data-test="bulk-categorize">{{ __('Categorize') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="bulkExclude" data-test="bulk-exclude">{{ __('Exclude') }}</flux:button>
                @endif
            </div>
        </div>
    @endif

    {{-- Fork: the wrapper scrolls sideways, which clips the Category / Customer pickers
         that drop below a row. Keep enough height that their lists show even when only
         one or two rows are waiting. --}}
    <div class="min-h-[26rem] overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-3 py-2 text-left"><flux:checkbox wire:click="selectAllVisible" :checked="count($selected) > 0 && count($selected) === $this->rows->count()" data-test="select-all" /></th>
                    <th class="px-3 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Category') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Contact') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $line)
                    @php
                        $suggested = $line->hasUnconfirmedSuggestion();
                        $contactId = $this->lineContactId($line);
                        $contactName = $contactId ? ($this->contactNames[$contactId] ?? null) : null;
                        $contactState = $this->lineContactState($line->id);
                        $candidates = $this->billCandidates[$line->id] ?? null;
                        $coveringRule = $this->ruleCoveredLines[$line->id] ?? null;
                        $categoryChosen = ($categories[$line->id] ?? null) ?: $line->suggested_account_id;
                        $categoryPlaceholder = $line->suggested_account_id && $line->suggestedAccount
                            ? $line->suggestedAccount->code.' — '.$line->suggestedAccount->displayName()
                            : __('— pick —');
                        $sourceIcon = match ($line->suggestion_source) {
                            \App\Enums\StatementSuggestionSource::Rule => 'bolt',
                            \App\Enums\StatementSuggestionSource::History => 'clock',
                            \App\Enums\StatementSuggestionSource::OpenBill => 'document-text',
                            \App\Enums\StatementSuggestionSource::ContactDefault => 'user',
                            \App\Enums\StatementSuggestionSource::Ai => 'sparkles',
                            default => null,
                        };
                    @endphp
                    <tr data-test="review-row" wire:key="line-{{ $line->id }}" data-state="{{ $suggested ? 'suggested' : 'unmatched' }}" wire:loading.class="opacity-50" wire:target="accept({{ $line->id }})">
                        <td class="px-3 py-2"><flux:checkbox wire:model.live="selected" value="{{ $line->id }}" data-test="review-select" /></td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $line->txn_date?->toDateString() }}</td>
                        <td class="px-3 py-2 text-muted-foreground">{{ $line->account?->code }}</td>
                        <td class="px-3 py-2">
                            {{ $line->description }}
                            @if (! $contactId && $line->isOutflow() && ! $showExcluded && ($merchant = $this->merchantName($line)))
                                <div class="mt-1">
                                    <flux:button size="xs" variant="ghost" icon="plus" wire:click="addMerchantVendor({{ $line->id }})" data-test="review-add-merchant">
                                        {{ __('Use “:name” as vendor', ['name' => $merchant]) }}
                                    </flux:button>
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right font-mono {{ $line->amount_cents < 0 ? 'text-red-600 dark:text-red-500' : 'text-green-600 dark:text-green-500' }}">
                            {{ number_format($line->amount_cents / 100, 2) }}
                        </td>
                        <td class="px-3 py-2 align-top">
                            <x-account-combo model="categories.{{ $line->id }}" options="categories" :live="false" size="sm" class="min-w-[180px]" :placeholder="$categoryPlaceholder" data-test="review-category" />
                            @if ($line->isOutflow() && ! $showExcluded)
                                @php($taxIds = array_values(array_filter($this->lineTaxIds($line))))
                                <flux:dropdown class="mt-1">
                                    <flux:button size="xs" variant="outline" icon:trailing="chevron-down" class="font-normal" data-test="review-tax">
                                        {{ $this->lineTaxLabel($line) ?: __('No tax') }}
                                    </flux:button>
                                    <flux:menu>
                                        <flux:menu.checkbox.group wire:model.live="lineTax.{{ $line->id }}">
                                            @foreach ($this->purchaseTaxCodes as $code)
                                                <flux:menu.checkbox value="{{ $code->id }}" :disabled="count($taxIds) === 2 && ! in_array($code->id, $taxIds)" keep-open>{{ $code->label() }}</flux:menu.checkbox>
                                            @endforeach
                                        </flux:menu.checkbox.group>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                            @if ($suggested)
                                <div class="mt-1 flex items-start gap-1 text-xs text-muted-foreground" data-test="review-reason">
                                    <flux:badge size="sm" color="violet" :icon="$sourceIcon">{{ __('Suggested') }}</flux:badge>
                                    @if ($line->match_reason)
                                        <span class="max-w-[16rem]">{{ $line->match_reason }}</span>
                                    @endif
                                </div>
                            @endif
                            @php($allocationLabel = $this->allocationLabel($line))
                            @if ((($candidates && $candidates->isNotEmpty()) || $allocationLabel) && ! $showExcluded)
                                <flux:select wire:model.live="lineBill.{{ $line->id }}" size="sm" class="mt-1 min-w-[180px]" data-test="review-record-as">
                                    <flux:select.option value="">{{ __('Record as expense') }}</flux:select.option>
                                    @if ($allocationLabel)
                                        <flux:select.option value="allocations">{{ $allocationLabel }}</flux:select.option>
                                    @endif
                                    @foreach ($candidates ?? [] as $bill)
                                        <flux:select.option :value="$bill->id">
                                            {{ __('Pay bill :no (:amount)', ['no' => $bill->bill_no ?: '#'.$bill->id, 'amount' => number_format($bill->balanceCents() / 100, 2)]) }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif
                            @php($invoiceOffers = $this->invoiceCandidates[$line->id] ?? null)
                            @php($invoiceSetLabel = $this->invoiceAllocationLabel($line))
                            @if ((($invoiceOffers && $invoiceOffers->isNotEmpty()) || $invoiceSetLabel) && ! $showExcluded)
                                <flux:select wire:model.live="lineInvoice.{{ $line->id }}" size="sm" class="mt-1 min-w-[180px]" data-test="review-receive-as">
                                    <flux:select.option value="">{{ __('Record as journal entry') }}</flux:select.option>
                                    @if ($invoiceSetLabel && ($invoiceOffers === null || $invoiceOffers->count() !== 1 || count($lineInvoiceAllocations[$line->id] ?? []) > 1))
                                        <flux:select.option value="allocations">{{ $invoiceSetLabel }}</flux:select.option>
                                    @endif
                                    @foreach ($invoiceOffers ?? [] as $invoice)
                                        <flux:select.option :value="$invoice->id">
                                            {{ __('Pay invoice :no (:amount)', ['no' => $invoice->invoice_no ?: '#'.$invoice->id, 'amount' => number_format($invoice->balanceCents() / 100, 2)]) }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif
                            @if (($lineHint[$line->id] ?? '') !== '')
                                <div class="mt-1 text-xs text-muted-foreground" data-test="review-hint">{{ $lineHint[$line->id] }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 align-top">
                            <x-statement-contact-combo
                                :line-id="$line->id"
                                :add-label="$line->isInflow() ? 'customer' : 'vendor'"
                                :options="$this->lineContactOptions($line->id)"
                                :selected-id="$contactId"
                                :selected-name="$contactName"
                                :query="$contactState['query']"
                                :creating="$contactState['creating']"
                                :new-name="$contactState['new_name']"
                                data-test="review-contact"
                            />
                        </td>
                        <td class="px-3 py-2 align-top">
                            <div class="flex justify-end gap-1">
                                @if ($showExcluded)
                                    <flux:button size="xs" variant="ghost" wire:click="includeLine({{ $line->id }})" data-test="row-include">{{ __('Include') }}</flux:button>
                                @else
                                    @if ($line->isOutflow() && $contactId && ($this->openBillCounts[$contactId] ?? 0) > 0)
                                        <flux:tooltip :content="__('Pay one or more open bills with this transaction')">
                                            <flux:button size="xs" variant="ghost" icon="document-text" wire:click="openPayBills({{ $line->id }})" data-test="review-pay-bills" />
                                        </flux:tooltip>
                                    @endif
                                    @if ($line->isInflow() && $contactId && ($this->openInvoiceCounts[$contactId] ?? 0) > 0)
                                        <flux:tooltip :content="__('Receive payment for one or more open invoices with this transaction')">
                                            <flux:button size="xs" variant="ghost" icon="document-currency-dollar" wire:click="openReceivePayment({{ $line->id }})" data-test="review-receive-payment" />
                                        </flux:tooltip>
                                    @endif
                                    @if ($categoryChosen)
                                        @if ($coveringRule)
                                            <flux:tooltip :content="__('Rule “:name” already covers this — edit it under Bank rules.', ['name' => $coveringRule->name])">
                                                <flux:button size="xs" variant="ghost" icon="bolt" disabled data-test="review-rule-exists" />
                                            </flux:tooltip>
                                        @else
                                            <flux:tooltip :content="__('Always do this — create a rule for this payee')">
                                                <flux:button size="xs" variant="ghost" icon="bolt" wire:click="createRule({{ $line->id }})" data-test="review-make-rule" />
                                            </flux:tooltip>
                                        @endif
                                    @endif
                                    <flux:button size="xs" variant="primary" wire:click="accept({{ $line->id }})" wire:loading.attr="disabled" wire:target="accept({{ $line->id }})" data-test="review-accept">{{ $suggested ? __('Confirm') : __('Accept') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="openSplit({{ $line->id }})" data-test="review-split">{{ __('Split') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="exclude({{ $line->id }})" data-test="review-exclude">{{ __('Exclude') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-muted-foreground">{{ $showExcluded ? __('No excluded transactions.') : __('Nothing to review — every imported transaction is categorized.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->totalRows > $this->rows->count())
        <div class="mt-3 flex flex-wrap items-center gap-3 text-sm" data-test="review-show-more">
            <flux:text>{{ __('Showing :shown of :total transactions, oldest first.', ['shown' => $this->rows->count(), 'total' => $this->totalRows]) }}</flux:text>
            <flux:button size="sm" wire:click="showMore">{{ __('Show 50 more') }}</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="showAllRows" wire:confirm="{{ __('Showing everything can make this page slow. Continue?') }}">{{ __('Show all') }}</flux:button>
        </div>
    @endif

    {{-- Accounts for the category pickers on each row and in the split modal. --}}
    <x-account-combo.options key="categories" :options="$this->categoryOptions" />

    {{-- Split modal --}}
    <flux:modal name="split-line" class="md:w-[760px]" wire:close="cancelSplit">
        @php($splitSum = collect($splits)->sum(fn ($s) => Money::tryFromString((string) $s['amount'])?->cents ?? 0))
        @php($splitLine = $splittingLineId ? $this->rows->firstWhere('id', $splittingLineId) : null)
        @php($splitIsOutflow = $splitLine !== null && $splitLine->isOutflow())
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Split transaction') }}</flux:heading>
            <flux:subheading>
                {{ __('Divide this transaction across categories. The parts must add up to the total.') }}
                @if ($splitIsOutflow)
                    <span class="block" data-test="split-payee">
                        @if ($this->splitPayeeName())
                            {{ __('Recorded as an expense to :name.', ['name' => $this->splitPayeeName()]) }}
                        @else
                            {{ __('No payee — pick a vendor on the row to record the expense to them.') }}
                        @endif
                    </span>
                @endif
            </flux:subheading>

            @foreach ($splits as $i => $split)
                @php($splitTaxIds = array_values(array_filter(array_map('intval', (array) ($split['tax_code_ids'] ?? [])))))
                <div class="grid grid-cols-12 gap-2" wire:key="split-{{ $i }}" data-test="split-row">
                    @if (! $splitIsOutflow)
                        <flux:select wire:model.live="splits.{{ $i }}.kind" class="col-span-2" data-test="split-kind">
                            <flux:select.option value="category">{{ __('Category') }}</flux:select.option>
                            <flux:select.option value="invoice">{{ __('Invoice') }}</flux:select.option>
                        </flux:select>
                    @endif
                    @if (! $splitIsOutflow && ($split['kind'] ?? 'category') === 'invoice')
                        <flux:select wire:model.live="splits.{{ $i }}.invoice_id" class="col-span-5" data-test="split-invoice">
                            <flux:select.option value="">{{ __('— open invoice —') }}</flux:select.option>
                            @foreach ($this->splitInvoiceOptions as $option)
                                <flux:select.option value="{{ $option['id'] }}">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <x-account-combo model="splits.{{ $i }}.account_id" options="categories" :live="false" class="{{ $splitIsOutflow ? 'col-span-5' : 'col-span-5' }}" :placeholder="__('— category —')" data-test="split-account" />
                    @endif
                    @if ($splitIsOutflow)
                        <flux:dropdown class="col-span-3">
                            <flux:button variant="outline" icon:trailing="chevron-down" class="w-full justify-between font-normal" data-test="split-tax">
                                <span class="truncate">{{ $this->purchaseTaxCodes->whereIn('id', $splitTaxIds)->map->label()->implode(', ') ?: __('No tax') }}</span>
                            </flux:button>
                            <flux:menu>
                                <flux:menu.checkbox.group wire:model.live="splits.{{ $i }}.tax_code_ids">
                                    @foreach ($this->purchaseTaxCodes as $code)
                                        <flux:menu.checkbox value="{{ $code->id }}" :disabled="count($splitTaxIds) === 2 && ! in_array($code->id, $splitTaxIds)" keep-open>{{ $code->label() }}</flux:menu.checkbox>
                                    @endforeach
                                </flux:menu.checkbox.group>
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                    <x-amount-input model="splits.{{ $i }}.amount" class="{{ $splitIsOutflow ? 'col-span-3' : 'col-span-4' }}" placeholder="0.00" data-test="split-amount" />
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeSplit({{ $i }})" class="col-span-1" />
                </div>
            @endforeach

            <div class="flex items-center justify-between text-sm">
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addSplit" data-test="split-add">{{ __('Add line') }}</flux:button>
                <span class="font-mono {{ $splitSum === $splitTargetCents ? 'text-green-600 dark:text-green-500' : 'text-muted-foreground' }}" data-test="split-remaining">
                    {{ __('Allocated :a of :t', ['a' => number_format($splitSum / 100, 2), 't' => number_format($splitTargetCents / 100, 2)]) }}
                </span>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancelSplit">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveSplit" :disabled="$splitSum !== $splitTargetCents" data-test="split-save">{{ __('Add split') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="receive-payment" class="md:w-[680px]" wire:close="cancelReceivePayment" data-test="receive-payment-modal">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Receive payment from this transaction') }}</flux:heading>
            <flux:subheading>{{ __('Apply :amount across the open invoices below. The amounts must add up to the transaction; an invoice may be paid in part.', ['amount' => number_format($receiveTargetCents / 100, 2)]) }}</flux:subheading>

            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left">{{ __('Invoice #') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('Due') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Open balance') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Apply') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($receiveRows as $i => $row)
                            <tr wire:key="receive-invoice-{{ $row['invoice_id'] }}" data-test="receive-payment-row">
                                <td class="px-3 py-2">{{ $row['invoice_no'] }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-muted-foreground">{{ $row['due_date'] }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ number_format($row['balance'] / 100, 2) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <x-amount-input model="receiveRows.{{ $i }}.apply" modifiers=".live.debounce.300ms" size="sm" placeholder="0.00" class="ml-auto w-32 text-right" data-test="receive-payment-apply" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @error('receiveRows')
                <flux:text class="text-red-600" data-test="receive-payment-error">{{ $message }}</flux:text>
            @enderror

            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span class="font-mono {{ $this->receiveTotal() === $receiveTargetCents ? 'text-green-600 dark:text-green-500' : 'text-muted-foreground' }}" data-test="receive-payment-total">
                    {{ __('Allocated :a of :t', ['a' => number_format($this->receiveTotal() / 100, 2), 't' => number_format($receiveTargetCents / 100, 2)]) }}
                </span>
                <div class="flex gap-2">
                    <flux:button variant="ghost" wire:click="cancelReceivePayment" data-test="receive-payment-cancel">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" wire:click="saveReceivePayment" :disabled="$this->receiveTotal() !== $receiveTargetCents" data-test="receive-payment-save">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="pay-bills" class="md:w-[680px]" wire:close="cancelPayBills" data-test="pay-bills-modal">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Pay bills from this transaction') }}</flux:heading>
            <flux:subheading>{{ __('Apply :amount across the open bills below. The amounts must add up to the transaction; a bill may be paid in part.', ['amount' => number_format($payBillsTargetCents / 100, 2)]) }}</flux:subheading>

            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left">{{ __('Bill #') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('Type') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('Due') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Open balance') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Apply') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($payBillsRows as $i => $row)
                            <tr wire:key="pay-bill-{{ $row['bill_id'] }}" data-test="pay-bills-row">
                                <td class="px-3 py-2">{{ $row['bill_no'] }}</td>
                                <td class="px-3 py-2">
                                    @if ($row['type'] === \App\Enums\BillType::Reimbursement->value)
                                        <flux:badge size="sm" color="zinc">{{ __('Reimbursement') }}</flux:badge>
                                    @else
                                        <span class="text-muted-foreground">{{ __('Bill') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-muted-foreground">{{ $row['due_date'] }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ number_format($row['balance'] / 100, 2) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <x-amount-input model="payBillsRows.{{ $i }}.apply" modifiers=".live.debounce.300ms" size="sm" placeholder="0.00" class="ml-auto w-32 text-right" data-test="pay-bills-apply" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @error('payBillsRows')
                <flux:text class="text-red-600" data-test="pay-bills-error">{{ $message }}</flux:text>
            @enderror

            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span class="font-mono {{ $this->payBillsTotal() === $payBillsTargetCents ? 'text-green-600 dark:text-green-500' : 'text-muted-foreground' }}" data-test="pay-bills-total">
                    {{ __('Allocated :a of :t', ['a' => number_format($this->payBillsTotal() / 100, 2), 't' => number_format($payBillsTargetCents / 100, 2)]) }}
                </span>
                <div class="flex gap-2">
                    <flux:button variant="ghost" wire:click="cancelPayBills" data-test="pay-bills-cancel">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" wire:click="savePayBills" :disabled="$this->payBillsTotal() !== $payBillsTargetCents" data-test="pay-bills-save">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </div>
    </flux:modal>
</section>
