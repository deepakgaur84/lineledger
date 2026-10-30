<?php

namespace App\Exceptions\Payroll;

use App\Services\Payroll\PayrollYtdService;
use RuntimeException;

/**
 * Thrown when posting a pay run would take an employee's posted CPP, CPP2, EI,
 * QPP, QPP2 or QPIP for the year past its annual maximum, typically because of
 * a manual adjustment. See {@see PayrollYtdService::assertPostable()}.
 */
class AnnualMaximumExceededException extends RuntimeException
{
    public static function forEmployee(string $employee, string $deduction, string $maximum, string $remaining): self
    {
        return new self(__('Posting this pay run would take :name’s :deduction past the annual maximum of :maximum: only :remaining is left this year. Correct the adjustment or recalculate the pay run.', [
            'name' => $employee,
            'deduction' => $deduction,
            'maximum' => $maximum,
            'remaining' => $remaining,
        ]));
    }
}
