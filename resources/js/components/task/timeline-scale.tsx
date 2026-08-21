import { useMemo } from 'react';
import { cn } from '@/lib/utils';
import {
    MONTH_NAMES,
    addMonths,
    addWeeks,
    daysBetween,
    parseDate,
    startOfWeek,
    today,
    weekOfMonth,
} from '@/lib/week';

export type Zoom = 'week' | 'month' | 'quarter';

export type TimelineColumn = {
    start: Date;
    days: number;
    topLabel: string | null;
    bottomLabel: string;
};

export type TimelineScale = {
    columns: TimelineColumn[];
    /** Pixels per calendar day; every bar is positioned with this. */
    dayWidth: number;
    origin: Date;
    width: number;
};

/** Pixels per day at each zoom level (TML-4). */
const DAY_WIDTH: Record<Zoom, number> = {
    week: 6.5,
    month: 2.6,
    quarter: 1,
};

export const ZOOM_LABELS: Record<Zoom, string> = {
    week: 'Minggu',
    month: 'Bulan',
    quarter: 'Kuartal',
};

/**
 * Build the header columns and the day scale covering a set of date ranges.
 *
 * Bars are positioned purely from `dayWidth`, so switching zoom only changes
 * one number and the header grouping — never the bar maths.
 */
export function useTimelineScale(
    ranges: { start: string | null; end: string | null }[],
    zoom: Zoom,
): TimelineScale {
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

        const dayWidth = DAY_WIDTH[zoom];
        const origin = startOfWeek(addWeeks(min, -1));
        const end = addWeeks(startOfWeek(max), 2);

        const columns: TimelineColumn[] =
            zoom === 'week'
                ? weekColumns(origin, end)
                : monthColumns(origin, end, zoom);

        const width = columns.reduce(
            (total, column) => total + column.days * dayWidth,
            0,
        );

        return { columns, dayWidth, origin, width };
    }, [ranges, zoom]);
}

function weekColumns(origin: Date, end: Date): TimelineColumn[] {
    const columns: TimelineColumn[] = [];
    let cursor = new Date(origin);
    let previousMonth = -1;

    while (cursor <= end && columns.length < 400) {
        const month = cursor.getMonth();

        columns.push({
            start: new Date(cursor),
            days: 7,
            topLabel: month === previousMonth ? null : monthLabel(cursor),
            bottomLabel: `W${weekOfMonth(cursor)}`,
        });

        previousMonth = month;
        cursor = addWeeks(cursor, 1);
    }

    return columns;
}

function monthColumns(origin: Date, end: Date, zoom: Zoom): TimelineColumn[] {
    const columns: TimelineColumn[] = [];
    let cursor = new Date(origin.getFullYear(), origin.getMonth(), 1);
    let previousYear = -1;

    while (cursor <= end && columns.length < 400) {
        const next = addMonths(cursor, 1);
        const days = daysBetween(cursor, next);
        const year = cursor.getFullYear();
        const quarter = Math.floor(cursor.getMonth() / 3) + 1;

        columns.push({
            start: new Date(cursor),
            days,
            topLabel:
                zoom === 'quarter'
                    ? cursor.getMonth() % 3 === 0
                        ? `Q${quarter} ${year}`
                        : null
                    : year === previousYear
                      ? null
                      : String(year),
            bottomLabel: MONTH_NAMES[cursor.getMonth()],
        });

        previousYear = year;
        cursor = next;
    }

    return columns;
}

function monthLabel(date: Date): string {
    return `${MONTH_NAMES[date.getMonth()]} ${String(date.getFullYear()).slice(-2)}`;
}

/**
 * Two-row header: period on top, week or month underneath (TML-3).
 */
export function TimelineHeader({ scale }: { scale: TimelineScale }) {
    return (
        <div className="flex text-xs" style={{ width: `${scale.width}px` }}>
            {scale.columns.map((column, index) => (
                <div
                    key={index}
                    className={cn(
                        'shrink-0 px-0.5 py-1 text-center',
                        column.topLabel ? 'border-l border-border' : '',
                    )}
                    style={{ width: `${column.days * scale.dayWidth}px` }}
                >
                    <div className="truncate font-medium text-foreground/70">
                        {column.topLabel ?? ' '}
                    </div>
                    <div className="truncate text-muted-foreground tabular-nums">
                        {column.bottomLabel}
                    </div>
                </div>
            ))}
        </div>
    );
}

/**
 * A bar spanning start..end, filled to `progress` (TML-1, TML-7).
 */
export function TimelineBar({
    scale,
    start,
    end,
    progress,
    overdue = false,
    muted = false,
    label,
    onClick,
}: {
    scale: TimelineScale;
    start: string | null;
    end: string | null;
    progress: number;
    overdue?: boolean;
    /** Parent bars are drawn lighter, since their span is derived (TML-6). */
    muted?: boolean;
    label: string;
    onClick?: () => void;
}) {
    const startDate = parseDate(start);
    const endDate = parseDate(end);

    if (!startDate || !endDate) {
        return null;
    }

    const offset = daysBetween(scale.origin, startDate) * scale.dayWidth;
    const span = Math.max(
        scale.dayWidth,
        (daysBetween(startDate, endDate) + 1) * scale.dayWidth,
    );

    const Element = onClick ? 'button' : 'div';

    return (
        <Element
            type={onClick ? 'button' : undefined}
            onClick={onClick}
            title={label}
            aria-label={label}
            className={cn(
                'absolute top-1/2 h-4 -translate-y-1/2 overflow-hidden rounded-sm border text-left',
                muted
                    ? 'border-slate-300 bg-slate-100 dark:border-slate-700 dark:bg-slate-900'
                    : overdue
                      ? 'border-red-300 bg-red-100 dark:border-red-900 dark:bg-red-950'
                      : 'border-sky-300 bg-sky-100 dark:border-sky-900 dark:bg-sky-950',
                onClick && 'cursor-pointer hover:brightness-95',
            )}
            style={{ left: `${offset}px`, width: `${span}px` }}
        >
            <span
                className={cn(
                    'block h-full',
                    muted
                        ? 'bg-slate-400/60 dark:bg-slate-600/60'
                        : overdue
                          ? 'bg-red-400/70 dark:bg-red-800/70'
                          : 'bg-sky-400/70 dark:bg-sky-700/70',
                )}
                style={{ width: `${Math.max(0, Math.min(100, progress))}%` }}
            />
        </Element>
    );
}

/**
 * Vertical marker for today (TML-8).
 */
export function TimelineToday({ scale }: { scale: TimelineScale }) {
    const offset = daysBetween(scale.origin, today()) * scale.dayWidth;

    if (offset < 0 || offset > scale.width) {
        return null;
    }

    return (
        <div
            className="pointer-events-none absolute inset-y-0 w-px bg-red-500/70"
            style={{ left: `${offset}px` }}
            aria-hidden="true"
        />
    );
}
