<?php

namespace App\Enums;

/**
 * Typefaces available on printed invoices. Only fonts bundled with dompdf that
 * cover the full Latin range (so names and currency symbols never print as "?").
 */
enum InvoiceFont: string
{
    case Sans = 'sans';
    case Serif = 'serif';
    case Mono = 'mono';

    public static function default(): self
    {
        return self::Sans;
    }

    public function label(): string
    {
        return match ($this) {
            self::Sans => 'Sans-serif',
            self::Serif => 'Serif',
            self::Mono => 'Monospaced',
        };
    }

    public function stack(): string
    {
        return match ($this) {
            self::Sans => 'DejaVu Sans, sans-serif',
            self::Serif => 'DejaVu Serif, serif',
            self::Mono => 'DejaVu Sans Mono, monospace',
        };
    }
}
