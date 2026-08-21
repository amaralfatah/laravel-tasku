/**
 * Week-based date helpers (6.6).
 *
 * Dates travel as plain `YYYY-MM-DD` strings, so everything here works on
 * calendar days only — no timezone conversion is involved or wanted.
 */

export const TIMEZONE = 'Asia/Jakarta';

/** Parse a `YYYY-MM-DD` string as a local calendar date. */
export function parseDate(value: string | null | undefined): Date | null {
    if (!value) {
        return null;
    }

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    if (!year || !month || !day) {
        return null;
    }

    return new Date(year, month - 1, day);
}

/** Format a date back to `YYYY-MM-DD`. */
export function toDateString(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** Week within the month, 1 through 5 (DATE-3). */
export function weekOfMonth(date: Date): number {
    return Math.ceil(date.getDate() / 7);
}

/** Short week label, e.g. `W1 07-25` (DATE-2). */
export function formatWeek(value: string | Date | null | undefined): string {
    const date = value instanceof Date ? value : parseDate(value);

    if (!date) {
        return '—';
    }

    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = String(date.getFullYear()).slice(-2);

    return `W${weekOfMonth(date)} ${month}-${year}`;
}

/** Day-level label, e.g. `05 Agu 2026`. */
export function formatDay(value: string | Date | null | undefined): string {
    const date = value instanceof Date ? value : parseDate(value);

    if (!date) {
        return '—';
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

/** Timestamp label in Jakarta time, for created/updated stamps (DATE-6). */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: TIMEZONE,
    }).format(new Date(value));
}

/** Monday of the week containing the date (DATE-4). */
export function startOfWeek(date: Date): Date {
    const result = new Date(date);
    // getDay() is 0 for Sunday, so Sunday counts as the end of the prior week.
    const offset = (result.getDay() + 6) % 7;
    result.setDate(result.getDate() - offset);

    return result;
}

/** Friday of the week containing the date (DATE-4). */
export function endOfWeek(date: Date): Date {
    const result = startOfWeek(date);
    result.setDate(result.getDate() + 4);

    return result;
}

/** Monday..Friday range for the week containing the date. */
export function weekRange(date: Date): { start: string; end: string } {
    return {
        start: toDateString(startOfWeek(date)),
        end: toDateString(endOfWeek(date)),
    };
}

export function addWeeks(date: Date, count: number): Date {
    const result = new Date(date);
    result.setDate(result.getDate() + count * 7);

    return result;
}

export function addMonths(date: Date, count: number): Date {
    const result = new Date(date);
    result.setMonth(result.getMonth() + count);

    return result;
}

/** Whole days between two calendar dates. */
export function daysBetween(from: Date, to: Date): number {
    const MS_PER_DAY = 24 * 60 * 60 * 1000;

    return Math.round(
        (Date.UTC(to.getFullYear(), to.getMonth(), to.getDate()) -
            Date.UTC(from.getFullYear(), from.getMonth(), from.getDate())) /
            MS_PER_DAY,
    );
}

/** Today as a calendar date, with the time component stripped. */
export function today(): Date {
    const now = new Date();

    return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

/** True when the date is before today — used to flag overdue work. */
export function isOverdue(value: string | null | undefined): boolean {
    const date = parseDate(value);

    return date !== null && date < today();
}

export const MONTH_NAMES = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Agu',
    'Sep',
    'Okt',
    'Nov',
    'Des',
];
