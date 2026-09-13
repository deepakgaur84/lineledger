<?php

/*
|--------------------------------------------------------------------------
| Edit locks (one editor at a time)
|--------------------------------------------------------------------------
|
| When someone opens a record to edit it they hold a short lease on it; other
| members see who is editing and can't change the record until the lease is
| released (save / leave) or lapses. See App\Services\EditLocks\EditLockManager.
|
*/

return [

    // Operator kill switch. Off = every lock call is a no-op.
    'enabled' => (bool) env('EDIT_LOCKS_ENABLED', true),

    // How long a lease lives without a heartbeat. Hidden browser tabs throttle
    // timers to roughly one per minute, so keep this comfortably above 60s.
    'ttl_seconds' => (int) env('EDIT_LOCKS_TTL_SECONDS', 120),

    // How often the open edit page renews its lease.
    'heartbeat_seconds' => (int) env('EDIT_LOCKS_HEARTBEAT_SECONDS', 30),

    // A page with no keyboard/mouse activity for this long stops renewing, so a
    // tab left open overnight doesn't hold the record hostage.
    'idle_minutes' => (int) env('EDIT_LOCKS_IDLE_MINUTES', 15),

    // Released lock rows untouched for this long are deleted by edit-locks:prune.
    'prune_after_days' => (int) env('EDIT_LOCKS_PRUNE_AFTER_DAYS', 7),

];
