<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bank feeds (fork)
    |--------------------------------------------------------------------------
    |
    | Automatic transaction import from a bank-data aggregator. Akahu is the
    | first provider; Actual Budget (via the bridge) is the second. The tokens themselves are stored per company
    | (encrypted) on the bank-feed connection, not here.
    |
    */

    'akahu' => [
        'base_url' => env('AKAHU_BASE_URL', 'https://api.akahu.io/v1'),
        'timeout' => (int) env('AKAHU_TIMEOUT', 30),
    ],

    /*
    | Actual Budget, through the bank bridge service (docker-compose service
    | `actual-bank-bridge`). Both values must be set for the provider to be usable;
    | each company's own Actual server address, password and budget id are stored
    | on its bank-feed connection (encrypted), not here.
    */
    'actual' => [
        'bridge_url' => env('ACTUAL_BRIDGE_URL'),
        'bridge_token' => env('ACTUAL_BRIDGE_TOKEN'),
        // A bank sync inside Actual can take minutes.
        'timeout' => (int) env('ACTUAL_BRIDGE_TIMEOUT', 600),
    ],

    // The sync runs every N hours, counting from 00:00 in the app time zone (UTC):
    // 4 (the default) runs at 00:00, 04:00, 08:00, 12:00, 16:00 and 20:00. A whole
    // number from 1 to 24.
    'sync_every_hours' => env('BANK_FEEDS_SYNC_EVERY_HOURS', 4),

    // Each sync re-reads this many days before the last one, so late edits to
    // recent transactions are picked up.
    'overlap_days' => (int) env('BANK_FEEDS_OVERLAP_DAYS', 7),

    // How far back the first sync of an account goes when no start date is set.
    'default_history_days' => (int) env('BANK_FEEDS_HISTORY_DAYS', 30),

];
