<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesCompanyArgument;
use App\Models\Company;
use App\Services\Currency\ReversalForeignMemoBackfiller;
use Illuminate\Console\Command;

class BackfillReversalForeignMemosCommand extends Command
{
    use ResolvesCompanyArgument;

    protected $signature = 'currency:backfill-reversal-memos
        {company? : Company ID or slug; all companies when omitted}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Copy the foreign-currency memo onto void and accrual reversal lines written without it, so a voided foreign document leaves no foreign units behind, and re-open the invoices and bills a voided foreign credit had closed.';

    public function __construct(private ReversalForeignMemoBackfiller $backfiller)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $arg = $this->argument('company');
        $dryRun = (bool) $this->option('dry-run');

        $companies = $arg !== null
            ? $this->whereCompanyArgument(Company::query()->withoutGlobalScopes(), $arg)->get()
            : Company::query()->withoutGlobalScopes()->orderBy('id')->get();

        // A named company that matches nothing is an operator error; no companies
        // at all (a fresh install, which app:upgrade runs on first boot) is not.
        if ($companies->isEmpty()) {
            if ($arg === null) {
                $this->info('No companies yet; nothing to backfill.');

                return self::SUCCESS;
            }

            $this->error('No matching company.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $result = $this->backfiller->backfill($company->id, $dryRun);

            $this->line(sprintf(
                'Company %s — %s the foreign-currency memo onto %d line(s) of %d reversal(s).',
                $company->slug,
                $dryRun ? 'would copy' : 'copied',
                $result['lines'],
                $result['reversals'],
            ));

            if ($dryRun && $result['contacts'] > 0) {
                $this->line(sprintf(
                    '  Would then re-check %d customer or vendor balance(s) and re-open any invoice or bill a voided credit had closed.',
                    $result['contacts'],
                ));
            }

            if (! $dryRun && $result['invoices'] + $result['bills'] > 0) {
                $this->line(sprintf(
                    '  Re-opened %d invoice(s) and %d bill(s) that a voided credit had closed.',
                    $result['invoices'],
                    $result['bills'],
                ));
            }

            if ($result['revaluations'] > 0) {
                $this->warn(sprintf(
                    '  %d Home Currency Adjustment(s) dated %s or later revalued these units as if they were still open, so the unrealized gain or loss they posted counted voided documents. Each reversed itself the next day; review the period-end figures they produced.',
                    $result['revaluations'],
                    $result['since'],
                ));
            }

            foreach ($result['skipped'] as $skip) {
                $this->warn(sprintf(
                    '  Skipped reversal %s (#%d) of entry %s (#%d): %s Check it by hand.',
                    $skip['entry_no'],
                    $skip['id'],
                    $skip['original_no'],
                    $skip['original_id'],
                    $skip['reason'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
