<?php

namespace App\Exceptions\EditLocks;

use App\Contracts\ClientSafeException;
use App\Services\EditLocks\EditLockHolder;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * A write was refused because another member is editing the record.
 *
 * The message names the holder — it is shown to members of the same company
 * (show-page toasts; existing `catch (\RuntimeException)` blocks surface it
 * as-is). API clients and the employee portal get {@see clientSafeMessage()},
 * which never names anyone. The API renders this as 423 with Retry-After.
 */
class RecordEditLockedException extends RuntimeException implements ClientSafeException, ShouldntReport
{
    public function __construct(
        public readonly string $noun,
        public readonly EditLockHolder $holder,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(__(":name is editing this :noun. Try again when they're done.", [
            'name' => $holder->name(),
            'noun' => $noun,
        ]));
    }

    public function clientSafeMessage(): string
    {
        return __('This :noun is being edited by someone else in LineLedger. Try again shortly.', ['noun' => $this->noun]);
    }
}
