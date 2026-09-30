<?php

namespace App\Services\BulkImport\Importers;

use App\Actions\Accounting\SaveJournalEntry;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TaxCode;
use App\Services\BulkImport\GroupedImporterDefinition;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Journal Entries — one row, one line; rows sharing the same import_ref
 * become one entry's lines, through the real SaveJournalEntry action and
 * JournalPoster, so an imported entry gets identical validation, balance
 * enforcement and period-lock handling to one entered by hand.
 *
 * Deliberately NOT for a full-history GL replay from another system:
 * Migration's own GeneralLedgerReplayImporter already exists for that (a
 * QuickBooks-specific tool posting raw, already-balanced entries from a QB
 * Journal report) and is a fundamentally different job — this importer is
 * for adding new journal entries to an already-running company, the same
 * shape as typing them in on the journal entry form.
 *
 * A row's debit and credit are both accepted as plain decimals, and exactly
 * one of the two must be non-zero on any given row — a line is either a
 * debit or a credit, never both, and a blank/zero row is rejected rather
 * than silently dropped (SaveJournalEntry itself drops a zero/zero line, but
 * a CSV row is presumably there on purpose, so a genuinely empty one is
 * treated as a mistake worth flagging, not silently ignored).
 */
class JournalEntryImporter implements GroupedImporterDefinition
{
    public function key(): string
    {
        return 'journal-entries';
    }

    public function label(): string
    {
        return 'Journal Entries';
    }

    public function csvColumns(): array
    {
        return [
            'import_ref' => 'Required. Groups rows into one journal entry — every line of the same entry repeats the same import_ref. Only used to group rows in this file; never stored, and unrelated to entry_no.',
            'entry_no' => 'Optional. Left blank, the entry is numbered automatically the same way one entered by hand would be. Given, must be unique — an entry_no already in use is rejected.',
            'entry_date' => 'Required. Any unambiguous date works, e.g. 15-Jan-2026 or 2026-01-15. Same on every line of an entry — only the first line\'s value is used.',
            'memo' => 'Optional. The entry\'s own memo. Same on every line of an entry — only the first line\'s value is used.',
            'account_code' => 'Required per row. The code of an existing account, e.g. 6100 — not the account name.',
            'debit' => 'Required per row unless credit is given. Plain decimal, e.g. 500.00 — not cents. Exactly one of debit or credit must be given per row, never both, and never neither.',
            'credit' => 'Required per row unless debit is given. Plain decimal.',
            'line_memo' => 'Optional. This specific line\'s own memo, separate from the entry\'s memo above.',
            'contact_name' => "Optional. An existing vendor or customer's display name (case-insensitive). If more than one contact shares this name, the row is rejected rather than guessing which one.",
            'tax_code' => 'Optional. Must match an existing tax code by its code (see Settings > Tax Codes). A reporting tag only — no tax amount is calculated or added from it.',
        ];
    }

    public function groupKey(array $row): ?string
    {
        $key = trim((string) ($row['import_ref'] ?? ''));

        return $key !== '' ? $key : null;
    }

