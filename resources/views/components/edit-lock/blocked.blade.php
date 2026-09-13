{{-- The panel an edit form shows instead of the form while another member
     holds the record's edit lock. Rendered by GuardsEditLockedForm pages:
     @if ($editLockBlocked) <x-edit-lock.blocked :lock="$this->editLockView" /> @else …
     Refreshes while the viewer is active so it notices when the lock frees up —
     but never takes it: "Start editing" reloads the page so the form loads the
     other member's saves. --}}
@props(['lock'])

<div class="mx-auto max-w-2xl py-6" data-test="edit-lock-blocked">
    @unless ($lock['available'])
        {{-- Checks every 15s while the viewer is around; goes quiet once they're idle. --}}
        <div wire:key="edit-lock-blocked-poller" x-data="editLockBlockedPoller(@js(['intervalMs' => 15000, 'idleMs' => (int) config('edit_locks.idle_minutes', 15) * 60000]))" class="hidden" aria-hidden="true" data-test="edit-lock-blocked-poller"></div>
    @endunless

    <flux:callout icon="lock-closed" :variant="$lock['available'] ? 'success' : 'secondary'">
        @if ($lock['available'])
            <flux:callout.heading>{{ __('This :noun is free to edit', ['noun' => $lock['noun']]) }}</flux:callout.heading>
            <flux:callout.text>{{ __('The other person has finished editing it.') }}</flux:callout.text>
        @else
            <flux:callout.heading>{{ __(':name is editing this :noun', ['name' => $lock['holderName'], 'noun' => $lock['noun']]) }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('They started :since and were last active :active. Only one person can edit it at a time, so it can be changed again once they are done.', ['since' => $lock['since'], 'active' => $lock['lastActive']]) }}
            </flux:callout.text>
        @endif

        <x-slot name="actions">
            @if ($lock['available'])
                <flux:button size="sm" variant="primary" wire:click="reloadEditLock" data-test="edit-lock-start">{{ __('Start editing') }}</flux:button>
            @else
                <flux:button size="sm" wire:click="reloadEditLock" data-test="edit-lock-retry">{{ __('Try again') }}</flux:button>
            @endif

            @if ($lock['canTakeOver'])
                <flux:button size="sm" variant="danger" wire:click="takeOverEditLock" wire:confirm="{{ __('Take over editing? Anything the other person has not saved will be lost.') }}" data-test="edit-lock-take-over">{{ __('Take over editing') }}</flux:button>
            @endif

            @if ($lock['viewUrl'])
                <flux:button size="sm" variant="ghost" :href="$lock['viewUrl']" wire:navigate data-test="edit-lock-view">{{ __('View :noun', ['noun' => $lock['noun']]) }}</flux:button>
            @endif
        </x-slot>
    </flux:callout>
</div>
