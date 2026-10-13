<?php

namespace App\Support;

use App\Enums\DateFormat;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Formats dates for display using the signed-in user's chosen format.
 *
 * Views call {@code $date?->displayDate()} (a Carbon macro registered by
 * {@see register()}); there is no user in queued jobs and on public pages, so
 * those fall back to the default format.
 */
final class DateDisplay
{
    public static function current(): DateFormat
    {
        $format = Auth::user()?->date_format ?? null;

        return $format instanceof DateFormat ? $format : DateFormat::default();
    }

    public static function format(CarbonInterface|string|null $date, string $empty = ''): string
    {
        if ($date === null || $date === '') {
            return $empty;
        }

        if (is_string($date)) {
            $date = CarbonImmutable::parse($date);
        }

        return self::current()->format($date);
    }

    public static function register(): void
    {
        $macro = fn (): string => DateDisplay::current()->format($this);

        Carbon::macro('displayDate', $macro);
        CarbonImmutable::macro('displayDate', $macro);
        \Illuminate\Support\Carbon::macro('displayDate', $macro);
    }
}
