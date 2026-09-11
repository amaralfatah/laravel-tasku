/**
 * The Gantt grid of the exported workbook, drawn in the browser.
 *
 * A straight port of `App\Support\TimelineGrid` and `App\Support\MonthWeek`:
 * four columns a month, whatever a month has left over folding into W4, and a
 * range that always closes on a December. The person page renders the sheet
 * people already read (`ContohLaporan.xlsx`), so its columns have to be the
 * export's columns — a week that means one thing in the workbook and another
 * on screen is the whole failure this replaces.
 *
 * This is deliberately not `@/lib/week`, which lays out real weeks and allows
 * a fifth one. The two grids are not interchangeable; neither is the right
 * answer for the other's caller.
 */

import { MONTH_NAMES, weekOfMonth } from '@/lib/week';

export type SheetZoom = 'week' | 'month' | 'quarter';

/** Columns drawn per month at week zoom. */
export const PER_MONTH = 4;

export const SHEET_ZOOMS: SheetZoom[] = ['week', 'month', 'quarter'];

export const SHEET_ZOOM_LABELS: Record<SheetZoom, string> = {
    week: 'Minggu',
    month: 'Bulan',
    quarter: 'Kuartal',
};

/** Month names as the week band spells them out. */
const MONTHS = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

/** Width of one timeline column, in the workbook's own character units. */
const WIDTHS: Record<SheetZoom, number> = {
    week: 3.18,
    month: 6.36,
    quarter: 10.0,
};

/**
 * An Excel column width in pixels, at 100% zoom: the character count times the
 * default font's digit width, plus the cell's own padding. Every width on the
 * sheet is stated in the workbook's units and converted here, so the page and
 * the file are the same size on paper.
 */
export function excelWidth(characters: number): number {
    return Math.round(characters * 7 + 5);
}

/**
 * The letter a one based column index is addressed by — `A`, `B`, … `Z`, `AA`.
 * Mirrors `Coordinate::stringFromColumnIndex()`, so a cell pointed at on screen
 * is the same cell in the file.
 */
export function columnLetter(index: number): string {
    let letter = '';
    let remaining = index;

    while (remaining > 0) {
        const digit = (remaining - 1) % 26;

        letter = String.fromCharCode(65 + digit) + letter;
        remaining = Math.floor((remaining - 1) / 26);
    }

    return letter;
}

export type SheetColumn = { group: string; unit: string; year: number };

/** A merged run of header cells: what it says, and how many columns it covers. */
export type SheetBand = { label: string; span: number };

export type SheetGrid = {
    zoom: SheetZoom;
    columns: SheetColumn[];
    /** Column width in pixels. */
    width: number;
    /** Year bands of the top header row. */
    years: SheetBand[];
    /** Month or quarter bands of the middle header row. */
    groups: SheetBand[];
    /** One label per column for the bottom header row. */
    units: string[];
    /** Zero based column a date falls in, counted from the grid's origin. */
    slot: (date: Date) => number;
    /** The START and END label, spoken at the grid's own granularity. */
    label: (date: Date | null) => string;
};

/**
 * The months the sheet spans: from the first scheduled month through the
 * December of the year the last one falls in. The grid runs past the work on
 * purpose — the weeks that have not happened yet are greyed, and that horizon
 * is what makes one person's sheet readable beside another's.
 */
export function sheetRange(dates: Date[]): [Date, Date] {
    if (dates.length === 0) {
        const now = new Date();

        return [
            new Date(now.getFullYear(), now.getMonth(), 1),
            new Date(now.getFullYear(), 11, 31),
        ];
    }

    const times = dates.map((date) => date.getTime());
    const first = new Date(Math.min(...times));
    const last = new Date(Math.max(...times));

    return [
        new Date(first.getFullYear(), first.getMonth(), 1),
        new Date(last.getFullYear(), 11, 31),
    ];
}

export function buildSheetGrid(
    zoom: SheetZoom,
    from: Date,
    to: Date,
): SheetGrid {
    const origin =
        zoom === 'quarter'
            ? new Date(
                  from.getFullYear(),
                  Math.floor(from.getMonth() / 3) * 3,
                  1,
              )
            : new Date(from.getFullYear(), from.getMonth(), 1);

    const columns = buildColumns(zoom, origin, to);

    const slot = (date: Date): number => {
        const months =
            (date.getFullYear() - origin.getFullYear()) * 12 +
            (date.getMonth() - origin.getMonth());

        if (zoom === 'week') {
            return months * PER_MONTH + weekOfMonth(date) - 1;
        }

        return zoom === 'month' ? months : Math.floor(months / 3);
    };

    return {
        zoom,
        columns,
        width: excelWidth(WIDTHS[zoom]),
        years: bands(
            columns,
            (column) => String(column.year),
            (column) => String(column.year),
        ),
        groups: bands(
            columns,
            (column) => `${column.group} ${column.year}`,
            (column) => column.group,
        ),
        units: columns.map((column) => column.unit),
        slot,
        label: (date) => labelOf(zoom, date),
    };
}

/** `W3 08-26`, `Agu 26` or `Q3 26`; an em dash when the date is missing. */
function labelOf(zoom: SheetZoom, date: Date | null): string {
    if (date === null) {
        return '—';
    }

    const year = String(date.getFullYear()).slice(-2);

    if (zoom === 'week') {
        const month = String(date.getMonth() + 1).padStart(2, '0');

        return `W${weekOfMonth(date)} ${month}-${year}`;
    }

    if (zoom === 'month') {
        return `${MONTH_NAMES[date.getMonth()]} ${year}`;
    }

    return `Q${Math.floor(date.getMonth() / 3) + 1} ${year}`;
}

function buildColumns(zoom: SheetZoom, origin: Date, to: Date): SheetColumn[] {
    const columns: SheetColumn[] = [];
    const last = new Date(to.getFullYear(), to.getMonth(), 1);
    const cursor = new Date(origin);

    while (cursor <= last) {
        const year = cursor.getFullYear();
        const month = cursor.getMonth();
        const quarter = Math.floor(month / 3) + 1;

        if (zoom === 'week') {
            for (let week = 1; week <= PER_MONTH; week++) {
                columns.push({
                    group: MONTHS[month],
                    unit: String(week),
                    year,
                });
            }
        } else if (zoom === 'month') {
            columns.push({
                group: `Q${quarter}`,
                unit: MONTH_NAMES[month],
                year,
            });
        } else {
            columns.push({
                group: `Q${quarter}`,
                unit: `${MONTH_NAMES[month]}-${MONTH_NAMES[month + 2]}`,
                year,
            });
        }

        cursor.setMonth(month + (zoom === 'quarter' ? 3 : 1));
    }

    return columns;
}

/** Collapse the columns into bands of equal key, keeping their order. */
function bands(
    columns: SheetColumn[],
    key: (column: SheetColumn) => string,
    label: (column: SheetColumn) => string,
): SheetBand[] {
    const result: SheetBand[] = [];
    let previous: string | null = null;

    for (const column of columns) {
        const current = key(column);

        if (current === previous) {
            result[result.length - 1].span++;

            continue;
        }

        result.push({ label: label(column), span: 1 });
        previous = current;
    }

    return result;
}
