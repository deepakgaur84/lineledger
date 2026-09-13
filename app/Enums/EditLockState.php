<?php

namespace App\Enums;

/**
 * Where an open edit page stands with its record's edit lock.
 */
enum EditLockState: string
{
    /** This page holds the lock and may save. */
    case Held = 'held';

    /** Still ours, but renewal stopped after a stretch with no activity. */
    case Idle = 'idle';

    /** Another member now holds the lock (took it over, or it lapsed and they opened it). */
    case HeldBy = 'held_by';

    /** The same user holds it from another tab or window. */
    case Elsewhere = 'elsewhere';

    /** The record may have changed since this page loaded it; reload before saving. */
    case Changed = 'changed';

    public function isLost(): bool
    {
        return match ($this) {
            self::HeldBy, self::Elsewhere, self::Changed => true,
            self::Held, self::Idle => false,
        };
    }
}
