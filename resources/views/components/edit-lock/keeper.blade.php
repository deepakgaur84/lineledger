{{-- Browser keeper for a held edit lock (resources/js/edit-lock.js). Keyed by
     token so taking a new lock starts a fresh keeper. Must sit inside the
     Livewire component holding the lock — it reads the token from $wire. --}}
@props(['config', 'token'])

<div wire:key="edit-lock-keeper-{{ $token }}" x-data="editLockKeeper(@js($config))" class="hidden" aria-hidden="true" data-test="edit-lock-keeper"></div>
