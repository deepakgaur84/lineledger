<?php

/*
 * Layout knobs for the voucher cheque PDF. All coordinates are points
 * (1 pt = 1/72"), top-left origin, and every `[x, y]` pair is the x of the
 * first glyph and the *text baseline* — not the top of the glyph box. The
 * renderer pins TCPDF to that baseline exactly, so these numbers are the ones
 * that come out of a ruler laid on the printed page.
 *
 * The numbers below are measured off an Intuit/QuickBooks voucher cheque
 * (standard letter, cheque on top + two stubs), so output drops onto Intuit
 * pre-printed stock without fiddling. The renderer draws the full cheque
 * (data + static labels) by default — print onto blank paper or use it as-is
 * for archive copies. For pre-printed stock, set `draw_static_labels` => false
 * so only data lands in the holes.
 */

return [
    /* Global drift, applied to every field. Tune per printer/tray. */
    'offset_x' => 0.0,
    'offset_y' => 0.0,

    /*
     * Draw the static labels (DATE, MEMO, the Y Y Y Y M M D D comb legend).
     * Set to false when printing onto pre-printed Intuit/QuickBooks stock that
     * already has them.
     */
    'draw_static_labels' => true,

    /*
     * Order of the eight date-comb digits, as a PHP date format built from Y,
     * m and d. Canadian cheques (Payments Canada Standard 006) print the
     * format under the comb, so the Y Y Y Y M M D D legend is derived from
     * this and the two can't disagree.
     */
    'date_comb_format' => 'Ymd',

    /*
     * Date comb digit pitch. Intuit sets the comb as one run of digits
     * separated by two spaces, so the pitch is a font metric, not a guess:
     * (556 + 2 x 278) / 1000 em x 10.02 pt = 11.142 pt.
     */
    'date_digit_pitch' => 11.142,

    /* All amount columns are right-aligned to this x. */
    'amount_right_edge' => 568.0,

    /* Voucher 2 = voucher 1 + this offset. */
    'voucher_band_pitch' => 252.0,

    /*
     * Stars printed in front of the amount on the PAY line
     * ("*****Five Hundred ..."), whatever the amount's length. They guard the
     * start of the line against an inserted word; they are not a fill to the
     * margin.
     */
    'amount_words_star_prefix' => 5,

    /*
     * Payee address block, drawn under the payee name. Intuit sets the address
     * in the same face as the payee and steps one em per line, which puts a
     * four-line worst case (a foreign payee — the country line is suppressed
     * domestically) clear of the MEMO baseline at 208.74.
     */
    'address_font_size' => 9.0,
    'address_line_height' => 9.0,
    'address_max_lines' => 4,

    /*
     * Detail rows on the vouchers step down by this much per row — the same
     * 18 pt rhythm that separates the payee band from the first detail row.
     */
    'voucher_line_height' => 18.0,

    /* Soft cap so detail rows don't overrun the summary band at 493.32. */
    'voucher_max_lines' => 10,

    /*
     * Intuit sets the cheque face in three sizes and the stubs in one. The
     * odd .98/.02 sizes are Intuit's, carried over verbatim — the point of
     * this file is to land on their grid, not on a rounder number.
     */
    'fonts' => [
        'family' => 'helvetica',   // Helvetica: same metrics as Intuit's ArialMT
        'size_body' => 10.02,      // amount lines on the face, everything on the stubs
        'size_date_comb' => 10.02, // the eight date digits
        'size_payee' => 9.0,       // payee + address block
        'size_label' => 7.98,      // DATE, MEMO — and the memo text itself
        'size_subscript' => 6.0,   // Y Y Y Y M M D D legend under the digits
    ],

    /* "DATE" label, relative to the first date digit. */
    'date_label_offset' => -31.98,
    'date_label_drop' => 0.42,

    /*
     * The comb legend under the digits. Intuit draws it as one run with four
     * spaces between letters, so the letters sit on their own (slightly
     * uneven) rhythm rather than centring under each digit — reproduced
     * literally. The letters come from `date_comb_format`.
     */
    'date_subscript_drop' => 9.9,
    'date_subscript_x_offset' => 1.02,

    /*
     * Field coordinates. Each entry is [x, baseline]. The renderer applies
     * offset_x / offset_y globally.
     */
    'fields' => [
        // CHEQUE BAND ----------------------------------------------------
        'cheque_date_first_digit' => [495.0, 82.32],
        'cheque_amount_words' => [72.0, 117.3],
        'cheque_amount_numeric' => [487.98, 117.3],
        'cheque_payee' => [72.0, 158.52],
        'cheque_payee_address' => [72.0, 167.52],
        'cheque_memo_label' => [19.98, 208.74],
        'cheque_memo' => [72.0, 208.74],

        // VOUCHER 1 (voucher 2 = same x, baseline + voucher_band_pitch) ---
        'voucher_payee' => [60.0, 283.32],
        'voucher_date' => [432.0, 283.32],
        'voucher_detail_first_row' => [43.98, 301.32],
        'voucher_detail_desc_x' => 234.0,
        'voucher_summary_account' => [36.0, 493.32],
        'voucher_summary_desc' => [144.0, 493.32],
        // Amount columns (right-aligned) all use `amount_right_edge` above.
    ],

    /*
     * Column widths on the vouchers. Text is hard-truncated to fit — no
     * ellipsis, mid-word, exactly as Intuit does it — so a long account name
     * or memo can't run into the column to its right. `summary_desc` is
     * measured: it's what cuts "…(preneed) policy" to "…(preneed) ".
     */
    'columns' => [
        'detail_account_width' => 186.0,
        'detail_desc_width' => 266.0,
        'summary_account_width' => 104.0,
        'summary_desc_width' => 213.0,
    ],
];
