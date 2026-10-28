<?php

namespace App\Enums;

/**
 * The invoice PDF layouts a form style can choose from. Classic is the original
 * layout (pdf.invoices.invoice) and Airy is upstream's own (pdf.invoices.airy);
 * every other case renders through the shared pdf.invoices.designed view with
 * its own stylesheet partial.
 */
enum InvoiceLayout: string
{
    case Classic = 'classic';
    case Modern = 'modern';
    case Minimal = 'minimal';
    case Banner = 'banner';
    case Ledger = 'ledger';
    case Elegant = 'elegant';
    case Airy = 'airy';

    public static function default(): self
    {
        return self::Classic;
    }

    public function label(): string
    {
        return match ($this) {
            self::Classic => 'Classic',
            self::Modern => 'Modern',
            self::Minimal => 'Minimal',
            self::Banner => 'Banner',
            self::Ledger => 'Ledger',
            self::Elegant => 'Elegant',
            self::Airy => 'Airy',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Classic => 'The standard boxed layout.',
            self::Modern => 'Large coloured title, clean lines, soft table header.',
            self::Minimal => 'Black and white, hairline rules, lots of space.',
            self::Banner => 'Full-width colour band with the title in white.',
            self::Ledger => 'Traditional bordered grid with striped rows.',
            self::Elegant => 'Centred serif header with a double rule.',
            self::Airy => 'Open layout, logo top right, balance due.',
        };
    }

    /** Accent used when the style doesn't set one; null keeps the neutral grey look. */
    public function defaultAccent(): ?string
    {
        return match ($this) {
            self::Classic, self::Airy => null,
            self::Modern => '#2563eb',
            self::Minimal => '#111827',
            self::Banner => '#0f766e',
            self::Ledger => '#1f2937',
            self::Elegant => '#7c2d12',
        };
    }

    public function view(): string
    {
        return match ($this) {
            self::Classic => 'pdf.invoices.invoice',
            self::Airy => 'pdf.invoices.airy',
            default => 'pdf.invoices.designed',
        };
    }
}
