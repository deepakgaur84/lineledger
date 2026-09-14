<?php

namespace App\Services\BulkImport;

use App\Models\Company;

/**
 * Contract for one entity type in the standalone bulk-import tool
 * (Tools > Bulk Import — resources/views/pages/tools/⚡bulk-import.blade.php).
 *
 * Deliberately separate from app/Services/Migration/Importers/ (the
 * one-time QuickBooks migration wizard): that system calls Contact::create()
 * directly, bypassing SaveContact — meaning it has no currency_code support
 * at all, and extending it would mean either missing currency handling here
 * or modifying shared code the migration wizard also depends on. Every
 * importer here instead calls the real Save* action a form's own UI/API
 * uses, so a bulk-imported record gets exactly the same validation and
 * business rules (including currency handling) as one entered by hand.
 *
 * To add a new entity type: implement this interface, call the real
 * Save or Store action in commit(), and register the class in
 * BulkImportRegistry::all().
 */
interface ImporterDefinition
{
    /** Stable key used in the entity-type selector, e.g. 'vendors'. */
    public function key(): string;

    /** Display label for the entity-type selector, e.g. 'Vendors'. */
    public function label(): string;

    /**
     * Expected CSV column names, in the order they should appear, mapped to
     * a short human description for the on-page help text / template.
     *
     * @return array<string, string>
     */
    public function csvColumns(): array;

    /**
     * Validate one CSV row (already trimmed, keyed by csvColumns()).
     * Returns a list of human-readable error messages — empty means valid.
     * Should reuse the same rules the real Store*Request declares, not
     * reimplement them, so a row that would be rejected by the UI/API is
     * also rejected here, and vice versa.
     *
     * @param  array<string, ?string>  $row
     * @return array<int, string>
     */
    public function validate(array $row, Company $company): array;

    /**
     * A handful of key facts about this row for the preview table — not
     * every field, just enough to recognise the record, confirm the
     * important decisions (e.g. which currency will actually be applied),
     * and flag anything worth a second look before committing (e.g. a
     * likely duplicate of an existing record — the app itself enforces no
     * uniqueness on contact names/account numbers, so this is the only
     * place that catches an accidental double-import).
     *
     * @param  array<string, ?string>  $row
     * @return array<string, string>
     */
    public function summarize(array $row, Company $company): array;

    /**
     * Create the record. Only ever called for rows that already passed
     * validate() — implementations should not need to re-validate.
     *
     * @param  array<string, ?string>  $row
     */
    public function commit(array $row, Company $company): void;
}
