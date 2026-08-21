<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Week-based presentation of dates (6.6).
 *
 * Dates are stored as real `date` values; this class only formats them. The
 * week number is the week within the month, `ceil(day / 7)`, which yields 1–5
 * and matches how the team already labelled their spreadsheets.
 */
class WeekFormat
{
    public const TIMEZONE = 'Asia/Jakarta';

    /**
     * Week within the month, 1 through 5 (DATE-3).
     */
    public static function weekOfMonth(Carbon $date): int
    {
        return (int) ceil($date->day / 7);
    }

    /**
     * Short week label, e.g. `W1 07-25` (DATE-2).
     */
    public static function label(?Carbon $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return sprintf(
            'W%d %s-%s',
            static::weekOfMonth($date),
            $date->format('m'),
            $date->format('y'),
        );
    }

    /**
     * Monday of the week containing the date, used as a start date (DATE-4).
     */
    public static function startOfWeek(Carbon $date): Carbon
    {
        return $date->copy()->startOfWeek(Carbon::MONDAY);
    }

    /**
     * Friday of the week containing the date, used as an end date (DATE-4).
     */
    public static function endOfWeek(Carbon $date): Carbon
    {
        return static::startOfWeek($date)->addDays(4);
    }

    /**
     * Day-level label in the Indonesian format used across the UI, `d M Y`.
     */
    public static function day(?Carbon $date): ?string
    {
        return $date?->format('d M Y');
    }
}
