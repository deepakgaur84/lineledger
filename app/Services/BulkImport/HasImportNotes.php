<?php

namespace App\Services\BulkImport;

/**
 * Optional, for an importer whose rules are easy to get wrong from the column
 * list alone: a few plain sentences the import screen shows above the columns,
 * before anything is uploaded. It is a separate interface rather than a method
 * on ImporterDefinition so adding it never touches the importers that don't
 * need it — the screen simply checks for it.
 */
interface HasImportNotes
{
    /**
     * One short, self-contained sentence per rule. Write them from what the
     * importer actually enforces, so a note can never promise something the
     * validator then refuses.
     *
     * @return array<int, string>
     */
    public function importNotes(): array;
}
