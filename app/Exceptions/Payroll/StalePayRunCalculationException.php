<?php

namespace App\Exceptions\Payroll;

use App\Services\Payroll\PayrollYtdService;
use RuntimeException;

/**
 * Thrown when posting a pay run whose year-to-date basis is not settled:
 *  - forEmployee(): its year-to-date totals changed after it was calculated
 *    (another run for the same employee was calculated, posted, voided or
 *    edited back to draft), so its CPP, EI, QPP or QPIP may take annual room
 *    that is no longer there. Recalculating the run fixes it.
 *  - earlierRunNotPosted(): it counts a calculated run with an earlier pay date
 *    (or the same date, created first) that is not posted yet. Posting that run
 *    first, or saving it back as a draft, fixes it.
 * See {@see PayrollYtdService::assertPostable()}.
 */
class StalePayRunCalculationException extends RuntimeException
{
    public static function forEmployee(string $employee): self
    {
        return new self(__('Recalculate this pay run before posting it. :name’s year-to-date CPP, EI and tax amounts changed after it was calculated, because another pay run was calculated, posted or voided.', [
            'name' => $employee,
        ]));
    }

    public static function earlierRunNotPosted(string $runNo, string $payDate, string $employee): self
    {
        return new self(__('Pay run :run (pay date :date) is calculated but not posted, and :name’s year-to-date in this pay run counts it. Post it first, or open it and save it as a draft if you won’t use it.', [
            'run' => $runNo,
            'date' => $payDate,
            'name' => $employee,
        ]));
    }
}
