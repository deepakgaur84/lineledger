<?php

namespace App\Enums;

/**
 * Whether a donation is cash or a gift-in-kind. Cash receipts are record-only
 * (the money is booked elsewhere); a stand-alone in-kind receipt posts the gift
 * to the GL at fair market value, but one spawned from a donation never does —
 * the donation already booked it.
 */
enum GiftType: string
{
    case Cash = 'cash';
    case InKind = 'in_kind';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::InKind => 'Gift in kind',
        };
    }
}
