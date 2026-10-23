<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\BankFeedAccount;
use App\Models\BankFeedConnection;
use App\Models\Company;
use App\Services\Banking\Feeds\Akahu\AkahuClient;
use App\Services\Banking\Feeds\Akahu\AkahuException;
use App\Services\Banking\Feeds\BankFeedSyncer;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

new #[Title('Bank feeds')] class extends Component {
    public Company $company;

    public string $appToken = '';

    public string $userToken = '';

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
        return BankFeedConnection::query()->where('company_id', $this->company->id)->where('provider', 'akahu')->first();
    }

    /**
     * @return Collection<int, BankFeedAccount>
     */
    #[Computed]
    public function feedAccounts(): Collection
    {
        return BankFeedAccount::query()
            ->with('account')
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

    private function fillFromAccounts(): void
    {
        foreach ($this->feedAccounts as $fa) {
            $this->links[$fa->id] = $fa->account_id ?? '';
            $this->syncFrom[$fa->id] = $fa->sync_from?->toDateString() ?? '';
        }
    }

    private function reloadState(): void
    {
        unset($this->connection, $this->feedAccounts);
        $this->fillFromAccounts();
    }

    public function saveTokens(): void
    {
        $this->validate([
            'appToken' => ['required', 'string', 'max:500'],
            'userToken' => ['required', 'string', 'max:500'],
        ]);

        $connection = BankFeedConnection::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'provider' => 'akahu'],
            ['app_token' => trim($this->appToken), 'user_token' => trim($this->userToken), 'status' => 'active', 'last_error' => null],
        );

        $this->appToken = '';
        $this->userToken = '';

        $this->loadAccounts($connection);
    }

    public function reloadAccounts(): void
    {
        $connection = $this->connection;

        if ($connection !== null) {
            $this->loadAccounts($connection);
        }
    }

    private function loadAccounts(BankFeedConnection $connection): void
    {
        try {
            $items = AkahuClient::make($connection->app_token, $connection->user_token)->accounts();
        } catch (AkahuException $e) {
            $connection->forceFill(['last_error' => $e->getMessage()])->save();
            $this->reloadState();
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        foreach ($items as $item) {
            $externalId = $item['_id'] ?? null;

            if (! is_string($externalId) || $externalId === '') {
                continue;
            }

            $balance = $item['balance'] ?? [];
            $currency = is_array($balance) ? ($balance['currency'] ?? null) : null;

            BankFeedAccount::query()->updateOrCreate(
                ['bank_feed_connection_id' => $connection->id, 'external_id' => $externalId],
                [
                    'company_id' => $this->company->id,
                    'name' => (string) ($item['name'] ?? $externalId),
                    'institution' => is_array($item['connection'] ?? null) ? ($item['connection']['name'] ?? null) : null,
                    'account_number' => is_string($item['formatted_account'] ?? null) ? $item['formatted_account'] : null,
                    'currency' => is_string($currency) && strlen($currency) === 3 ? strtoupper($currency) : null,
                ],
            );
        }

        $connection->forceFill(['last_error' => null])->save();
        $this->reloadState();
        Flux::toast(variant: 'success', text: __('Loaded :count accounts from Akahu.', ['count' => count($items)]));
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

    public function syncNow(int $feedAccountId, BankFeedSyncer $syncer): void
    {
        $feedAccount = BankFeedAccount::query()->where('company_id', $this->company->id)->findOrFail($feedAccountId);

        try {
            $result = $syncer->sync($feedAccount);
        } catch (Throwable $e) {
            $this->reloadState();
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->reloadState();
        Flux::toast(variant: 'success', text: __(':added new, :updated updated.', ['added' => $result['added'], 'updated' => $result['updated']]));
    }

    public function disconnect(): void
    {
        BankFeedConnection::query()->where('company_id', $this->company->id)->where('provider', 'akahu')->delete();
        BankFeedAccount::query()->where('company_id', $this->company->id)->delete();

        $this->links = [];
        $this->syncFrom = [];
        $this->reloadState();
        Flux::toast(text: __('Akahu disconnected. Imported transactions were kept.'));
    }
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Bank feeds') }}</flux:heading>
        <flux:subheading>{{ __('Pull new transactions from your bank through Akahu every day. They arrive in For Review, where your bank rules and suggestions apply as usual. File import keeps working alongside.') }}</flux:subheading>
    </div>

    @if ($this->connection?->last_error)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" data-test="feed-connection-error">
            <flux:callout.text>{{ $this->connection->last_error }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="mb-8 rounded-lg border border-border p-4">
        <flux:heading size="lg">{{ __('Akahu connection') }}</flux:heading>
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
        <flux:text class="mb-4">{{ __('Link each Akahu account to the matching bank or credit card account. Only linked accounts sync. The first sync starts at the date you give, or 30 days back.') }}</flux:text>

        <div class="space-y-4">
            @foreach ($this->feedAccounts as $fa)
                <div class="rounded-lg border border-border p-4" data-test="feed-account-row" wire:key="feed-{{ $fa->id }}">
                    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <span class="font-medium">{{ $fa->name }}</span>
                            <span class="text-sm text-muted-foreground">
                                {{ $fa->institution }}{{ $fa->account_number ? ' · '.$fa->account_number : '' }}{{ $fa->currency ? ' · '.$fa->currency : '' }}
                            </span>
                        </div>
                        @if ($fa->last_synced_at)
                            <span class="text-sm text-muted-foreground">{{ __('Synced :when', ['when' => $fa->last_synced_at->diffForHumans()]) }}</span>
                        @endif
                    </div>

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
                                <flux:button variant="primary" icon="arrow-path" wire:click="syncNow({{ $fa->id }})" data-test="feed-sync-{{ $fa->id }}">{{ __('Sync now') }}</flux:button>
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
