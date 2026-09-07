<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Weeks counted inside their month, the way the team reads a calendar: weeks
 * run Monday to Sunday, and the days before the month's first Monday are its
 * week 1. `W3 08-26` is the third such week of August 2026.
 *
 * The per-programmer spreadsheets draw four columns a month, so a month whose
 * calendar reaches a fifth week folds that week into the fourth column rather
 * than opening a fifth one. The frontend timeline
 * (`resources/js/lib/week.ts`) counts the same way but is free to show a fifth
 * week, because it lays out real weeks instead of a fixed grid.
 */
class MonthWeek
{
    /** Columns drawn per month. */
    public const PER_MONTH = 4;

    /** Week within the month, 1 through 4, counted Monday to Sunday. */
    public static function of(CarbonInterface $date): int
    {
        $offset = $date->copy()->startOfMonth()->dayOfWeekIso - 1;

        return min(self::PER_MONTH, intdiv($date->day + $offset - 1, 7) + 1);
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
