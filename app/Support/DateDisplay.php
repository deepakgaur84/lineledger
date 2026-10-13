<?php

namespace App\Support;

use App\Enums\DateFormat;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon as IlluminateCarbon;
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
        $value = Auth::user()?->getAttributeValue('date_format');

        if ($value instanceof DateFormat) {
            return $value;
        }

        return (is_string($value) ? DateFormat::tryFrom($value) : null) ?? DateFormat::default();
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
        // Carbon binds macro closures to the date instance at call time, so
        // $this is valid at runtime even though this is a static method.
        $macro = function (): string {
            return DateDisplay::current()->format($this); // @phpstan-ignore variable.undefined
        };

        Carbon::macro('displayDate', $macro);
        CarbonImmutable::macro('displayDate', $macro);
        IlluminateCarbon::macro('displayDate', $macro);
    }
}
