{{-- Show-page notice that another member is editing the record (ShowsEditLock):
     <x-edit-lock.banner :lock="$this->editLockBanner" /> --}}
@props(['lock' => null])

@if ($lock)
    <flux:callout icon="lock-closed" variant="warning" class="mb-6" data-test="edit-lock-banner">
        <flux:callout.heading>{{ __(':name is editing this :noun', ['name' => $lock['name'], 'noun' => $lock['noun']]) }}</flux:callout.heading>
        <flux:callout.text>{{ __('Started :since, last active :active. Changes such as voiding, posting or deleting wait until they are done.', ['since' => $lock['since'], 'active' => $lock['lastActive']]) }}</flux:callout.text>
        @if ($lock['canTakeOver'])
            <x-slot name="actions">
                <flux:button size="sm" wire:click="takeOverEditLock" wire:confirm="{{ __('Take over editing? Anything the other person has not saved will be lost.') }}" data-test="edit-lock-take-over">{{ __('Take over editing') }}</flux:button>
            </x-slot>
        @endif
    </flux:callout>
@endif
