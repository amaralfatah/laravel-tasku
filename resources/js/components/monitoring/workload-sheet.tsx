import { useEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    buildSheetGrid,
    columnLetter,
    excelWidth,
    sheetRange,
} from '@/lib/sheet-grid';
import type { SheetBand, SheetGrid, SheetZoom } from '@/lib/sheet-grid';
import { parseDate, today } from '@/lib/week';
import { STATUS_CATEGORY } from '@/types/tasks';
import type { TaskNode } from '@/types/tasks';

/**
 * The per-programmer workbook, drawn on screen.
 *
 * This is the table `App\Services\WorkloadExport` writes, cell for cell: the
 * year/group/unit header, the WBS lines under it, and the pale bar with a solid
 * cap on the closing column of finished work. People compare this page against
 * older copies of `ContohLaporan.xlsx`, so the colours, the widths and the
 * numbering are the file's — do not restyle them to match the rest of the app.
 *
 * The workbook's two opening blocks are left to the file: it carries its own
 * heading and its own totals because it is read on its own, while this page is
 * already headed by the avatar, the name and the unit.
 *
 * Type is set at 13px, not at the 16px a 12pt cell renders as. Every width here
 * comes from the workbook's character units through {@link excelWidth}, which
 * counts Excel's own narrow default face; at 16px in this application's stack a
 * `W3 08-26` no longer fits the column that is sized to hold it. The widths are
 * the file's and stay — the type is what gives. The one exception is
 * {@link PROGRESS_WIDTH}, and it says why.
 *
 * The page is read through a spreadsheet frame — row numbers down the left,
 * column letters across the top. Both are the page's own addresses, counted off
 * what is drawn: rows from 1 and columns from A on TASK. See {@link FIRST_ROW}
 * and {@link FIXED_COLUMNS} for why neither follows the file's numbering.
 *
 * What the page keeps that the file cannot is behaviour and theme, never
 * layout: the frame and the four fixed columns stay put while the timeline
 * scrolls, a title opens the task, and the palette follows light or dark. See
 * the fills below — every value is the workbook's in light mode, and the dark
 * set is the same sheet read on the dark surface ladder.
 */

/**
 * Every fill the sheet uses, read off a custom property so the one set of rules
 * below serves both themes.
 *
 * Light is the workbook's own palette, each value its ARGB minus the alpha
 * channel. Dark is the same sheet read in the dark: the paper, the header band,
 * the project line and the out-of-scope shading step down onto the surface
 * ladder in `app.css`, and the bar keeps its hue at a weight that survives
 * there. Only the cap on finished work is the same colour in both — it is the
 * one mark a reader hunts for, and moving it would break the habit.
 *
 * `--sheet-rule` is the one value that is not taken from that ladder. Excel has
 * no dark sheet — the paper stays white under a black border, at a contrast the
 * app's `--border` hairline comes nowhere near. A hairline separates panels,
 * which are legible without it; here the grid is what is being read, so the
 * rule is lifted well clear of the paper. The frame's own rule stays quieter,
 * as Excel's row and column headers are quieter than a cell border.
 */
const HEADER = 'var(--sheet-header)';
const PROJECT_ROW = 'var(--sheet-project)';
const BAR = 'var(--sheet-bar)';
const BAR_DONE = 'var(--sheet-done)';
const OUTSIDE = 'var(--sheet-outside)';
const PAPER = 'var(--sheet-paper)';
const RULE = '1px solid var(--sheet-rule)';

