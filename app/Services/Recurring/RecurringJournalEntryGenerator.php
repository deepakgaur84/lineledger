<?php

namespace App\Services\Recurring;

use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\RecurrenceEndType;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\RecurringJournalEntry;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Turns memorized journal-entry schedules into Draft journal entries by feeding the
 * existing SaveJournalEntry action. Drafts by default — a human reviews and posts
 * each one — unless the schedule has auto_post on (fork), in which case each entry is
 * posted straight away; a locked period leaves it as a draft.
 *
 * Mirrors {@see RecurringDocumentGenerator}: binds app('current_company') for the
 * action call (not bound in a job/command), and uses a row lock plus a re-read of
 * next_run_date inside the transaction for idempotency under concurrent workers.
 */
class RecurringJournalEntryGenerator
{
    /**
     * Hard cap on catch-up occurrences generated in a single run, guarding
     * against a pathological backdated start_date flooding the queue.
     */
    protected const MAX_CATCHUP = 60;

    public function __construct(protected NextRunDateCalculator $calculator) {}

    /**
     * Generate every occurrence due on or before $today (catching up if the
     * scheduler missed days), advancing the schedule and applying its end rule.
     *
     * @return Collection<int, JournalEntry>
     */
    public function generateDue(RecurringJournalEntry $schedule, CarbonImmutable $today): Collection
    {
        $created = collect();

        DB::transaction(function () use ($schedule, $today, $created): void {
            $row = $this->lockFresh($schedule);
            $company = Company::query()->findOrFail($row->company_id);

            $reason = $this->blockingReason($row);
            if ($reason !== null) {
                $this->pause($row, $reason);

                return;
            }

            $iterations = 0;

            while ($row->is_active
                && $row->next_run_date !== null
                && $row->next_run_date->lessThanOrEqualTo($today)) {
                if (++$iterations > self::MAX_CATCHUP) {
                    Log::warning('Recurring journal schedule hit catch-up cap.', [
                        'recurring_journal_entry_id' => $row->id,
                        'company_id' => $row->company_id,
                    ]);
                    break;
                }

                $created->push($this->generateStep(
                    $row,
                    $company,
                    CarbonImmutable::parse($row->next_run_date->toDateString()),
                ));

                if (! $row->is_active) {
                    break;
                }
            }
        });

        return $created;
    }

    /**
     * Manual "Generate now" from the schedule page: create every occurrence that is
     * already due, so a schedule started months ago catches up in one go (the same
     * as the nightly run, but now and with errors reported). When nothing is due
     * yet, create the next occurrence early, as {@see generateOne()} does. Throws
     * if the schedule is paused or references something that no longer exists.
     *
     * @return Collection<int, JournalEntry>
     */
    public function generateAllDue(RecurringJournalEntry $schedule): Collection
    {
        $row = RecurringJournalEntry::query()->withoutGlobalScopes()->with('lines')->findOrFail($schedule->getKey());
        $company = Company::query()->findOrFail($row->company_id);

        if (! $row->is_active) {
            throw new RuntimeException('This recurring schedule is paused or ended.');
        }

        $reason = $this->blockingReason($row);
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        $today = $company->currentDateTime()->startOfDay();

        if ($row->next_run_date === null || CarbonImmutable::parse($row->next_run_date)->greaterThan($today)) {
            return collect([$this->generateOne($schedule)]);
        }

        return $this->generateDue($schedule, $today);
    }

    /**
     * Generate a single occurrence immediately (manual "Generate now"), using the
     * scheduled next_run_date, or the company's today when none is set. Throws if
     * the schedule is inactive or references something that no longer exists.
     */
    public function generateOne(RecurringJournalEntry $schedule): JournalEntry
    {
        return DB::transaction(function () use ($schedule): JournalEntry {
            $row = $this->lockFresh($schedule);
            $company = Company::query()->findOrFail($row->company_id);

            if (! $row->is_active) {
                throw new RuntimeException('This recurring schedule is paused or ended.');
            }

            $reason = $this->blockingReason($row);
            if ($reason !== null) {
                throw new RuntimeException($reason);
            }

            $date = $row->next_run_date !== null
                ? CarbonImmutable::parse($row->next_run_date->toDateString())
                : $company->currentDateTime()->startOfDay();

            return $this->generateStep($row, $company, $date);
        });
    }

