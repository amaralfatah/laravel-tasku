import { CalendarDays, CalendarRange } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    endOfWeek,
    formatWeek,
    parseDate,
    startOfWeek,
    toDateString,
} from '@/lib/week';

type Mode = 'week' | 'day';

/**
 * Date field with two input modes (DATE-4, DATE-5).
 *
 * Week mode uses the native `week` input and snaps to Monday for a start date
 * or Friday for an end date, matching how the team schedules work. Day mode
 * falls back to a plain date input when a specific day matters.
 */
export function WeekPicker({
    id,
    value,
    edge = 'start',
    disabled = false,
    onChange,
}: {
    id: string;
    value: string | null;
    /** Which day of the selected week the value snaps to. */
    edge?: 'start' | 'end';
    disabled?: boolean;
    onChange: (value: string | null) => void;
}) {
    const [mode, setMode] = useState<Mode>('week');

    const date = parseDate(value);

    return (
        <div className="flex items-center gap-2">
            {mode === 'week' ? (
                <Input
                    id={id}
                    type="week"
                    disabled={disabled}
                    value={date ? toIsoWeek(date) : ''}
                    onChange={(event) => {
                        const picked = fromIsoWeek(event.target.value);

                        if (!picked) {
                            onChange(null);

                            return;
                        }

                        onChange(
                            toDateString(
                                edge === 'start'
                                    ? startOfWeek(picked)
                                    : endOfWeek(picked),
                            ),
                        );
                    }}
                    className="flex-1"
                />
            ) : (
                <Input
                    id={id}
                    type="date"
                    disabled={disabled}
                    value={value ?? ''}
                    onChange={(event) =>
                        onChange(event.target.value || null)
                    }
                    className="flex-1"
                />
            )}

            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-9 shrink-0"
                aria-label={
                    mode === 'week'
                        ? 'Ganti ke pilih tanggal harian'
                        : 'Ganti ke pilih minggu'
                }
                title={
                    mode === 'week'
                        ? 'Pilih tanggal harian'
                        : 'Pilih per minggu'
                }
                onClick={() => setMode(mode === 'week' ? 'day' : 'week')}
            >
                {mode === 'week' ? (
                    <CalendarDays className="size-4" />
                ) : (
                    <CalendarRange className="size-4" />
                )}
            </Button>

            <span className="w-20 shrink-0 text-xs text-muted-foreground tabular-nums">
                {formatWeek(value)}
            </span>
        </div>
    );
}

/**
 * `<input type="week">` speaks ISO-8601 weeks (`2026-W34`), which is a
 * different numbering from the in-month week used for display.
 */
function toIsoWeek(date: Date): string {
    const target = new Date(
        Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()),
    );
    // ISO weeks run Monday..Sunday and are numbered from the Thursday they contain.
    const dayNumber = (target.getUTCDay() + 6) % 7;
    target.setUTCDate(target.getUTCDate() - dayNumber + 3);

    const firstThursday = new Date(Date.UTC(target.getUTCFullYear(), 0, 4));
    const firstDayNumber = (firstThursday.getUTCDay() + 6) % 7;
    firstThursday.setUTCDate(firstThursday.getUTCDate() - firstDayNumber + 3);

    const week =
        1 +
        Math.round(
            (target.getTime() - firstThursday.getTime()) /
                (7 * 24 * 60 * 60 * 1000),
        );

    return `${target.getUTCFullYear()}-W${String(week).padStart(2, '0')}`;
}

function fromIsoWeek(value: string): Date | null {
    const match = /^(\d{4})-W(\d{2})$/.exec(value);

    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const week = Number(match[2]);

    // 4 January is always in ISO week 1.
    const fourthOfJanuary = new Date(year, 0, 4);
    const monday = startOfWeek(fourthOfJanuary);
    monday.setDate(monday.getDate() + (week - 1) * 7);

    return monday;
}