/** The palette itself, scoped to the sheet so nothing else inherits it. */
const PALETTE = [
    '[--sheet-paper:#ffffff] [--sheet-ink:#000000] [--sheet-rule:#000000]',
    '[--sheet-header:#f2f2f2] [--sheet-project:#e8e8e8]',
    '[--sheet-bar:#daf2d0] [--sheet-done:#4ea72e] [--sheet-outside:#d0d0d0]',
    '[--sheet-frame:#f0f0f0] [--sheet-frame-ink:#5f5f5f] [--sheet-frame-rule:#d0d0d0]',
    '[--sheet-select:rgba(78,167,46,0.16)] [--sheet-frame-active:#cfe6c6]',
    'dark:[--sheet-paper:#242528] dark:[--sheet-ink:#cecfd2] dark:[--sheet-rule:#5c5e64]',
    'dark:[--sheet-header:#2c2c2e] dark:[--sheet-project:#303134]',
    'dark:[--sheet-bar:#2c4a32] dark:[--sheet-outside:#18191a]',
    'dark:[--sheet-frame:#2c2c2e] dark:[--sheet-frame-ink:#8b8d91] dark:[--sheet-frame-rule:#45474c]',
    'dark:[--sheet-select:rgba(110,200,80,0.18)] dark:[--sheet-frame-active:#3c5a40]',
].join(' ');

/**
 * Below `md` the four fixed columns are released and only the row-number
 * gutter stays pinned.
 *
 * Pinned, the four of them are 626px wide — wider than any phone — so they
 * cover the viewport whole and the timeline slides underneath them unseen: the
 * sheet does scroll, but nothing on screen moves, which reads as a table that
 * cannot be scrolled at all. The gutter is 42px and keeps the reader's place.
 * `static!` because `pinned()` writes `position` inline, and a class cannot
 * outrank that otherwise.
 */
const UNPIN_NARROW = 'max-md:[&_[data-pin=column]]:static!';

/**
 * The row-number gutter down the left edge, in pixels. Not a column of the
 * sheet — it is the spreadsheet frame, the way Excel draws one.
 */
const GUTTER_WIDTH = 42;

/** The fixed columns, at the widths `writeColumns()` sets. */
const TASK_WIDTH = excelWidth(49.18);
const START_WIDTH = excelWidth(10.73);
const END_WIDTH = excelWidth(10.73);

/**
 * PROGRESS is the one width the page does not take from the file.
 *
 * The workbook gives it 15.18 characters, which is sized for the header rather
 * than the number under it: every value is at most `100%`, so the column read as
 * a gap with a figure pushed against its right edge. Ten characters still hold
 * the word and stop the number drifting away from the bar it describes. The
 * file keeps its own width — a sheet is measured for print, this is not.
 */
const PROGRESS_WIDTH = excelWidth(10);

/** Left offset of each pinned column, the gutter sitting ahead of them. */
const TASK_LEFT = GUTTER_WIDTH;
const PROGRESS_LEFT = TASK_LEFT + TASK_WIDTH;
const START_LEFT = PROGRESS_LEFT + PROGRESS_WIDTH;
const END_LEFT = START_LEFT + START_WIDTH;

export const FIXED_WIDTH = END_LEFT + END_WIDTH;

/**
 * How many columns stand before the timeline: TASK, PROGRESS, START and END,
 * lettered A through D, with the timeline opening on E.
 *
 * The workbook keeps a blank margin column ahead of TASK and so letters it B,
 * but a margin is a printing concern and the page is not printed — it only put
 * an empty strip on screen and pushed every letter one along. The lettering is
 * the page's own, as the row numbers are.
 */
const FIXED_COLUMNS = 4;

/**
 * Rows are numbered from 1, off the top of what is drawn.
 *
 * They deliberately do not carry the file's own row numbers. The workbook opens
 * on a heading and a summary block the page leaves out, so matching it would
 * start this table at row 13 or lower with nothing above to explain the gap —
 * which reads as something missing rather than as something skipped.
 */
const FIRST_ROW = 1;

/** Year, group and unit: the header band is three rows in both. */
const HEADER_ROWS = 3;

/** A default Excel row, in pixels. */
const ROW_HEIGHT = 20;

/** Breathing room left of a bar scrolled into view, in pixels. */
const SLIDE_GUTTER = 24;

/** How long the sheet takes to slide to a clicked row's bar, in milliseconds. */
const SLIDE_MS = 420;