    public function validateGroup(array $rows, Company $company): array
    {
        $errors = [];
        $first = $rows[0];

        $header = [
            'entry_no' => $first['entry_no'] ?? null,
            'entry_date' => $first['entry_date'] ?? null,
        ];

        $validator = Validator::make($header, [
            'entry_no' => [
                'nullable', 'string', 'max:40',
                Rule::unique('journal_entries', 'entry_no')->where('company_id', $company->id),
            ],
            'entry_date' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            $errors = array_merge($errors, $validator->errors()->all());
        }

        $totalDebits = 0.0;
        $totalCredits = 0.0;

        foreach ($rows as $i => $line) {
            $lineNum = $i + 1;

            $lineValidator = Validator::make($line, [
                'account_code' => [
                    'required', 'string',
                    Rule::exists('accounts', 'code')->where('company_id', $company->id),
                ],
                'debit' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
                'credit' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
                'contact_name' => ['nullable', 'string'],
                'tax_code' => [
                    'nullable', 'string',
                    Rule::exists('tax_codes', 'code')->where('company_id', $company->id),
                ],
            ]);

            if ($lineValidator->fails()) {
                foreach ($lineValidator->errors()->all() as $message) {
                    $errors[] = __('Line :n: :message', ['n' => $lineNum, 'message' => $message]);
                }

                continue;
            }

            $debit = is_numeric($line['debit'] ?? null) ? (float) $line['debit'] : 0.0;
            $credit = is_numeric($line['credit'] ?? null) ? (float) $line['credit'] : 0.0;

            if ($debit > 0 && $credit > 0) {
                $errors[] = __('Line :n: cannot have both a debit and a credit — a line is either one or the other.', ['n' => $lineNum]);
            } elseif ($debit <= 0 && $credit <= 0) {
                $errors[] = __('Line :n: needs a debit or a credit greater than zero.', ['n' => $lineNum]);
            }

            $totalDebits += $debit;
            $totalCredits += $credit;

            if (filled($line['contact_name'] ?? null)) {
                $this->resolveContact((string) $line['contact_name'], $company, $lineNum, $errors);
            }
        }

        // Compared in cents, not float dollars, to avoid a false imbalance from
        // ordinary floating-point rounding on a large file.
        if (round($totalDebits * 100) !== round($totalCredits * 100)) {
            $errors[] = __('This entry does not balance: total debits :debits, total credits :credits.', [
                'debits' => number_format($totalDebits, 2),
                'credits' => number_format($totalCredits, 2),
            ]);
        }

        return $errors;
    }

    public function summarizeGroup(array $rows, Company $company): array
    {
        $first = $rows[0];
        $total = array_reduce($rows, function (float $carry, array $line): float {
            return $carry + (float) ($line['debit'] ?? 0);
        }, 0.0);

        return [
            'Entry #' => filled($first['entry_no'] ?? null) ? (string) $first['entry_no'] : __('(auto-numbered)'),
            'Date' => (string) ($first['entry_date'] ?? ''),
            'Lines' => (string) count($rows),
            'Total debits' => number_format($total, 2),
        ];
    }

    public function commitGroup(array $rows, Company $company): void
    {
        $first = $rows[0];

        $lines = [];
        foreach ($rows as $line) {
            $accountId = Account::query()
                ->where('company_id', $company->id)
                ->where('code', $line['account_code'])
                ->value('id');

            $contactId = filled($line['contact_name'] ?? null)
                ? Contact::query()
                    ->where('company_id', $company->id)
                    ->whereRaw('LOWER(display_name) = ?', [mb_strtolower(trim((string) $line['contact_name']))])
                    ->value('id')
                : null;

            $taxCodeId = filled($line['tax_code'] ?? null)
                ? TaxCode::query()->where('company_id', $company->id)->where('code', $line['tax_code'])->value('id')
                : null;

            $debitCents = is_numeric($line['debit'] ?? null) ? (int) round(((float) $line['debit']) * 100) : 0;
            $creditCents = is_numeric($line['credit'] ?? null) ? (int) round(((float) $line['credit']) * 100) : 0;

            $lines[] = [
                'account_id' => $accountId,
                'debit_cents' => $debitCents,
                'credit_cents' => $creditCents,
                'memo' => $line['line_memo'] ?? null,
                'contact_id' => $contactId,
                'tax_code_id' => $taxCodeId,
            ];
        }

        $entry = app(SaveJournalEntry::class)->handle([
            'entry_no' => filled($first['entry_no'] ?? null) ? $first['entry_no'] : null,
            'entry_date' => CarbonImmutable::parse($first['entry_date'])->toDateString(),
            'memo' => $first['memo'] ?? null,
            'lines' => $lines,
        ]);

        // A posting failure (e.g. a locked period) still leaves a valid,
        // reviewable draft behind — reported as a distinct message rather
        // than losing the entry or masking it as a plain save failure. A
        // genuine imbalance is already caught in validateGroup() above, so
        // reaching post() with one here would mean the row data itself
        // changed between preview and commit — still handled the same way,
        // not assumed impossible.
        try {
            app(JournalPoster::class)->post($entry);
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('Entry :no was saved as a draft but could not be posted: :error', [
                'no' => $entry->entry_no,
                'error' => $e->getMessage(),
            ]), previous: $e);
        }
    }

    public function sampleRows(): array
    {
        return [
            [
                'import_ref' => '1',
                'entry_no' => 'JE-9001',
                'entry_date' => '15-Jan-2026',
                'memo' => 'Record monthly rent accrual',
                'account_code' => '6200',
                'debit' => '2000.00',
                'credit' => '',
                'line_memo' => '',
                'contact_name' => '',
                'tax_code' => '',
            ],
            [
                'import_ref' => '1',
                'entry_no' => 'JE-9001',
                'entry_date' => '15-Jan-2026',
                'memo' => '',
                'account_code' => '2100',
                'debit' => '',
                'credit' => '2000.00',
                'line_memo' => '',
                'contact_name' => '',
                'tax_code' => '',
            ],
            [
                // entry_no left blank here on purpose — this entry is numbered
                // automatically instead, same as one entered by hand.
                'import_ref' => '2',
                'entry_no' => '',
                'entry_date' => '16-Jan-2026',
                'memo' => 'Owner contribution',
                'account_code' => '1000',
                'debit' => '500.00',
                'credit' => '',
                'line_memo' => '',
                'contact_name' => '',
                'tax_code' => '',
            ],
            [
                'import_ref' => '2',
                'entry_no' => '',
                'entry_date' => '16-Jan-2026',
                'memo' => '',
                'account_code' => '3000',
                'debit' => '',
                'credit' => '500.00',
                'line_memo' => '',
                'contact_name' => '',
                'tax_code' => '',
            ],
        ];
    }

    /**
     * Requires exactly one match, mirroring BillImporter's own resolveVendor()
     * (and FindContactTool's ambiguity handling) — refuses to guess between
     * two contacts sharing a name rather than silently picking one. Unlike
     * the AP/AR importers, this checks every contact regardless of
     * vendor/customer role, since a journal line's contact can be either.
     *
     * No @param on $errors here — matching every other importer's own
     * resolve*() method (e.g. BillImporter::resolveVendor()), none of which
     * type-hint it either. A list<string> hint here previously conflicted
     * with __() being typed string|array by Larastan, the exact same
     * PHPStan error FixedAssetImporter::accountId() hit earlier tonight and
     * was fixed the same way, by removing the hint rather than casting
     * every __() call.
     */
    private function resolveContact(string $name, Company $company, int $lineNum, array &$errors): void
    {
        $name = trim($name);

        $matches = Contact::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($name)])
            ->get();

        if ($matches->isEmpty()) {
            $errors[] = __("Line :n: contact ':name' not found.", ['n' => $lineNum, 'name' => $name]);
        } elseif ($matches->count() > 1) {
            $errors[] = __("Line :n: more than one contact is named ':name' — rename one of them, or use a different name, before importing.", ['n' => $lineNum, 'name' => $name]);
        }
    }
}
