<?php

namespace App\Services\EditLocks;

/**
 * The outcome of {@see EditLockManager::acquire()}.
 */
final readonly class EditLockResult
{
    private function __construct(
        public bool $acquired,
        public ?string $token = null,
        public ?string $version = null,
        public ?int $changedAtMs = null,
        public ?EditLockHolder $holder = null,
    ) {}

    public static function acquired(string $token, string $version, ?int $changedAtMs): self
    {
        return new self(acquired: true, token: $token, version: $version, changedAtMs: $changedAtMs);
    }

    public static function blocked(EditLockHolder $holder): self
    {
        return new self(acquired: false, holder: $holder);
    }

    /**
     * Locking is switched off (config) — callers carry on as if they hold it.
     */
    public static function disabled(): self
    {
        return new self(acquired: true);
    }
}
