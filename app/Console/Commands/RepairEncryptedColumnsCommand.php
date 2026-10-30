<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesCompanyArgument;
use App\Models\Company;
use App\Services\Restore\EncryptedColumnRepairer;
use Illuminate\Console\Command;

class RepairEncryptedColumnsCommand extends Command
{
    use ResolvesCompanyArgument;

    protected $signature = 'backup:repair-encrypted-columns
        {company? : Company ID or slug; all companies when omitted}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Encrypt the employee SINs that restores from an organization backup stored unencrypted, and report any this APP_KEY cannot decrypt.';

    public function __construct(private EncryptedColumnRepairer $repairer)
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

        $encrypted = 0;
        $unreadable = 0;

        // Only organizations with something to report get a line: on most
        // instances that is none of them.
        foreach ($companies as $company) {
            foreach ($this->repairer->repair($company->id, $dryRun) as $column => $result) {
                $encrypted += $result['encrypted'];
                $unreadable += $result['unreadable'];

                if ($result['encrypted'] > 0) {
                    $this->line(sprintf(
                        'Company %s — %s %d unencrypted value(s) in %s.',
                        $company->slug,
                        $dryRun ? 'would encrypt' : 'encrypted',
                        $result['encrypted'],
                        $column,
                    ));
                }

                if ($result['unreadable'] > 0) {
                    $this->warn(sprintf(
                        'Company %s — %d value(s) in %s do not decrypt with this APP_KEY (a database restored under another key?). They cannot be recovered here: enter them again.',
                        $company->slug,
                        $result['unreadable'],
                        $column,
                    ));
                }
            }
        }

        $this->line(sprintf(
            '%s %d unencrypted value(s); %d value(s) do not decrypt with this APP_KEY.',
            $dryRun ? 'Would encrypt' : 'Encrypted',
            $encrypted,
            $unreadable,
        ));

        return self::SUCCESS;
    }
}
