<?php

use App\Support\UserCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public function currentCompany(): ?array
    {
        $company = Auth::user()->currentCompany;

        return $company ? [
            'id' => $company->id,
            'name' => $company->name,
            'display_name' => $company->brandDisplayName(),
            'slug' => $company->slug,
            'initials' => $company->brandInitials(),
            'logo_url' => $company->logoUrl(),
            'text_color' => $company->brandTextColor(),
            'bg_color' => $company->brandBackgroundColor(),
        ] : null;
    }

    /**
     * @return Collection<int, UserCompany>
     */
    public function companies(): Collection
    {
        return Auth::user()->toUserCompanies(includeCurrent: true);
    }
}; ?>

@php $current = $this->currentCompany(); @endphp
<div class="w-full min-w-0">
    <flux:dropdown position="bottom" align="start">
        <flux:button variant="ghost" class="group w-full justify-start in-data-flux-sidebar-collapsed-desktop:justify-center" data-test="company-switcher-trigger">
            <span
                class="flex aspect-square size-6 shrink-0 items-center justify-center overflow-hidden rounded text-[10px] font-semibold leading-none"
                style="background-color: {{ $current['bg_color'] ?? '#18181b' }}; color: {{ $current['text_color'] ?? '#ffffff' }};"
            >
                @if (! empty($current['logo_url']))
                    <img src="{{ $current['logo_url'] }}" alt="{{ $current['display_name'] }}" class="size-full object-cover" />
                @else
                    {{ $current['initials'] ?? 'L' }}
                @endif
            </span>
            <span class="ms-2 truncate font-semibold in-data-flux-sidebar-collapsed-desktop:hidden">{{ $current['display_name'] ?? __('Select company') }}</span>
            <flux:icon
                name="chevrons-up-down"
                variant="micro"
                class="ms-auto size-4 in-data-flux-sidebar-collapsed-desktop:hidden"
            />
        </flux:button>

        <flux:menu class="min-w-56">
            <flux:menu.heading>{{ __('Organizations') }}</flux:menu.heading>

            @foreach ($this->companies() as $company)
                @if ($company->isCurrent)
                    <flux:menu.item class="cursor-pointer" data-test="company-switcher-item">
                        <div class="flex w-full items-center justify-between">
                            <span>{{ $company->displayName }}</span>
                            <flux:icon name="check" class="size-4" />
                        </div>
                    </flux:menu.item>
                @else
                    {{-- A native form, not a Livewire action: a window.open() after the round trip gets popup-blocked. --}}
                    <form method="POST" action="{{ route('companies.switch', $company->slug) }}" target="_blank" rel="noopener">
                        @csrf
                        <input type="hidden" name="from" value="{{ $current['slug'] ?? '' }}" />
                        <flux:menu.item
                            type="submit"
                            icon:trailing="arrow-top-right-on-square"
                            class="cursor-pointer"
                            data-test="company-switcher-item"
                        >
                            {{ $company->displayName }}
                        </flux:menu.item>
                    </form>
                @endif
            @endforeach

            <flux:menu.separator />

            <flux:menu.item icon="plus" class="cursor-pointer" :href="route('companies.setup')" wire:navigate data-test="company-switcher-new-company">
                {{ __('New organization') }}
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>
