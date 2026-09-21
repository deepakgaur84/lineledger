<?php

namespace App\Services\BulkImport;

use App\Models\Company;

/**
 * Contract for entity types where one CSV row is a single LINE of a
 * multi-line document (a bill, an invoice) rather than a whole record by
 * itself — rows sharing the same groupKey() become one document with
 * multiple lines.
 *
 * Deliberately NOT an extension of ImporterDefinition, even though it
 * shares key()/label()/csvColumns() with it verbatim: extending it would
 * also inherit validate()/summarize()/commit(), which operate on a single
 * row and have no sensible meaning for a multi-line document — a class
 * couldn't satisfy this interface without also being forced to implement
 * three methods it can never correctly use. This was a real bug caught in
 * production: BillImporter implemented only the grouped methods below,
 * which is exactly right for how it's actually used, but PHP still fatally
 * refused to load the class because the old, wrongly-extended interface
 * demanded the row-level methods too.
 *
 * The Bulk Import page checks for this interface (instanceof) and switches
 * its preview/commit logic to operate per-group instead of per-row; a plain
 * ImporterDefinition (Vendors, Items, Item Categories, Customers) is
 * completely unaffected by any of this.
 */
interface GroupedImporterDefinition
{
    /** Stable key used in the entity-type selector, e.g. 'bills'. */
    public function key(): string;

    /** Display label for the entity-type selector, e.g. 'Bills'. */
    public function label(): string;

    /**
     * Expected CSV column names, in the order they should appear, mapped to
     * a short human description for the on-page help text / template.
     *
     * @return array<string, string>
     */
    public function csvColumns(): array;

    /**
     * The value that groups rows into one document — e.g. the bill number.
     * Rows sharing the same non-null, non-empty key become one document's
     * lines. A row returning null/empty is treated as its own singleton
     * group by the caller (not silently dropped), so a missing reference
     * still surfaces as a clearly attributable validation error.
     *
     * @param  array<string, ?string>  $row
     */
    public function groupKey(array $row): ?string;

    /**
     * Validate one whole document — every row sharing a groupKey(), in
     * file order. Returns a list of human-readable error messages — empty
     * means valid.
     *
     * @param  array<int, array<string, ?string>>  $rows
     * @return array<int, string>
     */
    public function validateGroup(array $rows, Company $company): array;

    /**
     * A handful of key facts about the whole document for the preview
     * table — the header fields (vendor, date, total) rather than every
     * line's own detail.
     *
     * @param  array<int, array<string, ?string>>  $rows
     * @return array<string, string>
     */
    public function summarizeGroup(array $rows, Company $company): array;

    /**
     * Create the document and all its lines. Only ever called for groups
     * that already passed validateGroup() — implementations should not
     * need to re-validate.
     *
     * @param  array<int, array<string, ?string>>  $rows
     */
    public function commitGroup(array $rows, Company $company): void;

    /**
     * A couple of realistic sample rows for the downloadable template,
     * demonstrating the shared-groupKey / multiple-rows-per-document
     * convention — a flat importer's template has no sample data since the
     * convention there is self-evident (one row, one record), but a
     * grouped importer's shape isn't guessable from column names alone.
     * Column keys must match csvColumns()'s own keys.
     *
     * @return array<int, array<string, string>>
     */
    public function sampleRows(): array;
}
