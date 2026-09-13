<?php

namespace App\Services\EditLocks;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Who currently holds a record's edit lock, for "Jane Doe is editing…" copy.
 */
final readonly class EditLockHolder
{
    public function __construct(
        public ?User $user,
        public CarbonImmutable $acquiredAt,
        public CarbonImmutable $lastActiveAt,
        public CarbonImmutable $expiresAt,
    ) {}

    public function name(): string
    {
        return $this->user->name ?? __('Someone');
    }
}
