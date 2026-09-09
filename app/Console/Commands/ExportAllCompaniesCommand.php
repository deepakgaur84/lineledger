<?php

namespace App\Console\Commands;

use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\Membership;
use App\Services\Backup\CompanyExporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Hands-off nightly backup: exports every company's data synchronously, one
 * at a time, in-process (no queue). This fork adds it for self-hosted
 * installs that want a single external script to (1) export everything,
 * then (2) move the finished ZIPs off-box, in that guaranteed order — the
 * upstream single-company `backup:export` command's queued mode makes that
 * ordering awkward, since a caller would have to guess when the queue
 * worker actually finishes before it's safe to move files.
 *
 *   php artisan backups:export-all
 *
 * One company failing (a corrupt row, a disk hiccup) does not abort the
 * run — every other company still gets its export attempt, and the ones
 * that failed are named at the end. Exit code is non-zero if ANY company
 * failed, so this composes with Schedule::command(...)->onFailure(...) the
 * same way every other command in routes/console.php does — see
 * SchedulerFailureAlert.
 */
class ExportAllCompaniesCommand extends Command
{
    protected $signature = 'backups:export-all';

    protected $description = "Produce a ZIP backup of every company's data, synchronously, one at a time.";

    public function handle(CompanyExporter $exporter): int
    {
        $companies = Company::query()->withoutGlobalScopes()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->info('No companies to back up.');

            return self::SUCCESS;
        }

        $failures = [];

        foreach ($companies as $company) {
            $this->line(sprintf('Exporting company #%d (%s)...', $company->id, $company->slug));

            $ownerUserId = Membership::query()
                ->where('company_id', $company->id)
                ->where('role', CompanyRole::Owner)
                ->value('user_id');

            // BelongsToCompany stamps company_id from app('current_company') on
            // creating() — bind it per-company so each row lands on the right
            // tenant, exactly like ExportCompanyCommand does for a single company.
            app()->instance('current_company', $company);

            $backup = CompanyBackup::create([
                'status' => CompanyBackupStatus::Pending,
                'requested_by_user_id' => $ownerUserId,
                'app_version' => config('version.app'),
                'schema_version' => config('version.schema'),
            ]);

            $backup->forceFill(['status' => CompanyBackupStatus::Running])->save();

            try {
                $exporter->export($backup);
                $backup->refresh();

                $this->info(sprintf(
                    '  Backup #%d ready: %s (%s bytes).',
                    $backup->id,
                    $backup->file_path ?? 'unknown',
                    number_format((int) $backup->file_size_bytes),
                ));
            } catch (Throwable $e) {
                $failures[] = $company->slug;
                $this->error(sprintf('  Company #%d (%s) failed: %s', $company->id, $company->slug, $e->getMessage()));
                // Deliberately no rethrow: one company's failure should not
                // stop the rest of the nightly run from backing up.
            }
        }

        if ($failures !== []) {
            $this->error(sprintf('Completed with %d failure(s): %s', count($failures), implode(', ', $failures)));

            return self::FAILURE;
        }

        $this->info(sprintf('All %d compan%s backed up successfully.', $companies->count(), $companies->count() === 1 ? 'y' : 'ies'));

        return self::SUCCESS;
    }
}
