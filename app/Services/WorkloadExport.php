<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Support\MonthWeek;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Rebuilds the per-programmer workbook out of the live data: one sheet per
 * person, each a WBS list on the left and a month-by-week Gantt on the right.
 *
 * The layout follows the sheet the team already reads (`ContohLaporan.xlsx`)
 * cell for cell — the green banner, the summary box beside the app list, the
 * year/month/week header, and the pale bar with a solid cap on the closing
 * week of finished work. Changing any of it changes a report people compare
 * against older copies, so match that file rather than personal taste.
 *
 * The grid is shared by every sheet — the range is taken once across all the
 * tasks handed in — so two people's bars line up when the sheets are compared
 * side by side, which is the whole point of the format.
 */
class WorkloadExport
{
    /** Column of the task title; the one to its left is a margin. */
    protected const COLUMN_TASK = 2;

    protected const COLUMN_PROGRESS = 3;

    protected const COLUMN_START = 4;

    protected const COLUMN_END = 5;

    /** First timeline column: week 1 of the first month in range. */
    protected const COLUMN_TIMELINE = 6;

    /** Left and right edge of the summary box, which sits over the timeline. */
    protected const COLUMN_SUMMARY = 5;

    protected const COLUMN_SUMMARY_VALUE = 11;

    protected const COLUMN_SUMMARY_END = 14;

    /** Month names, indexed the way `Carbon::month` counts them. */
    protected const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    protected const BANNER = 'FF00B050';

    protected const HEADER = 'FFF2F2F2';

    protected const PROJECT_ROW = 'FFE8E8E8';

    /** The bar itself, and the cap that closes a finished one. */
    protected const BAR = 'FFDAF2D0';

    protected const BAR_DONE = 'FF4EA72E';

    /** Weeks outside the reported window: before a project ran, or past it. */
    protected const OUTSIDE = 'FFD0D0D0';

    protected const RULE = 'FF000000';

    /**
     * Build the workbook.
     *
     * @param  array<int, array{name: string, subtitle: string|null, tasks: Collection<int, Task>}>  $people
     * @param  string|null  $portfolio  when set, a cover sheet is written and named after it
     */
    public function build(array $people, ?string $portfolio = null): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle($portfolio ?? ($people[0]['name'] ?? 'Project Management'))
            ->setSubject('Project Management')
            ->setCreator('Tasku');

        [$from, $to] = $this->range($people);
        $months = MonthWeek::months($from, $to);
        $titles = $this->sheetTitles($people);

        $first = $spreadsheet->getActiveSheet();

        if ($portfolio !== null) {
            $this->writeCover($first, $portfolio, $people, $titles);
        }

