<?php

namespace App\Actions\Accounting;

use App\Exceptions\Posting\LinkedJournalEntryException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Currency\ExchangeRateService;
use App\Services\Currency\MissingExchangeRateException;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use App\Support\Accounting\ForeignJournalLines;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds or rebuilds a journal entry header and its lines. Shared by the
 * Livewire journal form, the API, the MCP write tools and the generators
 * (recurring entries, depreciation, …). Does NOT post — balance enforcement,
 * period locking and the draft → posted flip stay in JournalPoster.
 *
 * Lines with nothing on them (no home and no foreign amount) are dropped,
 * matching the form.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   entry_no:   ?string  (null → auto-generated JE-xxxxxx)
 *   entry_date: string
 *   memo:       ?string
 *   lines: array<int, array{
 *     account_id: int, debit_cents?: int, credit_cents?: int,
 *     memo: ?string, contact_id: ?int, tax_code_id: ?int,
 *     class_id: ?int, location_id: ?int, fund_id: ?int,
 *     currency_code?: ?string, fx_rate?: ?string,
 *     foreign_debit_cents?: ?int, foreign_credit_cents?: ?int
 *   }>
 *
 * tax_code_id is a reporting tag only — no tax amounts are derived from it.
 *
 * debit_cents / credit_cents are HOME cents, and the entry balances in home. A
 * line on a foreign-currency account (foreign bank or credit card, per-currency
 * AR/AP) also records its foreign amount, rate and currency — always the
 * account's own — and any two of home, foreign and rate give the third; a
 * missing rate is the one in force on the entry date. See
 * {@see ForeignJournalLines}. So a caller that only knows the home amount still
 * leaves foreign units on the account, and the period-end revaluation only ever
 * revalues real foreign exposure. Nothing is worked out for a line on a
 * home-currency account; it keeps a memo only when one comes complete (the kind
 * a foreign document puts on every leg, copied by a reversal). Anything
 * inconsistent is a ValidationException keyed to the line field
 * (lines.N.fx_rate, …).
 *
 * Every line must be on one of the current organization's accounts, checked
 * here as well as at posting, because this also rewrites a posted entry in place.
 */
final class SaveJournalEntry
{
    public function __construct(
        protected EntryNumberGenerator $numbers,
        protected JournalPoster $poster,
        protected ExchangeRateService $exchangeRates,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data, ?JournalEntry $entry = null): JournalEntry
    {
        return DB::transaction(function () use ($data, $entry): JournalEntry {
            $company = app('current_company');
            $entryDate = CarbonImmutable::parse($data['entry_date'])->toDateString();
            $lines = array_values($data['lines']);
            $amounts = $this->resolveLines($lines, $entryDate);

            $header = [
                'entry_date' => $entryDate,
                'memo' => $data['memo'] ?? null,
            ];

            if ($entry && $entry->exists) {
                if ($entry->source_type !== null) {
                    throw LinkedJournalEntryException::for($entry);
                }
                if (! empty($data['entry_no'])) {
                    $header['entry_no'] = $data['entry_no'];
                }
                $entry->update($header);
            } else {
                $entry = JournalEntry::create($header + [
                    'entry_no' => $data['entry_no'] ?? $this->numbers->next($company),
                ]);
            }

            $entry->lines()->delete();

            $order = 0;
            foreach ($lines as $i => $line) {
                $money = $amounts[$i];

                if ($money['debit_cents'] === 0 && $money['credit_cents'] === 0
                    && $money['foreign_debit_cents'] === 0 && $money['foreign_credit_cents'] === 0) {
                    continue;
                }

                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'contact_id' => $this->optionalId($line['contact_id'] ?? null),
                    'debit_cents' => $debit,
                    'credit_cents' => $credit,
                    'memo' => $line['memo'] ?? null,
                    'tax_code_id' => $this->optionalId($line['tax_code_id'] ?? null),
                    'line_order' => $order++,
                    'class_id' => $this->optionalId($line['class_id'] ?? null),
                    'location_id' => $this->optionalId($line['location_id'] ?? null),
                    'fund_id' => $this->optionalId($line['fund_id'] ?? null),
                    ...($currencyCode !== null ? [
                        'currency_code' => $currencyCode,
                        'fx_rate' => $line['fx_rate'] ?? null,
                        'foreign_debit_cents' => (int) ($line['foreign_debit_cents'] ?? 0),
                        'foreign_credit_cents' => (int) ($line['foreign_credit_cents'] ?? 0),
                    ] : []),
                ]);
            }

            $this->poster->ensureLineAccountsInCompany($entry);

            $entry->refresh();

            return $entry;
        });
    }

    /**
     * Normalize an optional foreign key: '' / null / 0 → null, anything else → int.
     *
     * A blank Class / Location / Fund / Tax code select ("—") submits an empty
     * string, and `?? null` only catches a missing value, not that. MySQL in
     * strict mode refuses '' in an integer column ("Incorrect integer value: ''
     * for column 'class_id'"), so the whole save — draft or post — failed with a
     * 500. Validation lets it through ('nullable|integer' skips an empty string).
     * SaveJournalEntryTemplate already does exactly this; this is the same fix.
     */
    private function optionalId(mixed $value): ?int
    {
        return filled($value) && (int) $value > 0 ? (int) $value : null;
    }
}
