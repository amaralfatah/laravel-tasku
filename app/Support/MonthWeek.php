<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Weeks counted inside their month, the way the team writes them: `W3 08-26`
 * is the third week of August 2026.
 *
 * The old per-programmer spreadsheets drew four such columns per month, so the
 * 29th through the 31st fall in the fourth column rather than opening a fifth
 * one. The frontend timeline (`resources/js/lib/week.ts`) allows a fifth week
 * because it lays out real weeks; this one lays out a fixed grid.
 */
class MonthWeek
{
    /** Columns drawn per month. */
    public const PER_MONTH = 4;

    /** Week within the month, 1 through 4. */
    public static function of(CarbonInterface $date): int
    {
        return min(self::PER_MONTH, (int) ceil($date->day / 7));
    }

    /** Label as `W3 08-26`, or an em dash when the date is missing. */
    public static function label(?CarbonInterface $date): string
    {
        return $date === null ? '—' : sprintf('W%d %s', self::of($date), $date->format('m-y'));
    }

    /**
     * First day of every month the range touches, inclusive on both ends.
     *
     * @return array<int, CarbonInterface>
     */
    public static function months(CarbonInterface $from, CarbonInterface $to): array
    {
        $months = [];
        $cursor = $from->copy()->startOfMonth();
        $last = $to->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            $months[] = $cursor;
            $cursor = $cursor->copy()->addMonth();
        }

        return $months;
    }

    /**
     * Zero based column of a date on a grid that starts at `$from`.
     *
     * Months are counted off the calendar rather than through a date diff, so
     * a partial month at either end still lands on a whole column.
     */
    public static function slot(CarbonInterface $date, CarbonInterface $from): int
    {
        $months = ($date->year - $from->year) * 12 + ($date->month - $from->month);

        return $months * self::PER_MONTH + self::of($date) - 1;
    }
}
