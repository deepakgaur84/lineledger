<?php

namespace App\Jobs;

use App\Models\BankFeedAccount;
use App\Models\Company;
use App\Services\Banking\Feeds\BankFeedSyncer;
use App\Services\Banking\Feeds\BankFeedSyncStatus;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * One feed-account sync started from the Bank feeds page. It is dispatched
 * after the HTTP response (no queue worker needed), so a long first sync no
 * longer ties up — or times out — the page. Progress goes to BankFeedSyncStatus.
 */
class SyncBankFeedAccount
{
    use Dispatchable;

    public function __construct(
        public readonly int $feedAccountId,
        public readonly int $companyId,
    ) {}

    public function handle(BankFeedSyncer $syncer): void
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $company = Company::query()->find($this->companyId);
        $feedAccount = BankFeedAccount::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->find($this->feedAccountId);

        if ($company === null || $feedAccount === null) {
            return;
        }

        $bound = app()->bound('current_company');

        if (! $bound) {
            app()->instance('current_company', $company);
        }

        try {
            $result = $syncer->sync(
                $feedAccount,
                null,
                fn (string $message) => BankFeedSyncStatus::put($this->feedAccountId, 'running', $message),
            );

            BankFeedSyncStatus::put(
                $this->feedAccountId,
                'done',
                __('Finished: :added new, :updated updated. New transactions are in For Review.', [
                    'added' => $result['added'],
                    'updated' => $result['updated'],
                ]),
            );
        } catch (Throwable $e) {
            report($e);
            BankFeedSyncStatus::put($this->feedAccountId, 'error', $e->getMessage());
        } finally {
            if (! $bound) {
                app()->forgetInstance('current_company');
            }
        }
    }
}
