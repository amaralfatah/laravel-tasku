import { useEffect, useMemo, useRef, useState } from 'react';
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
 * The zoom a set of ranges fits into, used as the level a page opens on.
 *
 * A year of work at week zoom is roughly three times the width of the screen,
 * so most bars start outside it — and a row whose bar is off to the right reads
 * exactly like one that was never scheduled. Opening wide enough to show the
 * whole span is what keeps those two apart.
 */
export function fittingZoom(
    ranges: { start: string | null; end: string | null }[],
): Zoom {
    const dates = ranges
        .flatMap((range) => [parseDate(range.start), parseDate(range.end)])
        .filter((date): date is Date => date !== null);

    if (dates.length === 0) {
        return 'week';
    }

    const times = dates.map((date) => date.getTime());
    const months =
        daysBetween(
            new Date(Math.min(...times)),
            new Date(Math.max(...times)),
        ) / 30;

    if (months <= 4) {
        return 'week';
    }

    return months <= 24 ? 'month' : 'quarter';
}

/**
 * Width the grid may occupy: the scroll panel minus its sticky label column.
 *
 * Returned as 0 until the element is measured, which reads as "do not stretch"
 * and keeps the first paint at the plain zoom width.
 */
export function useFillWidth<T extends HTMLElement>(
    labelWidth: number,
): [React.RefObject<T | null>, number] {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(([entry]) => {
            setWidth(Math.max(0, entry.contentRect.width - labelWidth));
        });

        observer.observe(element);

        return () => observer.disconnect();
    }, [labelWidth]);

    return [ref, width];
}

/**
 * Build the header columns and the day scale covering a set of date ranges.
 *
 * Bars are positioned purely from `dayWidth`, so switching zoom only changes
 * one number and the header grouping — never the bar maths.
 */
export function useTimelineScale(
    ranges: { start: string | null; end: string | null }[],
    zoom: Zoom,
    /**
     * Width the grid should fill when the data is narrower than the screen.
     * A short project at quarter zoom would otherwise draw a few hundred
     * pixels of bars and leave the rest of the panel blank.
     */
    fillWidth = 0,
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
        // Start at the week the earliest date falls in, with nothing before it.
        // A blank week of padding read as work starting a month earlier: a
        // project opening on Sunday 1 June sits in the week of 26 May, and the
        // padding put a second, empty May column in front of that one.
        const origin = startOfWeek(min);
        const end = addWeeks(startOfWeek(max), 2);

        const columns: TimelineColumn[] =
            zoom === 'week'
                ? weekColumns(origin, end)
                : monthColumns(origin, end, zoom);

        const days = columns.reduce((total, column) => total + column.days, 0);
        const width = days * dayWidth;

        // Stretch, never shrink: a span wider than the panel keeps its zoom and
        // scrolls, which is what the zoom buttons are for.
        if (fillWidth > width && days > 0) {
            return {
                columns,
                dayWidth: fillWidth / days,
                origin,
                width: fillWidth,
            };
        }

        return { columns, dayWidth, origin, width };
    }, [ranges, zoom, fillWidth]);
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
    // The period label belongs to the whole run of columns it covers, not to
    // the first of them: a month written into one 45px week column, or a
    // quarter into one 30px month column, is only ever read as `Agu ...`.
    const periods = useMemo(() => {
        const groups: { label: string; days: number }[] = [];

        for (const column of scale.columns) {
            if (column.topLabel !== null || groups.length === 0) {
                groups.push({
                    label: column.topLabel ?? '',
                    days: column.days,
                });

                continue;
            }

            groups[groups.length - 1].days += column.days;
        }

        return groups;
    }, [scale.columns]);

    return (
        // Stretched by the band, with the week row taking the slack, so a
        // column's border reaches the bottom of the band and meets the grid
        // line under it. Sizing itself instead leaves it a pixel or two short
        // of the taller label column beside it, and the line breaks there —
        // which is also why there is no `h-full` here: an explicit height
        // cancels the stretch and hands back the same gap.
        <div
            className="flex flex-col text-xs"
            style={{ width: `${scale.width}px` }}
        >
            <div className="flex">
                {periods.map((period, index) => (
                    <div
                        key={index}
                        className={cn(
                            'shrink-0 truncate px-1 pt-1 font-medium text-foreground/70',
                            index > 0 ? 'border-l-2 border-border' : '',
                        )}
                        style={{ width: `${period.days * scale.dayWidth}px` }}
                    >
                        {period.label}
                    </div>
                ))}
            </div>

            <div className="flex flex-1">
                {scale.columns.map((column, index) => (
                    <div
                        key={index}
                        className={cn(
                            'shrink-0 truncate px-0.5 pb-1 text-center text-muted-foreground tabular-nums',
                            // Weight, not colour: the band sits on `muted` and
                            // the rows on the page, so a paler line reads as a
                            // different colour rather than a softer one.
                            index === 0
                                ? ''
                                : column.topLabel
                                  ? 'border-l-2 border-border'
                                  : 'border-l border-border',
                        )}
                        style={{ width: `${column.days * scale.dayWidth}px` }}
                    >
                        {column.bottomLabel}
                    </div>
                ))}
            </div>
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
                    ? 'border-border bg-muted'
                    : overdue
                      ? 'border-destructive/30 bg-destructive/10'
                      : 'border-primary/30 bg-primary/10',
                onClick && 'cursor-pointer hover:brightness-95',
            )}
            style={{ left: `${offset}px`, width: `${span}px` }}
        >
            <span
                className={cn(
                    'block h-full',
                    muted
                        ? 'bg-muted-foreground'
                        : overdue
                          ? 'bg-destructive'
                          : 'bg-primary',
                )}
                style={{ width: `${Math.max(0, Math.min(100, progress))}%` }}
            />
        </Element>
    );
}

/**
 * Vertical marker for today (TML-8).
 */
/**
 * The column boundaries of the current zoom, drawn behind the bars.
 *
 * Without them a bar's ends have to be read against a header three rows up,
 * which is guesswork on a wide grid.
 *
 * Drawn as the same row of bordered columns the header uses, not as absolutely
 * positioned rules. A column is rarely a whole number of pixels wide, and a
 * 1px div placed at a fractional offset is antialiased across two device
 * pixels — thinner and paler than the header's border at the same boundary.
 * Laying the columns out the same way makes the browser round them the same
 * way, so the line runs unbroken from the label down.
 *
 * Every line is the full `border` colour. A lighter weight for the weeks read
 * as a different colour rather than a softer one, since the header band sits
 * on `muted` and the rows on the page.
 */
export function TimelineGridLines({ scale }: { scale: TimelineScale }) {
    return (
        <div
            className="pointer-events-none absolute inset-0 flex"
            aria-hidden="true"
        >
            {scale.columns.map((column, index) => (
                <div
                    key={index}
                    className={cn(
                        'shrink-0',
                        index === 0
                            ? ''
                            : column.topLabel
                              ? 'border-l-2 border-border'
                              : 'border-l border-border',
                    )}
                    style={{ width: `${column.days * scale.dayWidth}px` }}
                />
            ))}
        </div>
    );
}

export function TimelineToday({ scale }: { scale: TimelineScale }) {
    const offset = daysBetween(scale.origin, today()) * scale.dayWidth;

    if (offset < 0 || offset > scale.width) {
        return null;
    }

    return (
        <div
            className="pointer-events-none absolute inset-y-0 w-px bg-destructive/70"
            style={{ left: `${offset}px` }}
            aria-hidden="true"
        />
    );
}
