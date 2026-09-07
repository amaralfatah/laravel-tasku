import { useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import {
    MONTH_NAMES,
    addMonths,
    addWeeks,
    daysBetween,
    parseDate,
    startOfWeek,
    startOfWeekColumn,
    today,
    weekOfMonth,
    weekStarts,
} from '@/lib/week';

export type Zoom = 'week' | 'month' | 'quarter';

type RawColumn = {
    start: Date;
    days: number;
    topLabel: string | null;
    bottomLabel: string;
};

export type TimelineColumn = RawColumn & {
    /** Drawn width in pixels, and the pixels from the origin to its left edge. */
    width: number;
    offset: number;
};

export type TimelineScale = {
    columns: TimelineColumn[];
    origin: Date;
    width: number;
    /**
     * Pixels from the origin to a date. A week column is drawn a fixed width
     * however many days it covers, so a date is placed by how far into its own
     * column it falls — never by counting days off the origin.
     */
    offsetOf: (date: Date) => number;
};

/** Pixels per day for the month and quarter zooms (TML-4). */
const DAY_WIDTH: Record<Zoom, number> = {
    week: 6.5,
    month: 2.6,
    quarter: 1,
};

/**
 * Width of one week column. The four columns of a month are drawn the same
 * width even though W1 can be a single day and W4 thirteen — a header that
 * reads W1 W2 W3 W4 in even steps is the point of the fixed grid.
 */
const WEEK_COLUMN_WIDTH = 7 * DAY_WIDTH.week;

/** Narrowest a bar may be drawn, so a one day task stays visible. */
const MIN_BAR_WIDTH = 2;

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
 * Bars are positioned through `offsetOf`, so switching zoom only changes the
 * columns and their widths — never the bar maths.
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
        // Start at the column the earliest date falls in, with nothing before
        // it. A blank week of padding read as work starting a month earlier: a
        // project opening on Sunday 1 June sits in the week of 26 May, and the
        // padding put a second, empty May column in front of that one.
        const origin =
            zoom === 'week' ? startOfWeekColumn(min) : startOfWeek(min);
        const end = addWeeks(startOfWeek(max), 2);

        const raw =
            zoom === 'week'
                ? weekColumns(origin, end)
                : monthColumns(origin, end, zoom);

        const widths = raw.map((column) =>
            zoom === 'week' ? WEEK_COLUMN_WIDTH : column.days * dayWidth,
        );

        const natural = widths.reduce(
            (total, columnWidth) => total + columnWidth,
            0,
        );

        // Stretch, never shrink: a span wider than the panel keeps its zoom and
        // scrolls, which is what the zoom buttons are for.
        const stretch =
            natural > 0 && fillWidth > natural ? fillWidth / natural : 1;

        const columns: TimelineColumn[] = [];
        let placed = 0;

        for (let index = 0; index < raw.length; index++) {
            const columnWidth = widths[index] * stretch;

            columns.push({ ...raw[index], width: columnWidth, offset: placed });
            placed += columnWidth;
        }

        return {
            columns,
            origin,
            width: placed,
            offsetOf: (date: Date) => offsetIn(columns, date),
        };
    }, [ranges, zoom, fillWidth]);
}

/**
 * Where a date sits along the drawn columns, interpolated across the column it
 * falls in. Dates outside the grid are carried on by the width of the column
 * nearest them, so a bar that runs off an edge still points the right way.
 */
function offsetIn(columns: TimelineColumn[], date: Date): number {
    if (columns.length === 0) {
        return 0;
    }

    let low = 0;
    let high = columns.length - 1;

    while (low < high) {
        const middle = Math.ceil((low + high) / 2);

        if (columns[middle].start <= date) {
            low = middle;
        } else {
            high = middle - 1;
        }
    }

    const column = columns[low];

    return (
        column.offset +
        (daysBetween(column.start, date) / column.days) * column.width
    );
}

/**
 * Four columns a month, so every month reads W1 through W4 however its days
 * fall. A column stops at the month boundary rather than running across it,
 * which is what keeps a month from opening on W2 — the days before its first
 * Monday are its own W1, not the tail of the month before.
 */
function weekColumns(origin: Date, end: Date): RawColumn[] {
    const columns: RawColumn[] = [];
    let cursor = new Date(origin);
    let previousMonth = -1;

    while (cursor <= end && columns.length < 400) {
        const month = cursor.getMonth();
        const week = weekOfMonth(cursor);
        const starts = weekStarts(cursor.getFullYear(), month);
        const next =
            starts[week] ?? new Date(cursor.getFullYear(), month + 1, 1);

        columns.push({
            start: new Date(cursor),
            days: daysBetween(cursor, next),
            topLabel: month === previousMonth ? null : monthLabel(cursor),
            bottomLabel: `W${week}`,
        });

        previousMonth = month;
        cursor = next;
    }

    return columns;
}

function monthColumns(origin: Date, end: Date, zoom: Zoom): RawColumn[] {
    const columns: RawColumn[] = [];
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

/** The day after a date, so a bar can be measured to the end of its last day. */
function dayAfter(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate() + 1);
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
        const groups: { label: string; width: number }[] = [];

        for (const column of scale.columns) {
            if (column.topLabel !== null || groups.length === 0) {
                groups.push({
                    label: column.topLabel ?? '',
                    width: column.width,
                });

                continue;
            }

            groups[groups.length - 1].width += column.width;
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
                        style={{ width: `${period.width}px` }}
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
                                  : 'border-l border-border/30',
                        )}
                        style={{ width: `${column.width}px` }}
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

    const offset = scale.offsetOf(startDate);
    const span = Math.max(
        MIN_BAR_WIDTH,
        scale.offsetOf(dayAfter(endDate)) - offset,
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
 * Month boundaries carry the full `border` colour, week boundaries a third of
 * it, so the weeks read as the finer grid behind them. The header band uses the
 * same pair, so a line keeps one weight from the label all the way down.
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
                              : 'border-l border-border/30',
                    )}
                    style={{ width: `${column.width}px` }}
                />
            ))}
        </div>
    );
}

export function TimelineToday({ scale }: { scale: TimelineScale }) {
    const offset = scale.offsetOf(today());

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