/**
 * What the frame has picked out: one whole row, or one whole column, the way
 * clicking a number or a letter selects one in a spreadsheet. Only ever one at
 * a time — there is nothing here to do with a selection but read it, so ranges
 * and multiple picks would be chrome with no purpose behind it.
 */
type Selection =
    { kind: 'row'; row: number } | { kind: 'column'; column: number } | null;

/** Zero based column of the sheet: TASK is 0, the timeline opens at 4. */
const TIMELINE_FROM = FIXED_COLUMNS;

function rowPicked(selection: Selection, row: number): boolean {
    return selection?.kind === 'row' && selection.row === row;
}

/** Whether the pick falls inside a cell that starts at `from` and spans `span`. */
function columnPicked(selection: Selection, from: number, span = 1): boolean {
    return (
        selection?.kind === 'column' &&
        selection.column >= from &&
        selection.column < from + span
    );
}

/**
 * The wash a picked cell carries, laid over whatever fill it already has —
 * a bar, the header band or plain paper all keep showing through.
 *
 * `none` rather than an absent key: dropping `backgroundImage` on a rerender
 * while the cell's own fill is set is what React warns about, and the cells
 * this is spread onto set their fill through `backgroundColor` for the same
 * reason — the `background` shorthand would clear this wash outright.
 */
function picked(active: boolean): CSSProperties {
    return {
        backgroundImage: active
            ? 'linear-gradient(var(--sheet-select), var(--sheet-select))'
            : 'none',
    };
}

export type SheetProjectGroup = {
    project: { id: number; name: string; key: string };
    tasks: TaskNode[];
};

type Row = {
    /** Null on a project line, which stands for no task of its own. */
    task: TaskNode | null;
    /** `1. Aplikasi` for a project line, `1.2.1 Judul` for a task. */
    label: string;
    progress: number;
    start: Date | null;
    end: Date | null;
    done: boolean;
    isProject: boolean;
};

