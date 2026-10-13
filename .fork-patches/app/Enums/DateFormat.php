<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How dates are DISPLAYED to a user (Settings → Appearance → Date format).
 *
 * Display only: date inputs, URLs, queries, filenames and API payloads stay
 * ISO (Y-m-d). The default, 01-Apr-26, is deliberately unambiguous — a month
 * name can't be mistaken for a day the way 04/01/26 can.
 */
enum DateFormat: string
{
    case DayMonthShortYear = 'dd_mmm_yy';
    case DayMonthYear = 'dd_mmm_yyyy';
    case Iso = 'yyyy_mm_dd';
    case DayMonthNameYear = 'd_mmmm_yyyy';
    case MonthDayYear = 'mmm_d_yyyy';
    case DaySlash = 'dd_mm_yyyy';
    case MonthSlash = 'mm_dd_yyyy';
    case MonthSlashShort = 'm_d_yyyy';
    case DayDot = 'dd_dot_mm_yyyy';

    public static function default(): self
    {
        return self::DayMonthShortYear;
    }

    /** The PHP date() pattern behind this option. */
    public function pattern(): string
    {
        return match ($this) {
            self::DayMonthShortYear => 'd-M-y',
            self::DayMonthYear => 'd-M-Y',
            self::Iso => 'Y-m-d',
            self::DayMonthNameYear => 'j F Y',
            self::MonthDayYear => 'M j, Y',
            self::DaySlash => 'd/m/Y',
            self::MonthSlash => 'm/d/Y',
            self::MonthSlashShort => 'n/j/Y',
            self::DayDot => 'd.m.Y',
        };
    }

    public function format(CarbonInterface $date): string
    {
        return $date->format($this->pattern());
    }

    /** A fixed sample (1 April 2026) — unambiguous in every format shown. */
    public function example(): string
    {
        return CarbonImmutable::create(2026, 4, 1)->format($this->pattern());
    }

    public function label(): string
    {
        return $this->example();
    }

    public function description(): string
    {
        return match ($this) {
            self::DayMonthShortYear => 'Day, month name, 2-digit year (default)',
            self::DayMonthYear => 'Day, month name, 4-digit year',
            self::Iso => 'ISO 8601 — year first',
            self::DayMonthNameYear => 'Month spelled out',
            self::MonthDayYear => 'US-style with month name',
            self::DaySlash => 'Day first, numeric',
            self::MonthSlash => 'Month first, numeric',
            self::MonthSlashShort => 'Month first, no leading zeros',
            self::DayDot => 'Day first, dotted',
        };
    }
}
