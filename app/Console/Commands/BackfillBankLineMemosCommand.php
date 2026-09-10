<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Banking\BankLineMemoBackfiller;
use Illuminate\Console\Command;

class BackfillBankLineMemosCommand extends Command
{
    protected $signature = 'banking:backfill-line-memos {company? : Company ID or slug; all companies when omitted}';

    protected $description = "Append each posted document's own memo to its bank journal line so the register reads \"Deposit: <memo>\" instead of a bare \"Deposit\".";

    public function __construct(private BankLineMemoBackfiller $backfiller)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $arg = $this->argument('company');

        $companies = $arg !== null
            ? Company::query()->withoutGlobalScopes()->where('id', $arg)->orWhere('slug', $arg)->get()
            : Company::query()->withoutGlobalScopes()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $result = $this->backfiller->backfill($company->id);

            $this->line(sprintf('Company %s — rewrote %d bank line memo(s).', $company->slug, $result['updated']));
        }

        return self::SUCCESS;
    }
}
