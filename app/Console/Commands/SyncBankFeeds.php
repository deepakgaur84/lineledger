<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesCompanyArgument;
use App\Models\BankFeedAccount;
use App\Models\Company;
use App\Services\Banking\Feeds\BankFeedSyncer;
use Illuminate\Console\Command;
use Throwable;

class SyncBankFeeds extends Command
{
    use ResolvesCompanyArgument;

    protected $signature = 'bank-feeds:sync {company? : Company ID or slug; all companies with a feed when omitted}';

    protected $description = 'Pull new bank-feed transactions (Akahu) into each company\'s For Review queue.';

    public function handle(BankFeedSyncer $syncer): int
    {
        $arg = $this->argument('company');

        $companies = $arg !== null
            ? $this->whereCompanyArgument(Company::query(), $arg)->get()
            : Company::query()->whereIn('id', BankFeedAccount::query()->withoutGlobalScopes()->select('company_id'))->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->line('No companies with a bank feed.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($companies as $company) {
            app()->instance('current_company', $company);

            try {
                $accounts = BankFeedAccount::query()
                    ->where('company_id', $company->id)
                    ->where('is_active', true)
                    ->whereNotNull('account_id')
                    ->orderBy('id')
                    ->get();

                foreach ($accounts as $feedAccount) {
                    try {
                        $result = $syncer->sync($feedAccount);
                        $this->line(sprintf('%s / %s — %d new, %d updated.', $company->slug, $feedAccount->name, $result['added'], $result['updated']));
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error(sprintf('%s / %s — %s', $company->slug, $feedAccount->name, $e->getMessage()));
                    }
                }
            } finally {
                app()->forgetInstance('current_company');
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
