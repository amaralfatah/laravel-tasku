import { useMemo } from 'react';
import { cn } from '@/lib/utils';
import {
    MONTH_NAMES,
    addWeeks,
    daysBetween,
    parseDate,
    startOfWeek,
    today,
    weekOfMonth,
} from '@/lib/week';

export type WeekColumn = {
    /** Monday of the week. */
    start: Date;
    monthLabel: string;
    weekLabel: string;
    isFirstOfMonth: boolean;
};

/**
 * Build the week columns covering a set of date ranges, with a little padding
 * on both sides so bars are not flush against the edge.
 */
export function useWeekColumns(
    ranges: { start: string | null; end: string | null }[],
    padWeeks = 1,
): WeekColumn[] {
    return useMemo(() => {
        const dates = ranges
            .flatMap((range) => [parseDate(range.start), parseDate(range.end)])
            .filter((date): date is Date => date !== null);

        const anchor = today();
        const min = dates.length
            ? new Date(Math.min(...dates.map((date) => date.getTime())))
            : anchor;
        const max = dates.length
            ? new Date(Math.max(...dates.map((date) => date.getTime())))
            : anchor;

        let cursor = addWeeks(startOfWeek(min), -padWeeks);
        const last = addWeeks(startOfWeek(max), padWeeks);
        const columns: WeekColumn[] = [];
        let previousMonth = -1;

        // Hard stop so a stray far-future date cannot render thousands of columns.
        while (cursor <= last && columns.length < 260) {
            const month = cursor.getMonth();

            columns.push({
                start: new Date(cursor),
                monthLabel: `${MONTH_NAMES[month]} ${String(cursor.getFullYear()).slice(-2)}`,
                weekLabel: `W${weekOfMonth(cursor)}`,
                isFirstOfMonth: month !== previousMonth,
            });

            previousMonth = month;
            cursor = addWeeks(cursor, 1);
        }

        return columns;
    }, [ranges, padWeeks]);
}

/**
 * Two-row header: month and year on top, week number underneath (TML-3).
 */
export function WeekStripHeader({
    columns,
    columnWidth,
}: {
    columns: WeekColumn[];
    columnWidth: number;
}) {
    return (
        <div
            className="grid border-b bg-muted/40 text-xs"
            style={{
                gridTemplateColumns: `repeat(${columns.length}, ${columnWidth}px)`,
            }}
        >
            {columns.map((column, index) => (
                <div
                    key={index}
                    className={cn(
                        'border-l px-1 py-1 text-center text-muted-foreground',
                        column.isFirstOfMonth
                            ? 'border-l-border'
                            : 'border-l-transparent',
                    )}
                >
                    <div className="truncate font-medium text-foreground/70">
                        {column.isFirstOfMonth ? column.monthLabel : ' '}
                    </div>
                    <div className="tabular-nums">{column.weekLabel}</div>
                </div>
            ))}
        </div>
    );
}

/**
 * A single bar positioned across the week columns.
 *
 * The filled portion reflects progress (TML-7), and the whole strip renders as
 * a grid so nothing depends on measuring the DOM.
 */
export function WeekBar({
    columns,
    columnWidth,
    start,
    end,
    progress,
    overdue = false,
    label,
}: {
    columns: WeekColumn[];
    columnWidth: number;
    start: string | null;
    end: string | null;
    progress: number;
    overdue?: boolean;
    label: string;
}) {
    const startDate = parseDate(start);
    const endDate = parseDate(end);

    if (!startDate || !endDate || columns.length === 0) {
        return null;
    }

    const origin = columns[0].start;
    const offsetDays = daysBetween(origin, startDate);
    const spanDays = Math.max(1, daysBetween(startDate, endDate) + 1);
    const perDay = columnWidth / 7;

    return (
        <div
            className={cn(
                'absolute top-1/2 h-4 -translate-y-1/2 overflow-hidden rounded-sm border',
                overdue
                    ? 'border-red-300 bg-red-100 dark:border-red-900 dark:bg-red-950'
                    : 'border-sky-300 bg-sky-100 dark:border-sky-900 dark:bg-sky-950',
            )}
            style={{
                left: `${offsetDays * perDay}px`,
                width: `${Math.max(perDay, spanDays * perDay)}px`,
            }}
            title={label}
        >
            <div
                className={cn(
                    'h-full',
                    overdue
                        ? 'bg-red-400/70 dark:bg-red-800/70'
                        : 'bg-sky-400/70 dark:bg-sky-700/70',
                )}
                style={{ width: `${Math.max(0, Math.min(100, progress))}%` }}
            />
            <span className="sr-only">{label}</span>
        </div>
    );
}

/**
 * Vertical marker for the current week (TML-8).
 */
export function TodayMarker({
    columns,
    columnWidth,
}: {
    columns: WeekColumn[];
    columnWidth: number;
}) {
    if (columns.length === 0) {
        return null;
    }

    const offsetDays = daysBetween(columns[0].start, today());
    const left = offsetDays * (columnWidth / 7);

    if (left < 0 || left > columns.length * columnWidth) {
        return null;
    }

    return (
        <div
            className="pointer-events-none absolute inset-y-0 w-px bg-red-500/70"
            style={{ left: `${left}px` }}
            aria-hidden="true"
        />
    );
}
