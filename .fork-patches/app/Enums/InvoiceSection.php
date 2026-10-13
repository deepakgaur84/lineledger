<?php

namespace App\Enums;

/**
 * The movable blocks of a designed invoice, below the header. A form style
 * stores them as an ordered list with a visibility flag each; blocks it doesn't
 * mention (e.g. added in a later release) are appended, visible.
 *
 * The header (logo, company, title, reference details) always comes first and
 * is not a section. The Classic layout has its own fixed structure and ignores
 * sections.
 */
enum InvoiceSection: string
{
    case Parties = 'parties';
    case Lines = 'lines';
    case Totals = 'totals';
    case Schedule = 'schedule';
    case Payment = 'payment';
    case Footer = 'footer';

    public function label(): string
    {
        return match ($this) {
            self::Parties => 'Bill to / Ship to',
            self::Lines => 'Line items',
            self::Totals => 'Totals',
            self::Schedule => 'Payment schedule',
            self::Payment => 'Payment details',
            self::Footer => 'Notes and footer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Parties => 'Customer billing and shipping addresses.',
            self::Lines => 'The table of items, quantities and amounts.',
            self::Totals => 'Subtotal, tax, total and balance due.',
            self::Schedule => 'Instalments, when the invoice has a payment schedule.',
            self::Payment => 'The payment instructions from invoice settings.',
            self::Footer => 'Tax number, customer message and footer message.',
        };
    }

    /**
     * The full ordered list for any stored value: unknown or repeated keys are
     * dropped, missing sections are appended (visible).
     *
     * @return list<array{key: string, visible: bool}>
     */
    public static function normalize(mixed $stored): array
    {
        $result = [];
        $seen = [];

        foreach (is_array($stored) ? $stored : [] as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;
            $section = is_string($key) ? self::tryFrom($key) : null;

            if ($section === null || isset($seen[$section->value])) {
                continue;
            }

            $seen[$section->value] = true;
            $result[] = ['key' => $section->value, 'visible' => (bool) ($entry['visible'] ?? true)];
        }

        foreach (self::cases() as $section) {
            if (! isset($seen[$section->value])) {
                $result[] = ['key' => $section->value, 'visible' => true];
            }
        }

        return $result;
    }

    /**
     * Parse the compact "parties:1,lines:0" form used by the preview URL.
     *
     * @return list<array{key: string, visible: bool}>
     */
    public static function parse(?string $compact): array
    {
        $entries = [];

        foreach (explode(',', (string) $compact) as $part) {
            [$key, $flag] = array_pad(explode(':', trim($part), 2), 2, '1');
            $entries[] = ['key' => $key, 'visible' => $flag !== '0'];
        }

        return self::normalize($entries);
    }

    /**
     * Only the visible sections, in order.
     *
     * @return list<self>
     */
    public static function visibleOrder(mixed $stored): array
    {
        $visible = [];

        foreach (self::normalize($stored) as $entry) {
            if ($entry['visible']) {
                $visible[] = self::from($entry['key']);
            }
        }

        return $visible;
    }
}