    /**
     * Create one draft for $date and advance the schedule one hop.
     */
    protected function generateStep(RecurringJournalEntry $row, Company $company, CarbonImmutable $date): JournalEntry
    {
        $created = $this->withCompany($company, fn (): JournalEntry => $this->createDraftFor($row, $date));

        if ($row->auto_post) {
            $created = $this->autoPost($company, $created);
        }

        $row->occurrences_generated = (int) $row->occurrences_generated + 1;
        $row->last_generated_at = now();
        $row->next_run_date = $this->calculator->next($row, $date)->toDateString();

        $this->applyEndRule($row);
        $row->save();

        return $created;
    }

    /**
     * Post a freshly generated entry. A locked period (or a template that somehow
     * no longer balances) leaves it as a draft, logged, instead of aborting the run.
     */
    protected function autoPost(Company $company, JournalEntry $entry): JournalEntry
    {
        try {
            return $this->withCompany($company, fn (): JournalEntry => app(JournalPoster::class)->post($entry));
        } catch (PeriodLockedException|UnbalancedJournalException $e) {
            Log::warning('Recurring journal auto-post skipped; left as a draft.', [
                'journal_entry_id' => $entry->id,
                'company_id' => $company->id,
                'reason' => $e->getMessage(),
            ]);

            return $entry;
        }
    }

    protected function createDraftFor(RecurringJournalEntry $row, CarbonImmutable $date): JournalEntry
    {
        $lines = $row->lines->map(fn ($line): array => [
            'account_id' => $line->account_id,
            'debit_cents' => (int) $line->debit_cents,
            'credit_cents' => (int) $line->credit_cents,
            'memo' => $line->memo,
            'contact_id' => $line->contact_id,
            'class_id' => $line->class_id,
            'location_id' => $line->location_id,
            'fund_id' => $line->fund_id,
        ])->all();

        $entry = app(SaveJournalEntry::class)->handle([
            'entry_no' => null,
            'entry_date' => $date->toDateString(),
            'memo' => $row->memo,
            'lines' => $lines,
        ]);

        $entry->forceFill(['recurring_journal_entry_id' => $row->id])->save();

        return $entry;
    }

    protected function applyEndRule(RecurringJournalEntry $row): void
    {
        switch ($row->end_type) {
            case RecurrenceEndType::OnDate:
                if ($row->end_date !== null
                    && $row->next_run_date !== null
                    && $row->next_run_date->greaterThan($row->end_date)) {
                    $row->is_active = false;
                    $row->next_run_date = null;
                }
                break;

            case RecurrenceEndType::AfterOccurrences:
                if ($row->max_occurrences !== null
                    && (int) $row->occurrences_generated >= (int) $row->max_occurrences) {
                    $row->is_active = false;
                    $row->next_run_date = null;
                }
                break;

            case RecurrenceEndType::Never:
            default:
                break;
        }
    }

    /**
     * Re-read the schedule under a row lock so concurrent workers cannot both
     * generate the same occurrence, with its line template eager-loaded.
     */
    protected function lockFresh(RecurringJournalEntry $schedule): RecurringJournalEntry
    {
        return RecurringJournalEntry::query()
            ->withoutGlobalScopes()
            ->whereKey($schedule->getKey())
            ->lockForUpdate()
            ->with('lines')
            ->firstOrFail();
    }

    /**
     * Returns a human reason the schedule cannot generate (a dead line account),
     * or null when generation is safe.
     */
    protected function blockingReason(RecurringJournalEntry $row): ?string
    {
        $accountIds = $row->lines->pluck('account_id')->filter()->unique();

        if ($accountIds->isEmpty()) {
            return 'The schedule has no lines.';
        }

        $foundIds = Account::query()->whereIn('id', $accountIds)->pluck('id');

        if ($foundIds->count() !== $accountIds->count()) {
            return 'A line account no longer exists.';
        }

        return null;
    }

    protected function pause(RecurringJournalEntry $row, string $reason): void
    {
        $row->is_active = false;
        $row->paused_reason = $reason;
        $row->save();
    }

    /**
     * Bind $company as the current tenant for the closure, then restore whatever
     * (if anything) was bound before — so SaveJournalEntry and the global company
     * scope behave correctly inside a job.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withCompany(Company $company, Closure $callback): mixed
    {
        $previous = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                app()->instance('current_company', $previous);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }
}
