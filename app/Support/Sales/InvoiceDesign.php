<?php

namespace App\Support\Sales;

use App\Enums\InvoiceFont;
use App\Enums\InvoiceLayout;
use App\Enums\LogoPosition;
use App\Models\FormStyle;

/**
 * The resolved look of one printed invoice: a FormStyle's raw settings with
 * defaults filled in and the colours the stylesheets need already worked out
 * (a pale tint for table headers, a readable text colour for anything printed
 * on the accent). Colours are plain 6-digit hex because dompdf has no rgba().
 */
final readonly class InvoiceDesign
{
    public function __construct(
        public InvoiceLayout $layout,
        public ?string $accent,
        public string $accentTint,
        public string $accentSoft,
        public string $onAccent,
        public string $accentText,
        public InvoiceFont $font,
        public ?string $titleText,
        public LogoPosition $logoPosition,
        public ?int $logoMaxHeight,
        public bool $showShipTo,
        public bool $zebraRows,
    ) {}

    public static function for(?FormStyle $style): self
    {
        $layout = $style?->layout ?? InvoiceLayout::default();
        $accent = self::validHex($style?->accent_color) ?? $layout->defaultAccent();
        $title = $style?->title_text;

        return new self(
            layout: $layout,
            accent: $accent,
            accentTint: $accent ? self::mixWithWhite($accent, 0.10) : '#f3f4f6',
            accentSoft: $accent ? self::mixWithWhite($accent, 0.04) : '#f9fafb',
            onAccent: $accent ? self::readableOn($accent) : '#1f2937',
            accentText: $accent ? self::legibleOnWhite($accent) : '#1f2937',
            font: $style?->font_family ?? InvoiceFont::default(),
            titleText: filled($title) ? trim((string) $title) : null,
            logoPosition: $style?->logo_position ?? LogoPosition::default(),
            logoMaxHeight: $style?->logo_max_height ? (int) $style->logo_max_height : null,
            showShipTo: $style?->show_ship_to ?? true,
            zebraRows: $style?->zebra_rows ?? false,
        );
    }

    /** The document title: the style's own wording (e.g. "TAX INVOICE"), else "INVOICE". */
    public function title(): string
    {
        return $this->titleText ?? __('INVOICE');
    }

    public static function validHex(?string $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : null;
    }

    /** $weight of the colour, the rest white — 0.10 is a 90 % white tint. */
    public static function mixWithWhite(string $hex, float $weight): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return sprintf(
            '#%02x%02x%02x',
            (int) round($r * $weight + 255 * (1 - $weight)),
            (int) round($g * $weight + 255 * (1 - $weight)),
            (int) round($b * $weight + 255 * (1 - $weight)),
        );
    }

    /** True for titles like "TAX INVOICE" that need a smaller size to stay inside the header cell. */
    public function longTitle(): bool
    {
        return mb_strlen($this->title()) > 10;
    }

    /**
     * The accent, darkened just enough to read as text on white (WCAG AA, 4.5:1).
     * A pale yellow accent is fine as a background but unreadable as a label.
     */
    public static function legibleOnWhite(string $hex): string
    {
        for ($i = 0; $i < 12 && self::contrastWithWhite($hex) < 4.5; $i++) {
            [$r, $g, $b] = self::rgb($hex);
            $hex = sprintf('#%02x%02x%02x', (int) ($r * 0.85), (int) ($g * 0.85), (int) ($b * 0.85));
        }

        return $hex;
    }

    private static function contrastWithWhite(string $hex): float
    {
        return 1.05 / (self::luminance($hex) + 0.05);
    }

    /** White on dark accents, near-black on light ones (WCAG relative luminance). */
    public static function readableOn(string $hex): string
    {
        return self::luminance($hex) > 0.5 ? '#111827' : '#ffffff';
    }

    /** WCAG relative luminance, 0 (black) to 1 (white). */
    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }
}