        foreach ($people as $index => $person) {
            $sheet = $portfolio === null && $index === 0
                ? $first
                : $spreadsheet->createSheet();

            $sheet->setTitle($titles[$index]);
            $this->writePerson($sheet, $person, $from, $months);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * The months every sheet spans: from the first scheduled month through the
     * December of the year the last one falls in. The grid deliberately runs
     * past the work — the empty tail is greyed per person, which is what makes
     * one sheet's horizon readable next to another's.
     *
     * @param  array<int, array{tasks: Collection<int, Task>}>  $people
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    protected function range(array $people): array
    {
        $dates = $this->datesOf(collect($people)->flatMap(
            fn (array $person): array => $person['tasks']->all(),
        ));

        if ($dates->isEmpty()) {
            return [Date::today()->startOfMonth(), Date::today()->endOfYear()];
        }

        return [
            Date::parse($dates->min())->startOfMonth(),
            Date::parse($dates->max())->endOfYear(),
        ];
    }

    /**
     * Every date a set of tasks carries, both ends, nulls dropped.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Carbon>
     */
    protected function datesOf(Collection $tasks): Collection
    {
        return $tasks
            ->flatMap(fn (Task $task): array => [$task->start_date, $task->due_date])
            ->filter()
            ->values();
    }

    /**
     * Sheet titles, trimmed to Excel's 31 character limit and made unique —
     * two people with the same long name would otherwise collide.
     *
     * @param  array<int, array{name: string}>  $people
     * @return array<int, string>
     */
    protected function sheetTitles(array $people): array
    {
        $titles = [];
        $taken = [];

        foreach ($people as $index => $person) {
            $title = trim(str_replace(['*', ':', '/', '\\', '?', '[', ']'], ' ', $person['name']));
            $title = mb_substr($title === '' ? 'Anggota' : $title, 0, 31);
            $suffix = 2;

            while (in_array(mb_strtolower($title), $taken, true)) {
                $title = mb_substr($title, 0, 28).' '.$suffix++;
            }

            $taken[] = mb_strtolower($title);
            $titles[$index] = $title;
        }

        return $titles;
    }

    /**
     * @param  array<int, array{name: string, subtitle: string|null}>  $people
     * @param  array<int, string>  $titles
     */
    protected function writeCover(Worksheet $sheet, string $portfolio, array $people, array $titles): void
    {
        $sheet->setTitle('Cover');
        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(72);

        $sheet->setCellValue('A1', mb_strtoupper($portfolio));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);

        $sheet->setCellValue('A2', 'Dibuat '.Date::now()->format('d-m-Y H:i').' — satu sheet per anggota.');
        $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setARGB('FF64748B');

        $sheet->setCellValue('A4', 'DAFTAR ISI');
        $sheet->getStyle('A4')->getFont()->setBold(true);

        $row = 5;

        foreach ($people as $index => $person) {
            $sheet->setCellValue('A'.$row, $titles[$index]);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);

            $sheet->setCellValue('B'.$row, 'Rencana kerja dan Gantt '.$person['name'].
                ($person['subtitle'] === null ? '' : ' ('.$person['subtitle'].')').'.');
            $sheet->getStyle('B'.$row)->getFont()->getColor()->setARGB('FF475569');

            $row++;
        }
    }

    /**
     * One person's sheet: heading, the app list with its summary box, then the
     * WBS table with its Gantt three rows below.
     *
     * @param  array{name: string, subtitle: string|null, tasks: Collection<int, Task>}  $person
     * @param  array<int, CarbonInterface>  $months
     */
    protected function writePerson(Worksheet $sheet, array $person, CarbonInterface $from, array $months): void
    {
        $tasks = $person['tasks'];
        $groups = $tasks->groupBy('project_id');

        $sheet->setCellValue([self::COLUMN_TASK, 2], 'PROJECT MANAGEMENT');
        $sheet->getStyle([self::COLUMN_TASK, 2])->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue([self::COLUMN_TASK, 3], $person['name']);
        $sheet->getStyle([self::COLUMN_TASK, 3])->getFont()->setSize(14);

        $headerRow = $this->writeSummary($sheet, $groups, $tasks, 5) + 4;

        $this->writeColumns($sheet, $headerRow, count($months));
        $this->writeMonths($sheet, $headerRow, $months);
        $this->writeRows($sheet, $groups, $headerRow + 3, $from, $months);
    }

    /**
     * The banner row and the two blocks under it: projects with their progress
     * on the left, the counters on the right.
     *
     * @param  Collection<int, Collection<int, Task>>  $groups
     * @param  Collection<int, Task>  $tasks
     * @return int the last row either block reached
     */
    protected function writeSummary(Worksheet $sheet, Collection $groups, Collection $tasks, int $row): int
    {
        $sheet->setCellValue([self::COLUMN_TASK, $row], 'Aplikasi');
        $sheet->setCellValue([self::COLUMN_PROGRESS, $row], 'Progress');

        $sheet->mergeCells($this->area(self::COLUMN_SUMMARY, $row, self::COLUMN_SUMMARY_END, $row));
        $sheet->setCellValue([self::COLUMN_SUMMARY, $row], 'Ringkasan');

        foreach ([
            $this->area(self::COLUMN_TASK, $row, self::COLUMN_PROGRESS, $row),
            $this->area(self::COLUMN_SUMMARY, $row, self::COLUMN_SUMMARY_END, $row),
        ] as $range) {
            $sheet->getStyle($range)->applyFromArray([
                'font' => ['bold' => true, 'size' => 14],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BANNER]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::RULE]]],
            ]);
        }

        $projectRow = $row;
        $number = 1;

        foreach ($groups as $group) {
            $projectRow++;
            $sheet->setCellValue([self::COLUMN_TASK, $projectRow], $number.'. '.$group->first()->project->name);
            $sheet->setCellValue([self::COLUMN_PROGRESS, $projectRow], $this->groupProgress($group) / 100);
            $sheet->getStyle($this->area(self::COLUMN_TASK, $projectRow, self::COLUMN_PROGRESS, $projectRow))
                ->getFont()->setSize(12);
            $sheet->getStyle([self::COLUMN_PROGRESS, $projectRow])->getNumberFormat()->setFormatCode('0%');
            $number++;
        }

        $done = $tasks->where('status', TaskStatus::Done)->count();

        $counters = [
            ['Overall Progress', ($tasks->isEmpty() ? 0 : round((float) $tasks->avg('progress'), 2)) / 100, '0.00%'],
            ['Total Tasks', $tasks->count(), '0'],
            ['Completed Tasks', $done, '0'],
            ['Pending / In Progress', $tasks->count() - $done, '0'],
        ];

        $summaryRow = $row;

        foreach ($counters as [$label, $value, $format]) {
            $summaryRow++;

            $sheet->mergeCells($this->area(self::COLUMN_SUMMARY, $summaryRow, self::COLUMN_SUMMARY_VALUE - 1, $summaryRow));
            $sheet->setCellValue([self::COLUMN_SUMMARY, $summaryRow], $label);

            $sheet->mergeCells($this->area(self::COLUMN_SUMMARY_VALUE, $summaryRow, self::COLUMN_SUMMARY_END, $summaryRow));
            $sheet->setCellValue([self::COLUMN_SUMMARY_VALUE, $summaryRow], $value);

            $sheet->getStyle($this->area(self::COLUMN_SUMMARY, $summaryRow, self::COLUMN_SUMMARY_END, $summaryRow))
                ->applyFromArray([
                    'font' => ['size' => 11],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);

            $sheet->getStyle([self::COLUMN_SUMMARY_VALUE, $summaryRow])->getFont()->setBold(true);
            $sheet->getStyle([self::COLUMN_SUMMARY_VALUE, $summaryRow])->getNumberFormat()->setFormatCode($format);
        }

        return max($projectRow, $summaryRow);
    }

    /**
     * The four fixed columns, merged down the month and week rows, plus the
     * column widths.
     *
     * Nothing is frozen: the reference workbook freezes no pane either, and a
     * split hides the first timeline weeks behind the frozen half.
     */
    protected function writeColumns(Worksheet $sheet, int $row, int $monthCount): void
    {
        $monthRow = $row + 1;
        $weekRow = $row + 2;

        $labels = [
            self::COLUMN_TASK => 'TASK',
            self::COLUMN_PROGRESS => 'PROGRESS',
            self::COLUMN_START => 'START',
            self::COLUMN_END => 'END',
        ];

        foreach ($labels as $column => $label) {
            $sheet->mergeCells($this->area($column, $monthRow, $column, $weekRow));
            $sheet->setCellValue([$column, $monthRow], $label);
        }

        $sheet->getStyle($this->area(self::COLUMN_TASK, $monthRow, self::COLUMN_TASK, $weekRow))
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(1);

        $sheet->getColumnDimension('A')->setWidth(4.82);
        $sheet->getColumnDimension($this->letter(self::COLUMN_TASK))->setWidth(49.18);
        $sheet->getColumnDimension($this->letter(self::COLUMN_PROGRESS))->setWidth(15.18);
        $sheet->getColumnDimension($this->letter(self::COLUMN_START))->setWidth(10.73);
        $sheet->getColumnDimension($this->letter(self::COLUMN_END))->setWidth(10.73);

        $last = $this->lastColumn($monthCount);

        $sheet->getStyle($this->area(self::COLUMN_TASK, $row, $last, $weekRow))->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // The year row carries nothing above the fixed columns, so only the
        // timeline half of it is banded.
        foreach ([
            $this->area(self::COLUMN_TIMELINE, $row, $last, $row),
            $this->area(self::COLUMN_TASK, $monthRow, $last, $weekRow),
        ] as $range) {
            $sheet->getStyle($range)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::RULE]]],
            ]);
        }

        $sheet->getStyle($this->area(self::COLUMN_TIMELINE, $weekRow, $last, $weekRow))
            ->getFont()->setBold(false);
    }

    /**
     * The timeline header: a year band on top, the month names under it, and
     * the four week numbers of each month below that.
     *
     * @param  array<int, CarbonInterface>  $months
     */
    protected function writeMonths(Worksheet $sheet, int $row, array $months): void
    {
        $monthRow = $row + 1;
        $weekRow = $row + 2;

        $year = null;
        $yearStart = self::COLUMN_TIMELINE;

        foreach ($months as $index => $month) {
            $left = self::COLUMN_TIMELINE + $index * MonthWeek::PER_MONTH;

            $sheet->mergeCells($this->area($left, $monthRow, $left + MonthWeek::PER_MONTH - 1, $monthRow));
            $sheet->setCellValue([$left, $monthRow], self::MONTHS[$month->month]);

            for ($week = 1; $week <= MonthWeek::PER_MONTH; $week++) {
                $sheet->setCellValue([$left + $week - 1, $weekRow], $week);
                $sheet->getColumnDimension($this->letter($left + $week - 1))->setWidth(3.18);
            }

            if ($year !== $month->year) {
                if ($year !== null) {
                    $this->writeYear($sheet, $row, $yearStart, $left - 1, $year);
                }

                $year = $month->year;
                $yearStart = $left;
            }
        }

        if ($year !== null) {
            $this->writeYear($sheet, $row, $yearStart, $this->lastColumn(count($months)), $year);
        }
    }

    protected function writeYear(Worksheet $sheet, int $row, int $left, int $right, int $year): void
    {
        $sheet->mergeCells($this->area($left, $row, $right, $row));
        $sheet->setCellValue([$left, $row], $year);
    }

    /**
     * The table body: a shaded line per project, then its tasks, each with the
     * bar of the weeks it spans.
     *
     * @param  Collection<int, Collection<int, Task>>  $groups
     * @param  array<int, CarbonInterface>  $months
     */
    protected function writeRows(Worksheet $sheet, Collection $groups, int $row, CarbonInterface $from, array $months): void
    {
        $last = $this->lastColumn(count($months));
        $first = $row;
        $number = 1;

        foreach ($groups as $group) {
            $this->writeProjectRow($sheet, $group, $number, $row, $from, $last);
            $row++;

            foreach ($group as $task) {
                $this->writeTask($sheet, $task, $number, $row, $from, $last);
                $row++;
            }

            $number++;
        }

        if ($row === $first) {
            return;
        }

        $sheet->getStyle($this->area(self::COLUMN_TASK, $first, $last, $row - 1))->applyFromArray([
            'font' => ['size' => 12],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::RULE]]],
        ]);

        $sheet->getStyle($this->area(self::COLUMN_TASK, $first, self::COLUMN_TASK, $row - 1))
            ->getAlignment()->setWrapText(true);

        $this->greyPastHorizon($sheet, $groups->flatten(1), $first, $row - 1, $from, $last);
    }

    /**
     * The project line: name, rolled up progress, its own window in the START
     * and END columns, and a bar covering everything its tasks touch.
     *
     * @param  Collection<int, Task>  $group
     */
    protected function writeProjectRow(Worksheet $sheet, Collection $group, int $number, int $row, CarbonInterface $from, int $lastColumn): void
    {
        $dates = $this->datesOf($group);
        $start = $dates->isEmpty() ? null : Date::parse($dates->min());
        $end = $dates->isEmpty() ? null : Date::parse($dates->max());

        $sheet->setCellValue([self::COLUMN_TASK, $row], $number.'. '.$group->first()->project->name);
        $sheet->setCellValue([self::COLUMN_PROGRESS, $row], $this->groupProgress($group) / 100);
        $sheet->getStyle([self::COLUMN_PROGRESS, $row])->getNumberFormat()->setFormatCode('0%');
        $sheet->setCellValue([self::COLUMN_START, $row], MonthWeek::label($start));
        $sheet->setCellValue([self::COLUMN_END, $row], MonthWeek::label($end));

        $sheet->getStyle($this->area(self::COLUMN_TASK, $row, $lastColumn, $row))->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::PROJECT_ROW]],
        ]);

        if ($start === null || $end === null) {
            return;
        }

        // Everything before the project started reads as out of scope, the way
        // the reference sheet greys it.
        $left = self::COLUMN_TIMELINE + MonthWeek::slot($start, $from);

        if ($left > self::COLUMN_TIMELINE) {
            $this->fill($sheet, self::COLUMN_TIMELINE, $row, $left - 1, $row, self::OUTSIDE);
        }

        $this->drawBar($sheet, $row, $left, min($lastColumn, self::COLUMN_TIMELINE + MonthWeek::slot($end, $from)),
            $group->every(fn (Task $task): bool => $task->status === TaskStatus::Done));
    }

    /**
     * One task line. A task missing one of its two dates still gets a single
     * week marked, so work scheduled at one end only stays visible.
     *
     * The WBS number is prefixed with the project's position on the sheet, so a
     * task under the third application reads `3.2.1` — the numbering the
     * workbook uses, where the application itself is the first level.
     */
    protected function writeTask(Worksheet $sheet, Task $task, int $number, int $row, CarbonInterface $from, int $lastColumn): void
    {
        $sheet->setCellValue([self::COLUMN_TASK, $row], trim($number.'.'.$task->wbs_number.' '.$task->title));
        $sheet->setCellValue([self::COLUMN_PROGRESS, $row], $task->progress / 100);
        $sheet->getStyle([self::COLUMN_PROGRESS, $row])->getNumberFormat()->setFormatCode('0%');
        $sheet->setCellValue([self::COLUMN_START, $row], MonthWeek::label($task->start_date));
        $sheet->setCellValue([self::COLUMN_END, $row], MonthWeek::label($task->due_date));

        $start = $task->start_date ?? $task->due_date;
        $end = $task->due_date ?? $task->start_date;

        if ($start === null || $end === null) {
            return;
        }

        $this->drawBar(
            $sheet,
            $row,
            self::COLUMN_TIMELINE + MonthWeek::slot($start, $from),
            min($lastColumn, self::COLUMN_TIMELINE + MonthWeek::slot($end, $from)),
            $task->status === TaskStatus::Done,
        );
    }

    /**
     * A pale bar over the weeks the work runs, capped with a solid cell on the
     * closing week once it is finished — that cap is how a reader spots what
     * actually landed.
     */
    protected function drawBar(Worksheet $sheet, int $row, int $left, int $right, bool $done): void
    {
        if ($right < $left) {
            return;
        }

        if ($done) {
            $this->fill($sheet, $right, $row, $right, $row, self::BAR_DONE);
            $right--;
        }

        if ($right >= $left) {
            $this->fill($sheet, $left, $row, $right, $row, self::BAR);
        }
    }

    /**
     * Grey out the weeks after the last month this person has any work in. The
     * grid runs to the end of the year so the sheets share a width; this is
     * what keeps the unused tail from reading as idle time.
     *
     * @param  Collection<int, Task>  $tasks
     */
    protected function greyPastHorizon(Worksheet $sheet, Collection $tasks, int $first, int $last, CarbonInterface $from, int $lastColumn): void
    {
        $dates = $this->datesOf($tasks);

        if ($dates->isEmpty()) {
            return;
        }

        $horizon = Date::parse($dates->max());
        $month = intdiv(MonthWeek::slot($horizon, $from), MonthWeek::PER_MONTH);
        $left = self::COLUMN_TIMELINE + ($month + 1) * MonthWeek::PER_MONTH;

        if ($left <= $lastColumn) {
            $this->fill($sheet, $left, $first, $lastColumn, $last, self::OUTSIDE);
        }
    }

    /**
     * Progress of a project block: the average over its top level tasks, or
     * over everything when the block carries no root of its own — someone can
     * be assigned a sub task without owning its parent.
     *
     * @param  Collection<int, Task>  $group
     */
    protected function groupProgress(Collection $group): float
    {
        $roots = $group->where('depth', 0);
        $source = $roots->isEmpty() ? $group : $roots;

        return $source->isEmpty() ? 0.0 : round((float) $source->avg('progress'));
    }

    protected function fill(Worksheet $sheet, int $left, int $top, int $right, int $bottom, string $argb): void
    {
        $sheet->getStyle($this->area($left, $top, $right, $bottom))->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB($argb);
    }

    /** Rightmost timeline column for a header that spans `$monthCount` months. */
    protected function lastColumn(int $monthCount): int
    {
        return self::COLUMN_TIMELINE + $monthCount * MonthWeek::PER_MONTH - 1;
    }

    /** `B6:C9` for a pair of column/row coordinates. */
    protected function area(int $left, int $top, int $right, int $bottom): string
    {
        return $this->letter($left).$top.':'.$this->letter($right).$bottom;
    }

    protected function letter(int $column): string
    {
        return Coordinate::stringFromColumnIndex($column);
    }
}
