<?php

namespace App\Actions\Sales;

use App\Models\FormStyle;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a sales-form style (invoice template overrides).
 *
 * Invariant: at most one default per company, and the company's first active
 * style always becomes the default — so any company with active styles has
 * exactly one default.
 *
 * Expected $data shape:
 *   name:            string
 *   layout:          ?string (App\Enums\InvoiceLayout value)
 *   show_logo:       ?bool
 *   logo_position:   ?string (App\Enums\LogoPosition value)
 *   logo_max_height: ?int    (px)
 *   accent_color:    ?string (#rrggbb)
 *   font_family:     ?string (App\Enums\InvoiceFont value)
 *   title_text:      ?string
 *   show_ship_to:    ?bool
 *   zebra_rows:      ?bool
 *   footer_message:  ?string
 *   is_default:     ?bool
 *   is_active:      ?bool
 */
final class SaveFormStyle
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?FormStyle $style = null): FormStyle
    {
        return DB::transaction(function () use ($data, $style): FormStyle {
            $attributes = [
                'name' => $data['name'],
            ];

            foreach (['layout', 'logo_position', 'font_family'] as $enumColumn) {
                if (array_key_exists($enumColumn, $data) && filled($data[$enumColumn])) {
                    $attributes[$enumColumn] = $data[$enumColumn];
                }
            }

            foreach (['show_logo', 'show_ship_to', 'zebra_rows'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $attributes[$flag] = (bool) $data[$flag];
                }
            }

            if (array_key_exists('logo_max_height', $data)) {
                $attributes['logo_max_height'] = $data['logo_max_height'] ? (int) $data['logo_max_height'] : null;
            }

            if (array_key_exists('title_text', $data)) {
                $attributes['title_text'] = filled($data['title_text']) ? trim((string) $data['title_text']) : null;
            }

            if (array_key_exists('accent_color', $data)) {
                $attributes['accent_color'] = $data['accent_color'] ?: null;
            }

            if (array_key_exists('footer_message', $data)) {
                $attributes['footer_message'] = $data['footer_message'] ?: null;
            }

            if (array_key_exists('is_default', $data)) {
                $attributes['is_default'] = (bool) $data['is_default'];
            }

            if (array_key_exists('is_active', $data)) {
                $attributes['is_active'] = (bool) $data['is_active'];
            }

            if ($style && $style->exists) {
                $style->update($attributes);
            } else {
                $style = FormStyle::create($attributes + [
                    'show_logo' => $data['show_logo'] ?? true,
                    'is_default' => $data['is_default'] ?? false,
                    'is_active' => $data['is_active'] ?? true,
                ]);
            }

            // The company's first (or only remaining) active style is forced to
            // be the default so PDF rendering always has one to fall back on.
            $hasOtherDefault = FormStyle::query()
                ->whereKeyNot($style->id)
                ->where('is_default', true)
                ->where('is_active', true)
                ->exists();

            if (! $style->is_default && ! $hasOtherDefault && $style->is_active) {
                $style->update(['is_default' => true]);
            }

            // Single-default invariant: a new default clears all siblings.
            if ($style->is_default) {
                FormStyle::query()->whereKeyNot($style->id)->where('is_default', true)->update(['is_default' => false]);
            }

            return $style->refresh();
        });
    }
}
