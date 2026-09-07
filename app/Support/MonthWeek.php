<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Weeks counted inside their month, the way the team reads a calendar: weeks
 * run Monday to Sunday. `W3 08-26` is the third such week of August 2026.
 *
 * A month rarely opens on a Monday, so it starts with a few days left over
 * from the week before. Those days join W1 when there are fewer than four of
 * them — the ISO 8601 rule, which keeps a two day sliver from taking a whole
 * column of its own — and stand as W1 themselves when there are four or more.
 *
 * The per-programmer spreadsheets draw four columns a month, so whatever is
 * left over at the end folds into W4 rather than opening a fifth column. The
 * frontend timeline (`resources/js/lib/week.ts`) counts exactly the same way,
 * so a label means the same thing in the workbook and on screen.
 */
class MonthWeek
{
    /** Columns drawn per month. */
    public const PER_MONTH = 4;

    /** Week within the month, 1 through 4, counted Monday to Sunday. */
    public static function of(CarbonInterface $date): int
    {
        $second = self::secondWeekOpens($date);

        if ($date->day < $second) {
            return 1;
        }

        return min(self::PER_MONTH, intdiv($date->day - $second, 7) + 2);
    }

    /**
     * The day W2 opens on: the month's first Monday, unless the days before it
     * are too few to stand as a week of their own, in which case W1 swallows
     * that Monday's week and W2 opens a week later.
     */
    protected static function secondWeekOpens(CarbonInterface $date): int
    {
        $lead = (8 - $date->copy()->startOfMonth()->dayOfWeekIso) % 7;

        return $lead < 4 ? $lead + 8 : $lead + 1;
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
