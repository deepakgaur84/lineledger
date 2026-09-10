<?php

namespace App\Support\Reporting;

use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The "Generated …" stamp every exported report carries.
 *
 * Reports are read by the people keeping the books, so the stamp belongs in the
 * company's own timezone — the same one `Company::currentDateTime()` uses to
 * default transaction dates. Exporting at 2:34pm Pacific must not print
 * "21:34". A PDF layout that only knows its report group (multi-company), and
 * anything rendered off the request, falls back to the company bound for the
 * render (ReportRenderer and the tenant middleware both bind one) and finally
 * to the app timezone.
 */
final class GeneratedAt
{
    public static function for(?Company $company = null): CarbonImmutable
    {
        $company ??= app()->bound('current_company') ? app('current_company') : null;

        return $company instanceof Company
            ? $company->currentDateTime()
            : CarbonImmutable::now();
    }

    /** The stamp as printed on a report: 'Y-m-d H:i', company-local. */
    public static function label(?Company $company = null): string
    {
        return self::for($company)->format('Y-m-d H:i');
    }
}
