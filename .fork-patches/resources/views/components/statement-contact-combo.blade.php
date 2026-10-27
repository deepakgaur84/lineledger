{{--
    Per-row payee picker for bank statement lines (import wizard + For Review).
    Host must use App\Livewire\Concerns\ManagesStatementLineContacts.
--}}
@props([
    'lineId',
    'addLabel' => 'vendor',
    'placeholder' => null,
    'options' => [],
    'selectedId' => null,
    'selectedName' => null,
    'query' => '',
    'creating' => false,
    'newName' => '',
    'dataTest' => 'line-contact-combo',
])

@php
    $placeholder ??= $addLabel === 'customer' ? __('Customer…') : __('Vendor…');
    $trimmed = trim((string) $query);
    $hasExactMatch = $trimmed !== '' && collect($options)->contains(
        fn ($o) => mb_strtolower($o->display_name) === mb_strtolower($trimmed)
    );
@endphp

<div
    x-data="{
        open: false,
        q: '',
        picked: '',
        list: null,
        inflow: {{ $addLabel === 'customer' ? 'true' : 'false' }},
        async load() {
            const cached = window.__llContactPool;
            if (cached && Date.now() - cached.t < 60000) { this.list = cached.list; return; }
            const list = await $wire.contactPool();
            window.__llContactPool = { t: Date.now(), list: list };
            this.list = list;
        },
        get term() { return this.q.trim().toLowerCase(); },
        get matches() {
            if (!this.term || !this.list) return [];
            return this.list
                .filter(c => c.n.toLowerCase().includes(this.term))
                .sort((a, b) => (this.inflow ? (b.c - a.c) : ((b.v - a.v) || (b.e - a.e))) || a.n.localeCompare(b.n))
                .slice(0, 50);
        },
        get exact() { return this.term !== '' && (this.list || []).some(c => c.n.toLowerCase() === this.term); },
        pick(c) {
            this.picked = c.n;
            this.open = false;
            this.q = '';
            $wire.selectLineContact({{ $lineId }}, c.id);
        },
    }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative min-w-[200px]"
    wire:ignore.self
    wire:key="line-contact-{{ $lineId }}"
    data-test="{{ $dataTest }}"
>
    @if ($selectedId)
        <div class="flex h-8 items-center justify-between gap-2 rounded-md border border-border bg-card px-2">
            <span class="truncate text-xs" data-test="{{ $dataTest }}-selected">{{ $selectedName ?? __('Contact #:id', ['id' => $selectedId]) }}</span>
            <button
                type="button"
                wire:click="clearLineContact({{ $lineId }})"
                class="shrink-0 text-muted-foreground hover:text-foreground"
                aria-label="{{ __('Clear') }}"
                data-test="{{ $dataTest }}-clear"
            >
                <flux:icon name="x-mark" class="size-3.5" />
            </button>
        </div>
    @elseif ($creating)
        <div class="flex h-8 items-center justify-between gap-1 rounded-md border border-border bg-card px-2">
            <input
                type="text"
                wire:model.live.blur="lineContact.{{ $lineId }}.new_name"
                wire:keydown.enter.prevent="createLineContact({{ $lineId }})"
                placeholder="{{ __('New :name name', ['name' => $addLabel]) }}"
                class="block w-full bg-transparent text-xs text-foreground focus:outline-none"
                data-test="{{ $dataTest }}-new-name"
                autofocus
            />
            <button
                type="button"
                wire:click="createLineContact({{ $lineId }})"
                class="shrink-0 text-emerald-600 hover:text-emerald-700"
                aria-label="{{ __('Create :name', ['name' => $addLabel]) }}"
                data-test="{{ $dataTest }}-create"
            >
                <flux:icon name="check" class="size-3.5" />
            </button>
            <button
                type="button"
                wire:click="clearLineContact({{ $lineId }})"
                class="shrink-0 text-muted-foreground hover:text-foreground"
                aria-label="{{ __('Cancel new :name', ['name' => $addLabel]) }}"
                data-test="{{ $dataTest }}-cancel-new"
            >
                <flux:icon name="x-mark" class="size-3.5" />
            </button>
        </div>
    @else
        {{-- Searched in the browser (see contactPool()), so typing and picking feel instant. --}}
        <div x-show="picked !== ''" x-cloak class="flex h-8 items-center gap-2 rounded-md border border-border bg-card px-2 text-xs">
            <flux:icon.loading class="size-3.5" />
            <span class="truncate" x-text="picked"></span>
        </div>

        <div x-show="picked === ''">
            <flux:input
                size="sm"
                x-model="q"
                placeholder="{{ $placeholder }}"
                @focus="open = true; load()"
                @input="open = true"
                @keydown.enter.prevent=""
                autocomplete="off"
                data-test="{{ $dataTest }}-search"
            />

            <div
                x-show="open"
                data-escape-guard
                x-cloak
                class="absolute left-0 right-0 top-full z-20 mt-1 max-h-60 overflow-auto rounded-md border border-border bg-card py-1 shadow-lg"
            >
                <template x-for="c in matches" :key="c.id">
                    <button
                        type="button"
                        @click="pick(c)"
                        class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-xs hover:bg-muted"
                        data-test="{{ $dataTest }}-option"
                    >
                        <span class="truncate" x-text="c.n"></span>
                        <span x-show="c.e && !c.v" class="rounded bg-zinc-100 px-1.5 text-[10px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ __('Employee') }}</span>
                    </button>
                </template>

                <div x-show="term === ''" class="px-3 py-1.5 text-xs text-muted-foreground">{{ __('Start typing to search.') }}</div>
                <div x-show="term !== '' && list === null" class="px-3 py-1.5 text-xs text-muted-foreground">{{ __('Loading…') }}</div>

                {{-- Server-side results: only when a query was set on the component itself. --}}
                @if ($trimmed !== '')
                    @foreach ($options as $opt)
                        <button
                            type="button"
                            wire:click="selectLineContact({{ $lineId }}, {{ $opt->id }})"
                            class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-xs hover:bg-muted"
                            data-test="{{ $dataTest }}-option"
                        >
                            <span class="truncate">{{ $opt->display_name }}</span>
                            @if (($opt->is_employee ?? false) && ! ($opt->is_vendor ?? false))
                                <flux:badge size="sm" color="zinc" data-test="{{ $dataTest }}-role">{{ __('Employee') }}</flux:badge>
                            @endif
                        </button>
                    @endforeach
                @endif

                <button
                    type="button"
                    x-show="term !== '' && list !== null && !exact"
                    @click="$wire.startNewLineContact({{ $lineId }}, q)"
                    class="block w-full border-t border-border px-3 py-1.5 text-left text-xs font-medium text-blue-600 hover:bg-muted dark:text-blue-400"
                    data-test="{{ $dataTest }}-add-new"
                >
                    + {{ __('Add') }} "<span x-text="q.trim()"></span>" {{ __('as new') }} {{ $addLabel }}
                </button>
            </div>
        </div>
    @endif
</div>
