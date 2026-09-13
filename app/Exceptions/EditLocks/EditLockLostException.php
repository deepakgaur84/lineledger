<?php

namespace App\Exceptions\EditLocks;

use App\Enums\EditLockState;
use App\Services\EditLocks\EditLockHolder;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * An open edit page no longer holds its record's edit lock, so its save is
 * refused. UI-only: the message may name the member who now holds the lock.
 */
class EditLockLostException extends RuntimeException implements ShouldntReport
{
    private function __construct(
        public readonly EditLockState $state,
        string $message,
        public readonly ?EditLockHolder $holder = null,
    ) {
        parent::__construct($message);
    }

    public static function heldBy(string $noun, EditLockHolder $holder): self
    {
        return new self(EditLockState::HeldBy, __(":name is now editing this :noun. Your unsaved changes can't be saved.", [
            'name' => $holder->name(),
            'noun' => $noun,
        ]), $holder);
    }

    public static function elsewhere(string $noun): self
    {
        return new self(EditLockState::Elsewhere, __("You're editing this :noun in another tab or window, so changes here can't be saved.", [
            'noun' => $noun,
        ]));
    }

    public static function changed(string $noun): self
    {
        return new self(EditLockState::Changed, __("Someone else opened or changed this :noun after you did. Reload to see the latest version — your unsaved changes can't be saved.", [
            'noun' => $noun,
        ]));
    }
}
