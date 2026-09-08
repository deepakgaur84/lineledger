<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Insights\DailyInsightGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Computes one company's daily "Did you know?" insight. Isolated per company
 * so a slow or erroring tenant cannot block the others; "today" is evaluated
 * in the company's own timezone so the insight lands on its local calendar
 * day, not UTC's.
 */
class GenerateDailyInsightForCompany implements ShouldQueue
{
    use Queueable;

    /**
     * A cosmetic feature — on failure, skip the day rather than retry-hammer
     * the books (or the Anthropic API). Tomorrow's run starts fresh.
     */
    public int $tries = 1;

    public function __construct(public int $companyId) {}

    public function handle(DailyInsightGenerator $generator): void
    {
        $company = Company::query()->find($this->companyId);

        // Deleted between dispatch and execution: nothing to do, and not a
        // failure worth a failed_jobs row and an ops alert.
        if (! $company) {
            Log::info('Skipping daily insight: company no longer exists.', ['company_id' => $this->companyId]);

            return;
        }

        // Defensive: BelongsToCompany's automatic company_id stamping on
        // creating() looks at the bound `current_company` (see
        // ExportCompanyDataJob for the same pattern).
        app()->instance('current_company', $company);

        $generator->generate($company, $company->currentDateTime());
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Daily insight generation failed', [
            'company_id' => $this->companyId,
            'error' => $e->getMessage(),
        ]);
    }
}
