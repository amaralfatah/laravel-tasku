<?php

namespace App\Support;

use App\Enums\ExportZoom;
use Carbon\CarbonInterface;

/**
 * The Gantt grid of an exported workbook: what its columns are, and which one
 * a date lands in.
 *
 * `ExportZoom::Week` is the reference layout — four columns a month, so days
 * 29 through 31 fall in W4 rather than opening a fifth column. That grid is
 * what people diff against older copies of the report, so it is the default
 * and its output must not drift; the coarser zooms exist so a plan running
 * over several years fits on a page.
 *
 * Every zoom draws the same three header rows — year, group, unit — which is
 * what keeps the rest of the sheet's geometry identical between them:
 *
 * | Zoom    | Group row      | Unit row          |
 * | ------- | -------------- | ----------------- |
 * | Week    | `Agustus`      | `1` `2` `3` `4`   |
 * | Month   | `Q3`           | `Jul` `Agu` `Sep` |
 * | Quarter | `Q3`           | `Jul-Sep`         |
 */
class TimelineGrid
{
    /** Month names, indexed the way `Carbon::month` counts them. */
    protected const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    protected const SHORT_MONTHS = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];

    /** Width of one timeline column, per zoom. */
    protected const WIDTHS = [
        ExportZoom::Week->value => 3.18,
        ExportZoom::Month->value => 6.36,
        ExportZoom::Quarter->value => 10.0,
    ];

    /**
     * The columns, left to right.
     *
     * @var array<int, array{group: string, unit: string, year: int}>
     */
    protected array $columns;

    /** Start of the first column; every slot is counted from here. */
    protected CarbonInterface $origin;

    public function __construct(
        public readonly ExportZoom $zoom,
        CarbonInterface $from,
        CarbonInterface $to,
    ) {
        $this->origin = $zoom === ExportZoom::Quarter
            ? $from->copy()->startOfQuarter()
            : $from->copy()->startOfMonth();

        $this->columns = $this->buildColumns($to);
    }

    /** How many timeline columns the sheet carries. */
    public function count(): int
    {
        return count($this->columns);
    }

    /** Zero based column a date falls in, counted from the grid's origin. */
    public function slot(CarbonInterface $date): int
    {
        $months = ($date->year - $this->origin->year) * 12 + ($date->month - $this->origin->month);

        return match ($this->zoom) {
            ExportZoom::Week => $months * MonthWeek::PER_MONTH + MonthWeek::of($date) - 1,
            ExportZoom::Month => $months,
            ExportZoom::Quarter => intdiv($months, 3),
        };
    }

    /**
     * The label for the START and END columns, spoken at the grid's own
     * granularity: `W3 08-26`, `Agu 26` or `Q3 26`.
     */
    public function label(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        return match ($this->zoom) {
            ExportZoom::Week => MonthWeek::label($date),
            ExportZoom::Month => self::SHORT_MONTHS[$date->month].' '.$date->format('y'),
            ExportZoom::Quarter => 'Q'.$date->quarter.' '.$date->format('y'),
        };
    }

    /**
     * First column after the group holding `$slot` — where the greyed tail of
     * a person's sheet begins, so their last month or quarter stays whole.
     */
    public function groupEnd(int $slot): int
    {
        $current = $this->columns[$slot] ?? null;

        if ($current === null) {
            return $this->count();
        }

        $index = $slot;

        while (
            isset($this->columns[$index])
            && $this->columns[$index]['group'] === $current['group']
            && $this->columns[$index]['year'] === $current['year']
        ) {
            $index++;
        }

        return $index;
    }

    /**
     * Year bands of the top header row.
     *
     * @return array<int, array{label: string, span: int}>
     */
    public function years(): array
    {
        return $this->bands(
            fn (array $column): string => (string) $column['year'],
            fn (array $column): string => (string) $column['year'],
        );
    }

    /**
     * Month or quarter bands of the middle header row.
     *
     * @return array<int, array{label: string, span: int}>
     */
    public function groups(): array
    {
        return $this->bands(
            fn (array $column): string => $column['group'].' '.$column['year'],
            fn (array $column): string => $column['group'],
        );
    }

    /**
     * One label per column for the bottom header row.
     *
     * @return array<int, string>
     */
    public function units(): array
    {
        return array_map(fn (array $column): string => $column['unit'], $this->columns);
    }

    public function columnWidth(): float
    {
        return self::WIDTHS[$this->zoom->value];
    }

    /**
     * @return array<int, array{group: string, unit: string, year: int}>
     */
    protected function buildColumns(CarbonInterface $to): array
    {
        $columns = [];
        $cursor = $this->origin->copy();

        // The range always closes on a December, so stepping by month or by
        // quarter reaches the end squarely either way.
        $last = $to->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            foreach ($this->columnsOf($cursor) as $column) {
                $columns[] = $column;
            }

            $cursor = $cursor->copy()->addMonths($this->zoom === ExportZoom::Quarter ? 3 : 1);
        }

        return $columns;
    }

    /**
     * The columns one step of the cursor contributes.
     *
     * @return array<int, array{group: string, unit: string, year: int}>
     */
    protected function columnsOf(CarbonInterface $cursor): array
    {
        $year = $cursor->year;

        if ($this->zoom === ExportZoom::Week) {
            $group = self::MONTHS[$cursor->month];

            return array_map(
                fn (int $week): array => ['group' => $group, 'unit' => (string) $week, 'year' => $year],
                range(1, MonthWeek::PER_MONTH),
            );
        }

        if ($this->zoom === ExportZoom::Month) {
            return [[
                'group' => 'Q'.$cursor->quarter,
                'unit' => self::SHORT_MONTHS[$cursor->month],
                'year' => $year,
            ]];
        }

        $closing = $cursor->copy()->endOfQuarter();

        return [[
            'group' => 'Q'.$cursor->quarter,
            'unit' => self::SHORT_MONTHS[$cursor->month].'-'.self::SHORT_MONTHS[$closing->month],
            'year' => $year,
        ]];
    }

    /**
     * Collapse the columns into bands of equal key, keeping their order.
     *
     * @param  callable(array{group: string, unit: string, year: int}): string  $key
     * @param  callable(array{group: string, unit: string, year: int}): string  $label
     * @return array<int, array{label: string, span: int}>
     */
    protected function bands(callable $key, callable $label): array
    {
        $bands = [];
        $previous = null;

        foreach ($this->columns as $column) {
            $current = $key($column);

            if ($current === $previous) {
                $bands[count($bands) - 1]['span']++;

                continue;
            }

            $bands[] = ['label' => $label($column), 'span' => 1];
            $previous = $current;
        }

        return $bands;
    }
}
