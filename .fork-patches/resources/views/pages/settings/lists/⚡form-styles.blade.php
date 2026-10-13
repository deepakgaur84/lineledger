<?php

use App\Actions\Sales\SaveFormStyle;
use App\Enums\InvoiceFont;
use App\Enums\InvoiceLayout;
use App\Enums\InvoiceSection;
use App\Enums\LogoPosition;
use App\Livewire\Concerns\HoldsEditLock;
use App\Models\Company;
use App\Models\FormStyle;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Form styles')] class extends Component {
    use HoldsEditLock;

    public Company $company;

    public ?int $editingId = null;

    public string $f_name = '';

    public string $f_layout = 'classic';

    public bool $f_show_logo = true;

    public string $f_logo_position = 'left';

    public string $f_logo_max_height = '';

    public string $f_accent_color = '';

    public string $f_font_family = 'sans';

    public string $f_title_text = '';

    public bool $f_show_ship_to = true;

    public bool $f_zebra_rows = false;

    /** @var list<array{key: string, visible: bool}> */
    public array $f_sections = [];

    public string $f_footer_message = '';

    public bool $f_is_default = false;

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function openCreate(): void
    {
        $this->releaseEditLock();
        $this->reset([
            'editingId', 'f_name', 'f_layout', 'f_show_logo', 'f_logo_position', 'f_logo_max_height', 'f_accent_color',
            'f_font_family', 'f_title_text', 'f_show_ship_to', 'f_zebra_rows', 'f_sections', 'f_footer_message', 'f_is_default', 'f_is_active',
        ]);
        // New styles start from a designed layout; Classic stays available.
        $this->f_layout = InvoiceLayout::Modern->value;
        $this->f_accent_color = InvoiceLayout::Modern->defaultAccent() ?? '';
        $this->f_show_logo = true;
        $this->f_show_ship_to = true;
        $this->f_sections = InvoiceSection::normalize(null);
        $this->f_is_active = true;
        Flux::modal('form-styles-form')->show();
    }

    public function openEdit(int $id): void
    {
        $s = FormStyle::findOrFail($id);

        if (! $this->acquireEditLock($s, reopen: 'openEdit', reopenArgs: [$id])) {
            return;
        }

        $this->editingId = $s->id;
        $this->f_name = $s->name;
        $this->f_layout = ($s->layout ?? InvoiceLayout::default())->value;
        $this->f_show_logo = $s->show_logo;
        $this->f_logo_position = ($s->logo_position ?? LogoPosition::default())->value;
        $this->f_logo_max_height = $s->logo_max_height ? (string) $s->logo_max_height : '';
        $this->f_accent_color = $s->accent_color ?? '';
        $this->f_font_family = ($s->font_family ?? InvoiceFont::default())->value;
        $this->f_title_text = $s->title_text ?? '';
        $this->f_show_ship_to = (bool) $s->show_ship_to;
        $this->f_zebra_rows = (bool) $s->zebra_rows;
        $this->f_sections = InvoiceSection::normalize($s->getAttributeValue('sections'));
        $this->f_footer_message = $s->footer_message ?? '';
        $this->f_is_default = $s->is_default;
        $this->f_is_active = $s->is_active;
        Flux::modal('form-styles-form')->show();
    }

    /**
     * Switching layout also switches the accent, unless the user has picked a
     * colour of their own (anything that isn't blank or another layout's default).
     */
    public function updatedFLayout(string $value): void
    {
        $defaults = collect(InvoiceLayout::cases())
            ->map(fn (InvoiceLayout $layout) => $layout->defaultAccent())
            ->filter()
            ->all();

        if ($this->f_accent_color === '' || in_array(strtolower($this->f_accent_color), $defaults, true)) {
            $this->f_accent_color = InvoiceLayout::tryFrom($value)?->defaultAccent() ?? '';
        }
    }

    /** Drag-and-drop: move the section with this key to a zero-based position. */
    public function sortSection(string $key, int $position): void
    {
        $sections = InvoiceSection::normalize($this->f_sections);
        $index = collect($sections)->search(fn (array $entry) => $entry['key'] === $key);

        if ($index === false) {
            return;
        }

        [$moved] = array_splice($sections, (int) $index, 1);
        array_splice($sections, max(0, min($position, count($sections))), 0, [$moved]);

        $this->f_sections = $sections;
    }

    /** Keyboard/button alternative to dragging: -1 moves up, 1 moves down. */
    public function moveSection(string $key, int $direction): void
    {
        $index = collect(InvoiceSection::normalize($this->f_sections))->search(fn (array $entry) => $entry['key'] === $key);

        if ($index !== false) {
            $this->sortSection($key, (int) $index + ($direction < 0 ? -1 : 1));
        }
    }

    public function save(): void
    {
        if ($this->editingId !== null && ! $this->ensureEditLockForSave(FormStyle::class, $this->editingId)) {
            return;
        }

        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_layout' => ['required', Rule::enum(InvoiceLayout::class)],
            'f_show_logo' => ['boolean'],
            'f_logo_position' => ['required', Rule::enum(LogoPosition::class)],
            'f_logo_max_height' => ['nullable', 'integer', 'min:16', 'max:200'],
            'f_accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'f_font_family' => ['required', Rule::enum(InvoiceFont::class)],
            'f_title_text' => ['nullable', 'string', 'max:40'],
            'f_show_ship_to' => ['boolean'],
            'f_zebra_rows' => ['boolean'],
            'f_sections' => ['array'],
            'f_sections.*.key' => ['required', Rule::enum(InvoiceSection::class)],
            'f_sections.*.visible' => ['boolean'],
            'f_footer_message' => ['nullable', 'string'],
            'f_is_default' => ['boolean'],
            'f_is_active' => ['boolean'],
        ], [
            'f_accent_color.regex' => __('Enter a 6-digit hex colour like #2563eb.'),
            'f_logo_max_height.min' => __('The logo height must be between 16 and 200 pixels.'),
            'f_logo_max_height.max' => __('The logo height must be between 16 and 200 pixels.'),
        ]);

        $editing = $this->editingId ? FormStyle::findOrFail($this->editingId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        app(SaveFormStyle::class)->handle([
            'name' => $validated['f_name'],
            'layout' => $validated['f_layout'],
            'show_logo' => $validated['f_show_logo'],
            'logo_position' => $validated['f_logo_position'],
            'logo_max_height' => $validated['f_logo_max_height'] !== '' && $validated['f_logo_max_height'] !== null ? (int) $validated['f_logo_max_height'] : null,
            'accent_color' => $validated['f_accent_color'] ?: null,
            'font_family' => $validated['f_font_family'],
            'title_text' => $validated['f_title_text'] ?: null,
            'show_ship_to' => $validated['f_show_ship_to'],
            'zebra_rows' => $validated['f_zebra_rows'],
            'sections' => $validated['f_sections'],
            'footer_message' => $validated['f_footer_message'] ?: null,
            'is_default' => $validated['f_is_default'],
            'is_active' => $validated['f_is_active'],
        ], $editing);

        $this->completeEditLockSave();
        Flux::modal('form-styles-form')->close();
        Flux::toast(variant: 'success', text: __('Form style saved.'));
    }

    #[Computed]
    public function styles()
    {
        return FormStyle::query()->orderBy('name')->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Form styles')" :subheading="__('Design your invoices: pick a layout, colour, font and logo placement, then choose a style on each invoice or set one as the default.')">
        <div class="mb-4 flex justify-end">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-style-button">{{ __('New style') }}</flux:button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Layout') }}</th>
                        <th class="px-4 py-2 text-center">{{ __('Default') }}</th>
                        <th class="px-4 py-2 text-center">{{ __('Accent') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->styles as $s)
                        <tr data-test="style-row" class="@if(! $s->is_active) opacity-50 @endif">
                            <td class="px-4 py-2">{{ $s->name }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ ($s->layout ?? \App\Enums\InvoiceLayout::default())->label() }}</td>
                            <td class="px-4 py-2 text-center">
                                @if ($s->is_default)
                                    <flux:badge color="emerald" size="sm" data-test="style-default-badge">{{ __('Default') }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-center">
                                @if ($s->accent_color)
                                    <span class="inline-block size-4 rounded-full border border-border align-middle" style="background: {{ $s->accent_color }};" title="{{ $s->accent_color }}"></span>
                                @else
                                    <span class="text-muted-foreground">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                <flux:button variant="ghost" size="sm" icon="eye" :href="route('lists.form-styles.preview', ['company' => $company, 'style' => $s->id])" target="_blank" :tooltip="__('Preview as PDF')" data-test="style-preview-link" />
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $s->id }})" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No form styles yet. Invoices use your invoice settings until you add one.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="form-styles-form" class="w-full max-w-3xl" wire:close="releaseEditLock">
        <form wire:submit="save" class="space-y-6">
            @if ($editLockToken)
                <x-edit-lock.keeper :config="$this->editLockKeeper" :token="$editLockToken" />
            @endif
            <flux:heading size="lg">{{ $editingId ? __('Edit form style') : __('New form style') }}</flux:heading>
            <flux:input wire:model="f_name" :label="__('Name')" required data-test="style-name" />

            <div>
                <flux:label>{{ __('Layout') }}</flux:label>
                <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3" data-test="layout-gallery">
                    @foreach (\App\Enums\InvoiceLayout::cases() as $layout)
                        <label wire:key="layout-{{ $layout->value }}" class="cursor-pointer rounded-lg border border-border p-2 transition hover:bg-muted @if ($f_layout === $layout->value) ring-2 ring-blue-600 @endif">
                            <input type="radio" class="sr-only" wire:model.live="f_layout" value="{{ $layout->value }}" data-test="layout-{{ $layout->value }}" />
                            @include('partials.invoice-layout-thumb', ['layout' => $layout->value, 'accent' => $f_accent_color])
                            <div class="mt-2 text-sm font-medium">{{ __($layout->label()) }}</div>
                            <div class="text-xs text-muted-foreground">{{ __($layout->description()) }}</div>
                        </label>
                    @endforeach
                </div>
                <flux:error name="f_layout" />
            </div>

            @if ($f_layout !== 'classic')
                <div data-test="section-order">
                    <flux:label>{{ __('Page sections') }}</flux:label>
                    <flux:description class="mt-1">{{ __('Drag to reorder what appears below the header, or switch a section off. The header always stays on top.') }}</flux:description>
                    <ul wire:sort="sortSection" class="mt-2 divide-y divide-border rounded-lg border border-border">
                        @foreach ($f_sections as $i => $entry)
                            @php($section = \App\Enums\InvoiceSection::from($entry['key']))
                            <li wire:key="section-{{ $entry['key'] }}" wire:sort:item="{{ $entry['key'] }}" class="flex items-center gap-3 bg-card px-3 py-2 @if (! $entry['visible']) opacity-60 @endif" data-test="section-row-{{ $entry['key'] }}">
                                <span wire:sort:handle class="cursor-grab text-muted-foreground" title="{{ __('Drag to reorder') }}" data-test="section-handle-{{ $entry['key'] }}">
                                    <flux:icon name="bars-3" class="size-5" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium">{{ __($section->label()) }}</div>
                                    <div class="text-xs text-muted-foreground">{{ __($section->description()) }}</div>
                                </div>
                                <flux:button type="button" size="xs" variant="ghost" icon="chevron-up" wire:click="moveSection('{{ $entry['key'] }}', -1)" :disabled="$loop->first" :tooltip="__('Move up')" data-test="section-up-{{ $entry['key'] }}" />
                                <flux:button type="button" size="xs" variant="ghost" icon="chevron-down" wire:click="moveSection('{{ $entry['key'] }}', 1)" :disabled="$loop->last" :tooltip="__('Move down')" data-test="section-down-{{ $entry['key'] }}" />
                                <flux:switch wire:model.live="f_sections.{{ $i }}.visible" :aria-label="__('Show :section', ['section' => __($section->label())])" data-test="section-visible-{{ $entry['key'] }}" />
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
                <flux:text class="text-sm text-muted-foreground" data-test="section-order-classic-note">{{ __('The Classic layout has a fixed structure. Pick another layout to reorder its sections.') }}</flux:text>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <flux:label>{{ __('Accent colour') }}</flux:label>
                    <div class="mt-2 flex items-center gap-2">
                        <input type="color" wire:model.live="f_accent_color" value="{{ preg_match('/^#[0-9a-fA-F]{6}$/', $f_accent_color) ? $f_accent_color : '#2563eb' }}" class="h-10 w-14 cursor-pointer rounded-md border border-border bg-card" data-test="style-accent-color-picker" />
                        <flux:input wire:model.live.debounce.500ms="f_accent_color" placeholder="#2563eb" data-test="style-accent-color" class="flex-1" />
                    </div>
                    <flux:description class="mt-1">{{ __('Tints the title, headings and totals. Leave blank for the layout\'s own colour.') }}</flux:description>
                    <flux:error name="f_accent_color" />
                </div>
                <flux:select wire:model="f_font_family" :label="__('Font')" data-test="style-font">
                    @foreach (\App\Enums\InvoiceFont::cases() as $font)
                        <flux:select.option value="{{ $font->value }}">{{ __($font->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="f_title_text" :label="__('Document title')" placeholder="{{ __('INVOICE') }}" :description="__('For example TAX INVOICE. Leave blank for INVOICE.')" data-test="style-title-text" />
                <flux:input wire:model="f_footer_message" :label="__('Footer message')" :description="__('Overrides the footer message from invoice settings.')" data-test="style-footer-message" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model="f_logo_position" :label="__('Logo position')" :description="__('The Elegant layout always centres the logo; Classic keeps it on the left.')" data-test="style-logo-position">
                    @foreach (\App\Enums\LogoPosition::cases() as $position)
                        <flux:select.option value="{{ $position->value }}">{{ __($position->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="f_logo_max_height" type="number" min="16" max="200" :label="__('Logo height (px)')" :description="__('Leave blank to use the height from your company settings.')" data-test="style-logo-height" />
            </div>

            <div class="space-y-3">
                <flux:switch wire:model="f_show_logo" :label="__('Show logo')" data-test="style-show-logo" />
                <flux:switch wire:model="f_show_ship_to" :label="__('Show ship-to address')" data-test="style-show-ship-to" />
                <flux:switch wire:model="f_zebra_rows" :label="__('Striped line rows')" :description="__('The Ledger layout is always striped.')" data-test="style-zebra-rows" />
                <flux:switch wire:model="f_is_default" :label="__('Default style')" :description="__('Used for invoices that don\'t pick a style.')" data-test="style-is-default" />
                <flux:switch wire:model="f_is_active" :label="__('Active')" />
            </div>

            <div
                x-data="{
                    on: false,
                    src: '',
                    timer: null,
                    base: @js(route('lists.form-styles.preview-draft', ['company' => $company])),
                    url() {
                        const w = $wire;
                        return this.base + '?' + new URLSearchParams({
                            layout: w.f_layout,
                            font_family: w.f_font_family,
                            logo_position: w.f_logo_position,
                            logo_max_height: w.f_logo_max_height ?? '',
                            accent_color: w.f_accent_color ?? '',
                            title_text: w.f_title_text ?? '',
                            footer_message: w.f_footer_message ?? '',
                            show_logo: w.f_show_logo ? 1 : 0,
                            show_ship_to: w.f_show_ship_to ? 1 : 0,
                            zebra_rows: w.f_zebra_rows ? 1 : 0,
                            sections: (w.f_sections ?? []).map(s => s.key + ':' + (s.visible ? 1 : 0)).join(','),
                        }).toString();
                    },
                    refresh() { this.src = this.url(); },
                }"
                x-effect="if (on) { const next = url(); clearTimeout(timer); timer = setTimeout(() => src = next, 700); }"
                data-test="style-live-preview"
            >
                <div class="flex items-center justify-between gap-2">
                    <flux:label>{{ __('Live preview') }}</flux:label>
                    <div class="flex gap-2">
                        <flux:button type="button" size="sm" variant="ghost" icon="eye" x-show="! on" x-on:click="on = true" data-test="style-preview-show">{{ __('Show preview') }}</flux:button>
                        <flux:button type="button" size="sm" variant="ghost" icon="arrow-path" x-show="on" x-cloak x-on:click="refresh()" data-test="style-preview-refresh">{{ __('Refresh') }}</flux:button>
                        <flux:button type="button" size="sm" variant="ghost" icon="arrow-top-right-on-square" x-show="on" x-cloak x-on:click="window.open(url(), '_blank')" data-test="style-preview-draft">{{ __('Open in new tab') }}</flux:button>
                    </div>
                </div>
                <flux:description class="mt-1">{{ __('Drawn on your latest invoice (or a sample) using the settings above, before you save.') }}</flux:description>
                <template x-if="on">
                    <iframe :src="src" class="mt-2 h-[560px] w-full rounded-lg border border-border bg-white" title="{{ __('Invoice preview') }}"></iframe>
                </template>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="style-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-edit-lock.takeover-modal :pending="$editLockPendingTakeover" />
</section>