export function WorkloadSheet({
    groups,
    zoom,
    onOpenTask,
}: {
    groups: SheetProjectGroup[];
    zoom: SheetZoom;
    onOpenTask: (id: number) => void;
}) {
    /** The row last scrolled to, kept ringed so the eye finds it again. */
    const [focusedTaskId, setFocusedTaskId] = useState<number | null>(null);
    /** The row or column picked off the frame; clicking it again clears it. */
    const [selection, setSelection] = useState<Selection>(null);
    const panel = useRef<HTMLDivElement>(null);
    /** Below `md` the fixed columns scroll away with the rest — see {@link UNPIN_NARROW}. */
    const isNarrow = useIsMobile();
    /** The slide in flight, so a second click takes over the first. */
    const slide = useRef(0);

    useEffect(() => () => cancelAnimationFrame(slide.current), []);

    const tasks = useMemo(
        () => groups.flatMap((group) => group.tasks),
        [groups],
    );

    const grid = useMemo(() => {
        const [from, to] = sheetRange(datesOf(tasks));

        return buildSheetGrid(zoom, from, to);
    }, [tasks, zoom]);

    const rows = useMemo(() => buildRows(groups), [groups]);

    // Everything past the column today falls in is greyed, the way the sheet
    // shades the weeks that have not happened yet.
    const todaySlot = grid.slot(today());
    const lastSlot = grid.columns.length - 1;
    const width = FIXED_WIDTH + grid.columns.length * grid.width;

    /**
     * Bring a row's bar into view when its row is clicked.
     *
     * Someone's work spans every project they touch, so a bar is often far off
     * screen from the row that names it. The sheet is slid so the bar opens
     * just past the fixed columns, which is what the reader is after.
     *
     * Animated here rather than through `scrollTo({ behavior: 'smooth' })`,
     * which browsers drop to an instant jump wherever the OS asks for reduced
     * motion — and a jump across a year of weeks reads as the sheet having
     * been replaced rather than moved.
     */
    const slideTo = (task: TaskNode, slot: number) => {
        const element = panel.current;

        if (element === null) {
            return;
        }

        setFocusedTaskId(task.id);

        const from = element.scrollLeft;
        const to = Math.max(
            0,
            Math.min(
                // Pinned, the fixed columns are cleared at exactly the bar's
                // own offset into the timeline; released, the timeline starts
                // past them and their width has to be scrolled off first.
                (isNarrow ? FIXED_WIDTH : 0) + slot * grid.width - SLIDE_GUTTER,
                element.scrollWidth - element.clientWidth,
            ),
        );

        cancelAnimationFrame(slide.current);

        if (from === to) {
            return;
        }

        // Timed from the first animation frame rather than the click, so a
        // frame the browser was late to deliver does not eat the slide.
        let startedAt = 0;

        const step = (now: number) => {
            startedAt = startedAt || now;

            const progress = Math.min(1, (now - startedAt) / SLIDE_MS);

            // Ease out: quick off the mark, settling on the target, so the
            // distance travelled reads without the wait feeling long.
            element.scrollLeft =
                from + (to - from) * (1 - Math.pow(1 - progress, 3));

            if (progress < 1) {
                slide.current = requestAnimationFrame(step);
            }
        };

        slide.current = requestAnimationFrame(step);
    };

    const pick = (next: NonNullable<Selection>) =>
        setSelection((current) =>
            current !== null &&
            current.kind === next.kind &&
            (current.kind === 'row'
                ? current.row === (next as { row: number }).row
                : current.column === (next as { column: number }).column)
                ? null
                : next,
        );

    return (
        <div
            ref={panel}
            className={`overflow-x-auto border bg-[var(--sheet-paper)] text-[var(--sheet-ink)] ${UNPIN_NARROW} ${PALETTE}`}
        >
            <div style={{ width: `${width}px` }}>
                {/* The two blocks the workbook opens with — `PROJECT
                    MANAGEMENT` over the person's name, and the green banner
                    over the application list and the counters — are left to the
                    file. A sheet has to carry its own heading and totals
                    because it is read on its own; this page is already headed
                    by the avatar, the name and the unit, and the table is what
                    people came for. */}
                <table
                    className="table-fixed border-separate"
                    style={{ borderSpacing: 0, width: `${width}px` }}
                >
                    <colgroup>
                        <col style={{ width: `${GUTTER_WIDTH}px` }} />
                        <col style={{ width: `${TASK_WIDTH}px` }} />
                        <col style={{ width: `${PROGRESS_WIDTH}px` }} />
                        <col style={{ width: `${START_WIDTH}px` }} />
                        <col style={{ width: `${END_WIDTH}px` }} />
                        {grid.columns.map((_, index) => (
                            <col
                                key={index}
                                style={{ width: `${grid.width}px` }}
                            />
                        ))}
                    </colgroup>

                    <SheetHeader
                        grid={grid}
                        firstRow={FIRST_ROW}
                        selection={selection}
                        onPick={pick}
                    />

                    <tbody>
                        {rows.map((row, index) => (
                            <SheetRow
                                key={row.task ? `t${row.task.id}` : `p${index}`}
                                row={row}
                                grid={grid}
                                number={FIRST_ROW + HEADER_ROWS + index}
                                todaySlot={todaySlot}
                                lastSlot={lastSlot}
                                lastRow={index === rows.length - 1}
                                focused={row.task?.id === focusedTaskId}
                                selection={selection}
                                onPick={pick}
                                onPickRow={slideTo}
                                onOpenTask={onOpenTask}
                            />
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/**
 * Three header rows — year, month or quarter, then the grid's own units.
 *
 * Every zoom draws the same three, which is what keeps the geometry under them
 * identical between zooms; only the band labels and the column width change.
 *
 * The four fixed columns are merged down all three, so the header closes as one
 * block: the workbook used to leave the cell above TASK blank, which read as a
 * gap torn out of the top left corner.
 */
function SheetHeader({
    grid,
    firstRow,
    selection,
    onPick,
}: {
    grid: SheetGrid;
    firstRow: number;
    selection: Selection;
    onPick: (selection: NonNullable<Selection>) => void;
}) {
    const headerRows = [firstRow, firstRow + 1, firstRow + 2];

    /** A fixed column's cell spans all three header rows, so any of them hits. */
    const fixedPicked = (column: number) =>
        columnPicked(selection, column) ||
        headerRows.some((row) => rowPicked(selection, row));

    return (
        <thead>
            {/* The spreadsheet frame: the corner, then one letter per column.
                Clicking a letter picks that whole column out, the way it does
                in a spreadsheet; clicking it again puts it back. */}
            <tr style={{ height: `${ROW_HEIGHT}px` }}>
                <th style={{ ...frameCell, ...pinned(0), zIndex: 3 }} />
                {Array.from({
                    length: FIXED_COLUMNS + grid.columns.length,
                }).map((_, index) => (
                    <th
                        key={index}
                        style={{
                            ...frameCell,
                            ...frameActive(
                                columnPicked(selection, index) ||
                                    selection?.kind === 'row',
                            ),
                            ...(index < FIXED_COLUMNS
                                ? { ...pinned(FRAME_LEFT[index]), zIndex: 3 }
                                : {}),
                        }}
                        data-pin={index < FIXED_COLUMNS ? 'column' : undefined}
                    >
                        <FrameButton
                            label={`Pilih kolom ${columnLetter(index + 1)}`}
                            onClick={() =>
                                onPick({ kind: 'column', column: index })
                            }
                        >
                            {columnLetter(index + 1)}
                        </FrameButton>
                    </th>
                ))}
            </tr>

            <tr style={{ height: `${ROW_HEIGHT}px` }}>
                <RowNumber
                    number={firstRow}
                    active={
                        rowPicked(selection, firstRow) ||
                        selection?.kind === 'column'
                    }
                    onPick={onPick}
                />
                <th
                    rowSpan={3}
                    className="pl-2 text-left"
                    data-pin="column"
                    style={{
                        ...headerCell(true),
                        ...pinned(TASK_LEFT),
                        ...picked(fixedPicked(0)),
                    }}
                >
                    TASK
                </th>
                <th
                    rowSpan={3}
                    data-pin="column"
                    style={{
                        ...headerCell(false),
                        ...pinned(PROGRESS_LEFT),
                        ...picked(fixedPicked(1)),
                    }}
                >
                    PROGRESS
                </th>
                <th
                    rowSpan={3}
                    data-pin="column"
                    style={{
                        ...headerCell(false),
                        ...pinned(START_LEFT),
                        ...picked(fixedPicked(2)),
                    }}
                >
                    START
                </th>
                <th
                    rowSpan={3}
                    data-pin="column"
                    style={{
                        ...headerCell(false),
                        ...pinned(END_LEFT),
                        ...picked(fixedPicked(3)),
                    }}
                >
                    END
                </th>
                <BandCells
                    bands={grid.years}
                    selection={selection}
                    row={firstRow}
                />
            </tr>

            <tr style={{ height: `${ROW_HEIGHT}px` }}>
                <RowNumber
                    number={firstRow + 1}
                    active={
                        rowPicked(selection, firstRow + 1) ||
                        selection?.kind === 'column'
                    }
                    onPick={onPick}
                />
                <BandCells
                    bands={grid.groups}
                    selection={selection}
                    row={firstRow + 1}
                />
            </tr>

            <tr style={{ height: `${ROW_HEIGHT}px` }}>
                <RowNumber
                    number={firstRow + 2}
                    active={
                        rowPicked(selection, firstRow + 2) ||
                        selection?.kind === 'column'
                    }
                    onPick={onPick}
                />
                {grid.units.map((unit, index) => (
                    <th
                        key={index}
                        style={{
                            ...headerCell(false),
                            fontWeight: 400,
                            ...picked(
                                columnPicked(
                                    selection,
                                    TIMELINE_FROM + index,
                                ) || rowPicked(selection, firstRow + 2),
                            ),
                        }}
                    >
                        {unit}
                    </th>
                ))}
            </tr>
        </thead>
    );
}

function SheetRow({
    row,
    grid,
    number,
    todaySlot,
    lastSlot,
    lastRow,
    focused,
    selection,
    onPick,
    onPickRow,
    onOpenTask,
}: {
    row: Row;
    grid: SheetGrid;
    /** The row's number down the frame on the left edge. */
    number: number;
    todaySlot: number;
    lastSlot: number;
    lastRow: boolean;
    focused: boolean;
    selection: Selection;
    onPick: (selection: NonNullable<Selection>) => void;
    onPickRow: (task: TaskNode, slot: number) => void;
    onOpenTask: (id: number) => void;
}) {
    const task = row.task;
    const left = row.start === null ? null : grid.slot(row.start);
    const right =
        row.end === null ? null : Math.min(lastSlot, grid.slot(row.end));

    const paper = row.isProject ? PROJECT_ROW : PAPER;
    const weight = row.isProject ? 700 : 400;

    const isRowPicked = rowPicked(selection, number);

    const fixed = (
        column: number,
        pin: number,
        first = false,
    ): CSSProperties => ({
        ...bodyCell(lastRow, first),
        ...pinned(pin),
        backgroundColor: paper,
        fontWeight: weight,
        ...picked(isRowPicked || columnPicked(selection, column)),
    });

    return (
        <tr
            style={{ height: `${ROW_HEIGHT}px` }}
            className={task === null ? undefined : 'cursor-pointer'}
            onClick={
                task === null || left === null
                    ? undefined
                    : () => onPickRow(task, left)
            }
        >
            {/* Picking a row off the frame also brings its bar into view. The
                number and the bar are at opposite ends of a grid a year wide,
                so a pick that only tinted the row would light up a stretch of
                empty columns and leave the work itself off screen. */}
            <RowNumber
                number={number}
                active={isRowPicked || selection?.kind === 'column'}
                onPick={(next) => {
                    onPick(next);

                    if (task !== null && left !== null) {
                        onPickRow(task, left);
                    }
                }}
            />

            <td
                className="pr-1 text-[13px] leading-[15px]"
                data-pin="column"
                style={{
                    ...fixed(0, TASK_LEFT, true),
                    // The same indent step the project tree and the timeline
                    // use, so a sub task still reads as one here.
                    paddingLeft: `${8 + (task?.depth ?? 0) * 14}px`,
                    whiteSpace: 'normal',
                    outline: focused ? `2px solid ${BAR_DONE}` : undefined,
                    outlineOffset: '-2px',
                }}
            >
                {task === null ? (
                    row.label
                ) : (
                    <button
                        type="button"
                        title={row.label}
                        className="text-left hover:underline"
                        onClick={(event) => {
                            event.stopPropagation();
                            onOpenTask(task.id);
                        }}
                    >
                        {row.label}
                    </button>
                )}
            </td>

            <td
                className="px-1 text-right text-[13px] tabular-nums"
                data-pin="column"
                style={fixed(1, PROGRESS_LEFT)}
            >
                {percent(row.progress)}
            </td>

            {/* A label is one token — `W3 08-26` — so it is never broken over
                two lines; the column is sized to hold it whole. */}
            <td
                className="px-1 text-[13px] whitespace-nowrap tabular-nums"
                data-pin="column"
                style={fixed(2, START_LEFT)}
            >
                {grid.label(row.start)}
            </td>

            <td
                className="px-1 text-[13px] whitespace-nowrap tabular-nums"
                data-pin="column"
                style={fixed(3, END_LEFT)}
            >
                {grid.label(row.end)}
            </td>

            {grid.columns.map((_, index) => (
                <td
                    key={index}
                    style={{
                        ...bodyCell(lastRow),
                        backgroundColor:
                            fillOf(row, index, left, right, todaySlot) ?? paper,
                        ...picked(
                            isRowPicked ||
                                columnPicked(selection, TIMELINE_FROM + index),
                        ),
                    }}
                />
            ))}
        </tr>
    );
}

/**
 * The fill one timeline cell carries.
 *
 * The order is the order the sheet paints in: the columns past today are
 * greyed first, a project line greys everything before it started, and the bar
 * is drawn over both — work already scheduled into a future week still reads
 * through the shading. A finished bar closes on a solid cap, which is how a
 * reader spots what actually landed.
 */
function fillOf(
    row: Row,
    index: number,
    left: number | null,
    right: number | null,
    todaySlot: number,
): string | undefined {
    if (left !== null && right !== null && index >= left && index <= right) {
        return row.done && index === right ? BAR_DONE : BAR;
    }

    if (row.isProject && left !== null && index < left) {
        return OUTSIDE;
    }

    return index > todaySlot ? OUTSIDE : undefined;
}

/**
 * The sheet's lines, project by project: a shaded line carrying the whole
 * project's window, then its tasks.
 *
 * A task is numbered `<project position>.<wbs>`, because the workbook treats
 * the application as the first WBS level while the database numbers each
 * project from 1 — `1.4.1` here is `GRO-4.1` everywhere else in the app.
 */
function buildRows(groups: SheetProjectGroup[]): Row[] {
    return groups.flatMap((group, index) => {
        const number = index + 1;
        const times = datesOf(group.tasks).map((date) => date.getTime());

        const project: Row = {
            task: null,
            label: `${number}. ${group.project.name}`,
            progress: groupProgress(group.tasks),
            start: times.length === 0 ? null : new Date(Math.min(...times)),
            end: times.length === 0 ? null : new Date(Math.max(...times)),
            done: group.tasks.every(isDone),
            isProject: true,
        };

        const tasks = group.tasks.map((task): Row => {
            const start = parseDate(task.start_date);
            const end = parseDate(task.due_date);

            return {
                task,
                label: `${number}.${task.wbs_number} ${task.title}`.trim(),
                progress: task.progress,
                // A task missing one of its two dates still gets a single
                // column marked, so work scheduled at one end only stays
                // visible.
                start: start ?? end,
                end: end ?? start,
                done: isDone(task),
                isProject: false,
            };
        });

        return [project, ...tasks];
    });
}

/**
 * One cell of the frame down the left edge, carrying the row's number and
 * picking that whole row out when it is clicked.
 */
function RowNumber({
    number,
    active,
    onPick,
}: {
    number: number;
    active: boolean;
    onPick: (selection: NonNullable<Selection>) => void;
}) {
    return (
        <th
            style={{
                ...frameCell,
                ...frameActive(active),
                ...pinned(0),
                zIndex: 2,
            }}
        >
            <FrameButton
                label={`Pilih baris ${number}`}
                onClick={() => onPick({ kind: 'row', row: number })}
            >
                {number}
            </FrameButton>
        </th>
    );
}

/**
 * The clickable face of a frame cell.
 *
 * A real button rather than a handler on the cell, so the frame answers the
 * keyboard the way the rest of the page does. The click is stopped here: a body
 * row carries its own handler, which slides the timeline to the row's bar, and
 * picking a row is not asking for that.
 */
function FrameButton({
    label,
    onClick,
    children,
}: {
    label: string;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            className="size-full cursor-pointer"
            onClick={(event) => {
                event.stopPropagation();
                onClick();
            }}
        >
            {children}
        </button>
    );
}

/**
 * The merged bands of a header row — years, then months or quarters. They are
 * laid out left to right, so the column each one opens on is counted as they
 * go; a pick anywhere inside a band lights the whole of it, as picking a column
 * lights a merged cell in a spreadsheet.
 */
function BandCells({
    bands,
    selection,
    row,
}: {
    bands: SheetBand[];
    selection: Selection;
    row: number;
}) {
    // Running start of each band, the timeline's first column onwards. One
    // entry longer than `bands`; the tail is the column past the last band.
    const opensOn = bands.reduce<number[]>(
        (columns, band, index) => [...columns, columns[index] + band.span],
        [TIMELINE_FROM],
    );

    return (
        <>
            {bands.map((band, index) => (
                <th
                    key={index}
                    colSpan={band.span}
                    style={{
                        ...headerCell(false),
                        ...picked(
                            columnPicked(
                                selection,
                                opensOn[index],
                                band.span,
                            ) || rowPicked(selection, row),
                        ),
                    }}
                >
                    {band.label}
                </th>
            ))}
        </>
    );
}

/**
 * Progress of a project block: the average over its top level tasks, or over
 * everything when the block carries no root of its own — someone can be
 * assigned a sub task without owning its parent.
 */
function groupProgress(tasks: TaskNode[]): number {
    const roots = tasks.filter((task) => task.depth === 0);
    const source = roots.length > 0 ? roots : tasks;

    if (source.length === 0) {
        return 0;
    }

    return Math.round(
        source.reduce((total, task) => total + task.progress, 0) /
            source.length,
    );
}

/** Every date a set of tasks carries, both ends, nulls dropped. */
function datesOf(tasks: TaskNode[]): Date[] {
    return tasks
        .flatMap((task) => [
            parseDate(task.start_date),
            parseDate(task.due_date),
        ])
        .filter((date): date is Date => date !== null);
}

function isDone(task: TaskNode): boolean {
    return STATUS_CATEGORY[task.status] === 'done';
}

/** The `0%` number format the sheet writes every percentage with. */
function percent(value: number): string {
    return `${Math.round(value)}%`;
}

/** Keeps a fixed column in place while the timeline scrolls under it. */
function pinned(left: number): CSSProperties {
    return { position: 'sticky', left: `${left}px`, zIndex: 1 };
}

function headerCell(first: boolean): CSSProperties {
    return {
        backgroundColor: HEADER,
        fontWeight: 700,
        fontSize: '13px',
        textAlign: 'center',
        verticalAlign: 'middle',
        borderTop: RULE,
        borderRight: RULE,
        borderBottom: RULE,
        borderLeft: first ? RULE : undefined,
    };
}

/**
 * A body cell, ruled on the same two edges the header rules.
 *
 * Every cell carries its own right rule and only the first of a row carries a
 * left one — the mirror of how the block used to be drawn, which put the rule
 * on each cell's left instead. That version lost its vertical lines the moment
 * the timeline was scrolled while the header, ruled on the right, kept every
 * one of its own: a sticky cell puts the row on its own paint layer, and the
 * left edge of a cell sliding under one is what goes missing when it is
 * repainted. Ruling to the right keeps the line on the side that stays.
 */
function bodyCell(lastRow: boolean, first = false): CSSProperties {
    return {
        borderTop: RULE,
        borderRight: RULE,
        borderLeft: first ? RULE : undefined,
        borderBottom: lastRow ? RULE : undefined,
        verticalAlign: 'middle',
    };
}

/**
 * The spreadsheet frame: the row numbers down the left and the column letters
 * across the top. Its own greys, not the sheet's — it is the window the sheet
 * is read through rather than part of the document.
 */
const frameCell: CSSProperties = {
    background: 'var(--sheet-frame)',
    color: 'var(--sheet-frame-ink)',
    fontSize: '11px',
    fontWeight: 400,
    textAlign: 'center',
    verticalAlign: 'middle',
    userSelect: 'none',
    borderRight: '1px solid var(--sheet-frame-rule)',
    borderBottom: '1px solid var(--sheet-frame-rule)',
};

/** A frame cell whose row or column is the picked one. */
function frameActive(active: boolean): CSSProperties {
    return active
        ? { background: 'var(--sheet-frame-active)', fontWeight: 700 }
        : {};
}

/** Left offset of each lettered column that is pinned, A through D. */
const FRAME_LEFT = [TASK_LEFT, PROGRESS_LEFT, START_LEFT, END_LEFT];
