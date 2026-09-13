{{-- The holder's side of an edit lock on a GuardsEditLockedForm page: the
     banner when the lock is no longer theirs (or paused for inactivity), and
     the browser keeper that renews and releases the lease. --}}
@props(['lock'])

@php($state = $lock['state'])

@if ($state === \App\Enums\EditLockState::Idle)
    <flux:callout icon="pause-circle" variant="warning" class="mb-6" data-test="edit-lock-idle">
        <flux:callout.heading>{{ __('Editing paused') }}</flux:callout.heading>
        <flux:callout.text>{{ __('No activity for :minutes minutes, so others may be able to open this :noun. Continue to pick up where you left off.', ['minutes' => config('edit_locks.idle_minutes'), 'noun' => $lock['noun']]) }}</flux:callout.text>
        <x-slot name="actions">
            <flux:button size="sm" variant="primary" wire:click="resumeEditLock" data-test="edit-lock-resume">{{ __('Continue editing') }}</flux:button>
        </x-slot>
    </flux:callout>
@elseif ($state === \App\Enums\EditLockState::HeldBy)
    <flux:callout icon="lock-closed" variant="danger" class="mb-6" data-test="edit-lock-lost">
        <flux:callout.heading>{{ __(':name is now editing this :noun', ['name' => $lock['holderName'] ?? __('Someone else'), 'noun' => $lock['noun']]) }}</flux:callout.heading>
        <flux:callout.text>{{ __('Your unsaved changes on this page cannot be saved.') }}</flux:callout.text>
        <x-slot name="actions">
            <flux:button size="sm" wire:click="reloadEditLock" data-test="edit-lock-reload">{{ __('Reload') }}</flux:button>
        </x-slot>
    </flux:callout>
@elseif ($state === \App\Enums\EditLockState::Elsewhere)
    <flux:callout icon="window" variant="warning" class="mb-6" data-test="edit-lock-elsewhere">
        <flux:callout.heading>{{ __('You opened this :noun in another tab or window', ['noun' => $lock['noun']]) }}</flux:callout.heading>
        <flux:callout.text>{{ __('Changes can only be saved from one place at a time. Continue in the other tab, or edit here instead.') }}</flux:callout.text>
        <x-slot name="actions">
            <flux:button size="sm" wire:click="reloadEditLock" data-test="edit-lock-edit-here">{{ __('Edit here instead') }}</flux:button>
        </x-slot>
    </flux:callout>
@elseif ($state === \App\Enums\EditLockState::Changed)
    <flux:callout icon="arrow-path" variant="danger" class="mb-6" data-test="edit-lock-changed">
        <flux:callout.heading>{{ __('Someone else opened or changed this :noun after you did', ['noun' => $lock['noun']]) }}</flux:callout.heading>
        <flux:callout.text>{{ __('Reload to see the latest version. Your unsaved changes on this page cannot be saved.') }}</flux:callout.text>
        <x-slot name="actions">
            <flux:button size="sm" wire:click="reloadEditLock" data-test="edit-lock-reload">{{ __('Reload') }}</flux:button>
        </x-slot>
    </flux:callout>
@endif

@if ($lock['keeper'])
    <x-edit-lock.keeper :config="$lock['keeper']" :token="$lock['token']" />
@endif
