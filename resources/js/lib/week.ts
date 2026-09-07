/**
 * Date helpers.
 *
 * Dates travel as plain `YYYY-MM-DD` strings, so everything here works on
 * calendar days only — no timezone conversion is involved or wanted.
 *
 * Tasks are scheduled by the day; the week helpers that remain only lay out
 * the timeline's columns, they no longer shape what a user can pick.
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

/** Week columns drawn per month, matching `MonthWeek::PER_MONTH` on the server. */
export const WEEKS_PER_MONTH = 4;

/**
 * Week within the month, 1 through 4, counted the way a calendar reads: weeks
 * run Monday to Sunday, and the days before the month's first Monday are its
 * week 1. A month whose calendar reaches a fifth week folds it into W4, so
 * every month reads W1 through W4.
 * Only the timeline header speaks this; task dates are picked by the day.
 */
export function weekOfMonth(date: Date): number {
    const first = new Date(date.getFullYear(), date.getMonth(), 1);
    const offset = (first.getDay() + 6) % 7;

    return Math.min(
        WEEKS_PER_MONTH,
        Math.floor((date.getDate() + offset - 1) / 7) + 1,
    );
}

/**
 * First day of each of a month's four week columns. W1 closes on the month's
 * first Sunday, W2 and W3 are whole weeks, and W4 carries whatever is left —
 * which is how a month with a fifth calendar week still fits four columns.
 */
export function weekStarts(year: number, month: number): Date[] {
    const offset = (new Date(year, month, 1).getDay() + 6) % 7;
    const second = 8 - offset;

    return [1, second, second + 7, second + 14].map(
        (day) => new Date(year, month, day),
    );
}

/** Start of the week column a date falls in. */
export function startOfWeekColumn(date: Date): Date {
    const starts = weekStarts(date.getFullYear(), date.getMonth());

    return starts[weekOfMonth(date) - 1];
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

/** Monday of the week containing the date; the timeline's column origin. */
export function startOfWeek(date: Date): Date {
    const result = new Date(date);
    // getDay() is 0 for Sunday, so Sunday counts as the end of the prior week.
    const offset = (result.getDay() + 6) % 7;
    result.setDate(result.getDate() - offset);

    return result;
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
