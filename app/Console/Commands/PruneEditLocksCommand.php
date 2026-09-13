<?php

namespace App\Console\Commands;

use App\Services\EditLocks\EditLockManager;
use Illuminate\Console\Command;

/**
 * Daily sweep of edit-lock rows nobody has held or changed for a while.
 *
 * A row is kept after its lease is released so its version survives an open
 * page's round trip; once it has sat untouched for `edit_locks.prune_after_days`
 * no such page can still be open, so it is deleted. Deleting is safe even if
 * one were: versions are random, and a page whose row is gone gets "changed"
 * when it tries to save.
 */
class PruneEditLocksCommand extends Command
{
    protected $signature = 'edit-locks:prune';

    protected $description = 'Delete released edit-lock rows untouched for edit_locks.prune_after_days.';

    public function handle(EditLockManager $locks): int
    {
        $deleted = $locks->prune((int) config('edit_locks.prune_after_days', 7));

        $this->info("Pruned {$deleted} edit lock(s).");

        return self::SUCCESS;
    }
}
