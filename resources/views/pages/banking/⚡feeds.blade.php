<?php

use App\Enums\AccountSubtype;
use App\Jobs\SyncBankFeedAccount;
use App\Models\Account;
use App\Models\BankFeedAccount;
use App\Models\BankFeedConnection;
use App\Models\Company;
use App\Services\Banking\Feeds\ActualBudget\ActualBudgetProvider;
use App\Services\Banking\Feeds\Akahu\AkahuProvider;
use App\Services\Banking\Feeds\BankFeedException;
use App\Services\Banking\Feeds\BankFeedProviders;
use App\Services\Banking\Feeds\BankFeedSyncStatus;
use App\Services\Banking\Feeds\Contracts\BankFeedProvider;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bank feeds')] class extends Component {
    public Company $company;

    public string $appToken = '';

    public string $userToken = '';

    public string $actualServerUrl = '';

    public string $actualPassword = '';

    public string $actualSyncId = '';

    public string $actualFilePassword = '';

    /** @var array<int, int|string> feed account id => chosen ledger account id ('' = not linked) */
    public array $links = [];

    /** @var array<int, string> feed account id => first-sync start date (Y-m-d) */
    public array $syncFrom = [];

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->fillFromAccounts();
    }

    #[Computed]
    public function connection(): ?BankFeedConnection
    {
        return BankFeedConnection::query()->where('company_id', $this->company->id)->where('provider', AkahuProvider::KEY)->first();
    }

    #[Computed]
    public function actualConnection(): ?BankFeedConnection
    {
        return BankFeedConnection::query()->where('company_id', $this->company->id)->where('provider', ActualBudgetProvider::KEY)->first();
    }

    /**
     * Every provider LineLedger knows about, in display order.
     *
     * @return array<string, BankFeedProvider>
     */
    #[Computed]
    public function providers(): array
    {
        return BankFeedProviders::all();
    }

    /**
     * Provider keys this company has a connection for.
     *
     * @return list<string>
     */
    #[Computed]
    public function connectedProviders(): array
    {
        return BankFeedConnection::query()
            ->where('company_id', $this->company->id)
            ->pluck('provider')
            ->map(fn ($key): string => (string) $key)
            ->all();
    }

    /**
     * @return Collection<int, BankFeedAccount>
     */
    #[Computed]
    public function feedAccounts(): Collection
    {
        return BankFeedAccount::query()
            ->with('account', 'connection')
            ->where('company_id', $this->company->id)
            ->orderBy('institution')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function ledgerAccounts(): Collection
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    /**
     * Progress of any sync started from this page, keyed by feed account id.
     *
     * @return array<int, array{state: string, message: string, at: int}>
     */
    #[Computed]
    public function syncStatuses(): array
    {
        $statuses = [];

        foreach ($this->feedAccounts as $fa) {
            $status = BankFeedSyncStatus::get((int) $fa->id);

            if ($status !== null) {
                $statuses[(int) $fa->id] = $status;
            }
        }

        return $statuses;
    }

    #[Computed]
    public function anySyncRunning(): bool
    {
        return collect($this->syncStatuses)->contains(fn (array $s): bool => $s['state'] === 'running');
    }

    private function fillFromAccounts(): void
    {
        foreach ($this->feedAccounts as $fa) {
            $this->links[$fa->id] = $fa->account_id ?? '';
            $this->syncFrom[$fa->id] = $fa->sync_from?->toDateString() ?? '';
        }
    }

    private function reloadState(): void
    {
        unset($this->connection, $this->actualConnection, $this->feedAccounts, $this->connectedProviders);
        $this->fillFromAccounts();
    }

    public function saveTokens(): void
    {
        $this->validate([
            'appToken' => ['required', 'string', 'max:500'],
            'userToken' => ['required', 'string', 'max:500'],
        ]);

        $connection = BankFeedProviders::get(AkahuProvider::KEY)->connect((int) $this->company->id, [
            'app_token' => $this->appToken,
            'user_token' => $this->userToken,
        ]);

        $this->appToken = '';
        $this->userToken = '';

        $this->loadAccounts($connection);
    }

    public function saveActual(): void
    {
        $provider = BankFeedProviders::get(ActualBudgetProvider::KEY);

        if (! $provider->isAvailable()) {
            Flux::toast(variant: 'danger', text: (string) $provider->unavailableReason());

            return;
        }

        $this->validate([
            'actualServerUrl' => ['required', 'url', 'max:500'],
            'actualPassword' => ['required', 'string', 'max:500'],
            'actualSyncId' => ['required', 'string', 'max:200'],
            'actualFilePassword' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $connection = $provider->connect((int) $this->company->id, [
                'server_url' => $this->actualServerUrl,
                'password' => $this->actualPassword,
                'sync_id' => $this->actualSyncId,
                'file_password' => $this->actualFilePassword,
            ]);
        } catch (BankFeedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->actualServerUrl = '';
        $this->actualPassword = '';
        $this->actualSyncId = '';
        $this->actualFilePassword = '';

        $this->loadAccounts($connection);
    }

    public function reloadAccounts(string $provider = AkahuProvider::KEY): void
    {
        $connection = $provider === ActualBudgetProvider::KEY ? $this->actualConnection : $this->connection;

        if ($connection !== null) {
            $this->loadAccounts($connection);
        }
    }

    private function loadAccounts(BankFeedConnection $connection): void
    {
        $provider = BankFeedProviders::forConnection($connection);

        try {
            $items = $provider->accounts($connection);
        } catch (BankFeedException $e) {
            $connection->forceFill(['last_error' => $e->getMessage()])->save();
            $this->reloadState();
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        foreach ($items as $item) {
            BankFeedAccount::query()->updateOrCreate(
                ['bank_feed_connection_id' => $connection->id, 'external_id' => $item->externalId],
                [
                    'company_id' => $this->company->id,
                    'name' => $item->name,
                    'institution' => $item->institution,
                    'account_number' => $item->accountNumber,
                    'currency' => $item->currency,
                ],
            );
        }

        $connection->forceFill(['last_error' => null])->save();
        $this->reloadState();
        Flux::toast(variant: 'success', text: __('Loaded :count accounts from :provider.', ['count' => count($items), 'provider' => $provider->label()]));
    }

    public function saveLink(int $feedAccountId): void
    {
        $feedAccount = BankFeedAccount::query()->where('company_id', $this->company->id)->findOrFail($feedAccountId);

        $chosen = $this->links[$feedAccountId] ?? '';
        $accountId = $chosen === '' ? null : (int) $chosen;

        if ($accountId !== null && ! $this->ledgerAccounts->contains('id', $accountId)) {
            $this->addError('links.'.$feedAccountId, __('Choose one of your bank or credit card accounts.'));

            return;
        }

        if ($accountId !== null) {
            $other = BankFeedAccount::query()
                ->where('company_id', $this->company->id)
                ->where('account_id', $accountId)
                ->where('is_active', true)
                ->where('id', '!=', $feedAccount->id)
                ->first();

            if ($other !== null) {
                $this->addError('links.'.$feedAccountId, __('That bank account already receives another feed (:name). Unlink it there first, or both feeds would import the same transactions.', ['name' => $other->name]));

                return;
            }
        }

        $from = trim((string) ($this->syncFrom[$feedAccountId] ?? ''));

        $this->validate(["syncFrom.{$feedAccountId}" => ['nullable', 'date_format:Y-m-d']]);

        $feedAccount->forceFill([
            'account_id' => $accountId,
            'sync_from' => $from === '' ? null : $from,
            'last_error' => null,
        ])->save();

        $this->reloadState();
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function syncNow(int $feedAccountId): void
    {
        $feedAccount = BankFeedAccount::query()->where('company_id', $this->company->id)->findOrFail($feedAccountId);

        if (! $feedAccount->isLinked() || ! $feedAccount->is_active) {
            Flux::toast(variant: 'danger', text: __('Link this feed account to a bank account first.'));

            return;
        }

        if (BankFeedSyncStatus::isRunning((int) $feedAccount->id)) {
            Flux::toast(text: __('A sync for this account is already running.'));

            return;
        }

        BankFeedSyncStatus::put((int) $feedAccount->id, 'running', __('Starting…'));

        // Runs after this response is sent, so a long first sync neither blocks
        // nor times out the page; the status line below follows its progress.
        SyncBankFeedAccount::dispatchAfterResponse((int) $feedAccount->id, (int) $this->company->id);

        unset($this->syncStatuses, $this->anySyncRunning);
        Flux::toast(text: __('Sync started. You can leave this page — it keeps running.'));
    }

    public function disconnect(string $provider = AkahuProvider::KEY): void
    {
        $connection = $provider === ActualBudgetProvider::KEY ? $this->actualConnection : $this->connection;
        $label = BankFeedProviders::get($provider === ActualBudgetProvider::KEY ? ActualBudgetProvider::KEY : AkahuProvider::KEY)->label();

        if ($connection !== null) {
            BankFeedAccount::query()->where('company_id', $this->company->id)->where('bank_feed_connection_id', $connection->id)->delete();
            $connection->delete();
        }

        $this->links = [];
        $this->syncFrom = [];
        $this->reloadState();
        Flux::toast(text: __(':provider disconnected. Imported transactions were kept.', ['provider' => $label]));
    }
}; ?>

<section class="w-full" @if ($this->anySyncRunning) wire:poll.2s @endif>
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Bank feeds') }}</flux:heading>
        <flux:subheading>{{ __('Pull new transactions from your bank every day. They arrive in For Review, where your bank rules and suggestions apply as usual. File import keeps working alongside.') }}</flux:subheading>
    </div>

    <div class="mb-8 grid gap-4 sm:grid-cols-2" data-test="feed-providers">
        @foreach ($this->providers as $provider)
            <div class="rounded-lg border border-border p-4 {{ $provider->isAvailable() ? '' : 'opacity-70' }}" wire:key="provider-{{ $provider->key() }}" data-test="feed-provider-{{ $provider->key() }}">
                <div class="flex items-center justify-between gap-2">
                    <flux:heading size="lg">{{ $provider->label() }}</flux:heading>
                    @if (! $provider->isAvailable())
                        <flux:badge color="zinc">{{ __('Not set up') }}</flux:badge>
                    @elseif (in_array($provider->key(), $this->connectedProviders, true))
                        <flux:badge color="emerald">{{ __('Connected') }}</flux:badge>
                    @else
                        <flux:badge color="amber">{{ __('Not connected') }}</flux:badge>
                    @endif
                </div>
                <flux:text class="mt-1">{{ $provider->description() }}</flux:text>
                @if ($provider->unavailableReason())
                    <flux:text class="mt-2 text-sm">{{ $provider->unavailableReason() }}</flux:text>
                @endif
            </div>
        @endforeach
    </div>

    @if ($this->actualConnection?->last_error)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" data-test="feed-actual-error">
            <flux:callout.text>{{ $this->actualConnection->last_error }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="mb-8 rounded-lg border border-border p-4" data-test="feed-actual-section">
        <flux:heading size="lg">{{ __('Actual Budget connection') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('Actual syncs with your bank; LineLedger reads the result through the bank bridge. In Actual, link your banks under More → Bank Sync, then enter your Actual server address, its password and the budget Sync ID (Settings → Show advanced settings). Stored encrypted and never shown again.') }}
        </flux:text>

        @if (! $this->providers['actual']->isAvailable())
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-3" data-test="feed-actual-unavailable">
                <flux:callout.text>{{ $this->providers['actual']->unavailableReason() }}</flux:callout.text>
            </flux:callout>
        @endif

        @if ($this->actualConnection)
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <flux:badge color="emerald" data-test="feed-actual-connected">{{ __('Connected') }}</flux:badge>
                @if ($this->actualConnection->last_synced_at)
                    <flux:text>{{ __('Last synced :when', ['when' => $this->actualConnection->last_synced_at->diffForHumans()]) }}</flux:text>
                @endif
                <flux:button size="sm" icon="arrow-path" wire:click="reloadAccounts('actual')" data-test="feed-actual-reload">{{ __('Reload accounts') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="disconnect('actual')" wire:confirm="{{ __('Disconnect Actual Budget? Imported transactions are kept.') }}" data-test="feed-actual-disconnect">{{ __('Disconnect') }}</flux:button>
            </div>
        @endif

        <form wire:submit="saveActual" class="mt-4 grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="actualServerUrl" type="url" :label="__('Actual server address')" placeholder="http://actual:5006" autocomplete="off" data-test="feed-actual-url" />
            <flux:input wire:model="actualSyncId" :label="__('Budget Sync ID')" autocomplete="off" data-test="feed-actual-sync-id" />
            <flux:input wire:model="actualPassword" type="password" :label="__('Server password')" autocomplete="off" data-test="feed-actual-password" />
            <flux:input wire:model="actualFilePassword" type="password" :label="__('Budget file password (only if encrypted)')" autocomplete="off" data-test="feed-actual-file-password" />
            <div class="sm:col-span-2">
                <flux:button type="submit" variant="primary" :disabled="! $this->providers['actual']->isAvailable()" data-test="feed-actual-save">
                    {{ $this->actualConnection ? __('Replace details and reload accounts') : __('Connect and load accounts') }}
                </flux:button>
            </div>
        </form>
    </div>

    @if ($this->connection?->last_error)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" data-test="feed-connection-error">
            <flux:callout.text>{{ $this->connection->last_error }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="mb-8 rounded-lg border border-border p-4">
        <flux:heading size="lg">{{ __('Akahu connection (direct — fallback)') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('Create a personal app at my.akahu.nz, link ANZ, ASB or Wise there, and paste its App ID token and your user access token. They are stored encrypted and never shown again.') }}
        </flux:text>

        @if ($this->connection)
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <flux:badge color="emerald" data-test="feed-connected">{{ __('Connected') }}</flux:badge>
                @if ($this->connection->last_synced_at)
                    <flux:text>{{ __('Last synced :when', ['when' => $this->connection->last_synced_at->diffForHumans()]) }}</flux:text>
                @endif
                <flux:button size="sm" icon="arrow-path" wire:click="reloadAccounts" data-test="feed-reload-accounts">{{ __('Reload accounts') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="disconnect" wire:confirm="{{ __('Disconnect Akahu? Imported transactions are kept.') }}" data-test="feed-disconnect">{{ __('Disconnect') }}</flux:button>
            </div>
        @endif

        <form wire:submit="saveTokens" class="mt-4 grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="appToken" type="password" :label="__('App ID token')" autocomplete="off" data-test="feed-app-token" />
            <flux:input wire:model="userToken" type="password" :label="__('User access token')" autocomplete="off" data-test="feed-user-token" />
            <div class="sm:col-span-2">
                <flux:button type="submit" variant="primary" data-test="feed-save-tokens">
                    {{ $this->connection ? __('Replace tokens and reload accounts') : __('Connect and load accounts') }}
                </flux:button>
            </div>
        </form>
    </div>

    @if ($this->feedAccounts->isNotEmpty())
        <flux:heading size="lg" class="mb-2">{{ __('Accounts') }}</flux:heading>
        <flux:text class="mb-4">{{ __('Link each feed account to the matching bank or credit card account. Only linked accounts sync. The first sync starts at the date you give, or 30 days back.') }}</flux:text>

        <div class="space-y-4">
            @foreach ($this->feedAccounts as $fa)
                <div class="rounded-lg border border-border p-4" data-test="feed-account-row" wire:key="feed-{{ $fa->id }}">
                    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <span class="font-medium">{{ $fa->name }}</span>
                            <flux:badge size="sm" color="zinc">{{ $fa->connection ? ((($this->providers[$fa->connection->provider] ?? null)?->label()) ?? $fa->connection->provider) : '' }}</flux:badge>
                            <span class="text-sm text-muted-foreground">
                                {{ $fa->institution }}{{ $fa->account_number ? ' · '.$fa->account_number : '' }}{{ $fa->currency ? ' · '.$fa->currency : '' }}
                            </span>
                        </div>
                        @if ($fa->last_synced_at)
                            <span class="text-sm text-muted-foreground">{{ __('Synced :when', ['when' => $fa->last_synced_at->diffForHumans()]) }}</span>
                        @endif
                    </div>

                    @if ($status = ($this->syncStatuses[$fa->id] ?? null))
                        <div class="mb-3 flex items-center gap-2 text-sm" data-test="feed-sync-status-{{ $fa->id }}">
                            @if ($status['state'] === 'running')
                                <flux:icon.loading class="size-4" />
                                <span>{{ $status['message'] }}</span>
                            @elseif ($status['state'] === 'done')
                                <flux:icon.check-circle class="size-4 text-emerald-600" />
                                <span>{{ $status['message'] }}</span>
                            @else
                                <flux:icon.exclamation-triangle class="size-4 text-red-600" />
                                <span class="text-red-600">{{ $status['message'] }}</span>
                            @endif
                        </div>
                    @endif

                    @if ($fa->last_error)
                        <flux:text class="mb-3 text-red-600" data-test="feed-account-error">{{ $fa->last_error }}</flux:text>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-3 sm:items-end">
                        <flux:select wire:model="links.{{ $fa->id }}" :label="__('Ledger account')" data-test="feed-link-{{ $fa->id }}">
                            <flux:select.option value="">{{ __('— Not linked —') }}</flux:select.option>
                            @foreach ($this->ledgerAccounts as $a)
                                <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->displayName() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="syncFrom.{{ $fa->id }}" type="date" :label="__('First sync from')" data-test="feed-from-{{ $fa->id }}" />
                        <div class="flex gap-2">
                            <flux:button wire:click="saveLink({{ $fa->id }})" data-test="feed-save-link-{{ $fa->id }}">{{ __('Save') }}</flux:button>
                            @if ($fa->isLinked())
                                <flux:button variant="primary" icon="arrow-path" wire:click="syncNow({{ $fa->id }})" :disabled="($this->syncStatuses[$fa->id]['state'] ?? '') === 'running'" data-test="feed-sync-{{ $fa->id }}">{{ __('Sync now') }}</flux:button>
                            @endif
                        </div>
                    </div>
                    @error('links.'.$fa->id) <flux:text class="mt-2 text-red-600">{{ $message }}</flux:text> @enderror
                    @error('syncFrom.'.$fa->id) <flux:text class="mt-2 text-red-600">{{ $message }}</flux:text> @enderror
                </div>
            @endforeach
        </div>

        <flux:text class="mt-6">
            {{ __('New transactions go to') }} <a class="underline" href="{{ route('banking.review', ['company' => $company->slug]) }}">{{ __('For Review') }}</a>.
        </flux:text>
    @endif
</section>
