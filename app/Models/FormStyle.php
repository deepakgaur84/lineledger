<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\InvoiceFont;
use App\Enums\InvoiceLayout;
use App\Enums\LogoPosition;
use Database\Factories\FormStyleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A named sales-form template. Per-field overrides for InvoiceSetting when
 * rendering an invoice PDF: layout template, typeface, logo placement and size,
 * accent colour, title wording, a few switches, the order and visibility of the
 * body sections, and the footer message.
 * One style per company may be the default. Invoices only for v1.
 */
#[Fillable(['company_id', 'name', 'layout', 'is_default', 'show_logo', 'logo_position', 'logo_max_height', 'accent_color', 'font_family', 'title_text', 'show_ship_to', 'zebra_rows', 'sections', 'footer_message', 'is_active'])]
class FormStyle extends Model
{
    /** @use HasFactory<FormStyleFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout' => InvoiceLayout::class,
            'font_family' => InvoiceFont::class,
            'logo_position' => LogoPosition::class,
            'logo_max_height' => 'integer',
            'show_ship_to' => 'boolean',
            'zebra_rows' => 'boolean',
            'sections' => 'array',
            'is_default' => 'boolean',
            'show_logo' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
