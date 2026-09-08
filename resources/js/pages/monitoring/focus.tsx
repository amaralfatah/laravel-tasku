import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarClock, ChevronDown, Inbox, Sunrise } from 'lucide-react';
import { useMemo, useState } from 'react';
import { TaskDetailModal } from '@/components/task/task-detail-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { daysBetween, formatDay, parseDate } from '@/lib/week';
import { me, person as personRoute } from '@/routes/monitoring';
import { index as projectsIndex, show as showProject } from '@/routes/projects';
import { update as updateTask } from '@/routes/tasks';
import type { Option } from '@/types/members';
import type { RequesterOption } from '@/types/requesters';
import {
    TASK_PRIORITY_BADGE,
    TASK_PRIORITY_LABELS,
    TASK_PRIORITY_TEXT,
    TASK_STATUS_LABELS,
} from '@/types/tasks';
import type { TaskAssignee, TaskNode, TaskPriority } from '@/types/tasks';

type ProjectGroup = {
    project: { id: number; name: string };
    /** Whether the viewer may edit tasks of this project (varies per block). */
    can_edit: boolean;
    assignees: TaskAssignee[];
    tasks: TaskNode[];
};

type Member = {
    id: number;
    user_id: number;
    name: string;
    email: string;
    avatar: string | null;
    org_unit: string | null;
};

/** A task carried together with the project block it came from. */
type Row = { task: TaskNode; group: ProjectGroup };

/**
 * A row standing in its family: `depth` is how far it sits under the topmost
 * ancestor that shares its bucket, and 0 for a row that leads one.
 */
type NestedRow = { row: Row; depth: number };

type BucketKey = 'overdue' | 'today' | 'week' | 'later' | 'unscheduled';

/** The filters at the top; the first three are buckets, the last is a state. */
type SignalKey = BucketKey | 'review';

/**
 * What the segmented control above the agenda offers, beside "Semua".
 *
 * Three of them name a heading below; "review" names a state a task can be in
 * under any heading, which is why it is a filter and not a sixth bucket.
 */
const SIGNALS: { key: SignalKey; label: string }[] = [
    { key: 'overdue', label: 'Telat' },
    { key: 'today', label: 'Hari ini' },
    { key: 'week', label: 'Minggu ini' },
    { key: 'review', label: 'Menunggu review' },
];

/**
 * The picked filter as it arrives in the query string.
 *
 * Anything unrecognised reads as no filter rather than as an empty agenda, so
 * a hand-edited or stale link still opens on something.
 */
function readSignal(url: string): SignalKey | null {
    const value = new URLSearchParams(url.split('?')[1] ?? '').get('signal');

    return SIGNALS.some((signal) => signal.key === value)
        ? (value as SignalKey)
        : null;
}

/**
 * The agenda, in the order a working day is lived.
 *
 * An empty bucket is left out, the way a calendar leaves out an empty day. The
 * filters above the list carry the same names as these headings, so a count in
 * one place always means the same thing as the same count in the other.
 */
const BUCKETS: { key: BucketKey; label: string }[] = [
    { key: 'overdue', label: 'Telat' },
    { key: 'today', label: 'Hari ini' },
    { key: 'week', label: 'Minggu ini' },
    { key: 'later', label: 'Nanti' },
    { key: 'unscheduled', label: 'Tanpa tanggal' },
];

/**
 * How much of the undated backlog stands in the agenda before the rest is
 * folded away.
 *
 * Everything else on this page is a day's work; "tanpa tanggal" is a pile that
 * only grows, and at ten rows it was longer than the four dated buckets put
 * together — the page ended up being mostly the part with no claim on today.
 */
const UNSCHEDULED_PREVIEW = 5;

const PRIORITY_RANK: Record<TaskPriority, number> = {
    urgent: 0,
    high: 1,
    medium: 2,
    low: 3,
};

/** Calendar days from today to a due date; the time of day never counts. */
function daysUntil(due: string, from: Date): number {
    const target = parseDate(due);

    return target === null
        ? Number.POSITIVE_INFINITY
        : daysBetween(from, target);
}

function bucketOf(task: TaskNode, now: Date): BucketKey {
    if (task.due_date === null) {
        return 'unscheduled';
    }

    if (task.is_overdue) {
        return 'overdue';
    }

    const days = daysUntil(task.due_date, now);

    if (days <= 0) {
        return 'today';
    }

    return days <= 7 ? 'week' : 'later';
}

/**
 * Due date in the words people use for the near ones, and the plain date once
 * "in eleven days" stops meaning anything.
 */
function dueLabel(task: TaskNode, now: Date): string {
    if (task.due_date === null) {
        return '';
    }

    const days = daysUntil(task.due_date, now);

    if (days === 0) {
        return 'Hari ini';
    }

    if (days === 1) {
        return 'Besok';
    }

    if (days < 0) {
        return `Telat ${Math.abs(days)} hari`;
    }

    return days <= 7 ? `${days} hari lagi` : formatDay(task.due_date);
}

/**
 * Two rows in the order the next hour is decided: due date, then priority,
 * then reference — soonest first, and among equals the one that costs most to
 * miss.
 */
function byUrgency(a: Row, b: Row): number {
    const due = (a.task.due_date ?? '').localeCompare(b.task.due_date ?? '');

    if (due !== 0) {
        return due;
    }

    const priority =
        PRIORITY_RANK[a.task.priority] - PRIORITY_RANK[b.task.priority];

    return priority !== 0
        ? priority
        : a.task.reference.localeCompare(b.task.reference);
}

/**
 * The rows of one bucket, each standing under its parent.
 *
 * A parent and its sub tasks are one piece of work, and scattering them down
 * the list by due date left the page reading as unrelated lines that happen to
 * share a reference prefix. So a family is ordered as a family, and the
 * families themselves keep the bucket's own order — the earliest row a family
 * has is the position the whole family takes.
 *
 * Only a parent inside this same bucket counts. Pulling one in from another
 * heading would file it under a date it does not have, and the count beside
 * the heading would stop matching what is under it.
 */
function nest(rows: Row[]): NestedRow[] {
    const here = new Set(rows.map((row) => row.task.id));
    const children = new Map<number, Row[]>();
    const roots: Row[] = [];

    for (const row of rows) {
        const parent = row.task.parent_task_id;

        if (parent === null || !here.has(parent)) {
            roots.push(row);

            continue;
        }

        children.set(parent, [...(children.get(parent) ?? []), row]);
    }

    /** The soonest row anywhere in a family, which is what places it. */
    const leading = (row: Row): Row =>
        (children.get(row.task.id) ?? [])
            .map(leading)
            .reduce(
                (best, other) => (byUrgency(other, best) < 0 ? other : best),
                row,
            );

    const ordered: NestedRow[] = [];

    const walk = (siblings: Row[], depth: number): void => {
        for (const row of [...siblings].sort((a, b) =>
            byUrgency(leading(a), leading(b)),
        )) {
            ordered.push({ row, depth });
            walk(children.get(row.task.id) ?? [], depth + 1);
        }
    };

    walk(roots, 0);

    return ordered;
}

function greetingFor(hour: number): string {
    if (hour < 11) {
        return 'Selamat pagi';
    }

    if (hour < 15) {
        return 'Selamat siang';
    }

    return hour < 18 ? 'Selamat sore' : 'Selamat malam';
}

/** The one line under the greeting: only the counts that are not zero. */
function summarise(counts: Record<SignalKey, number>, total: number): string {
    const parts: string[] = [];

    if (counts.overdue > 0) {
        parts.push(`${counts.overdue} telat`);
    }

    if (counts.today > 0) {
        parts.push(`${counts.today} jatuh tempo hari ini`);
    }

    if (counts.review > 0) {
        parts.push(`${counts.review} menunggu review`);
    }

    return parts.length === 0
        ? `${total} task terbuka, tidak ada yang mendesak.`
        : `${parts.join(', ')}, dari ${total} task terbuka.`;
}

/**
 * The landing page (MON-7).
 *
 * The same tasks as the timeline next door, sorted by when they are due rather
 * than by which project they belong to. A gantt answers "how does the quarter
 * look"; the first screen after signing in has to answer "what do I do now",
 * and those are not the same page.
 *
 * Structure comes from the headings and the hairlines under them, nothing
 * more. This design system has no card, fill or shadow to group with, and a
 * decorative rail down the left was tried and read as debris in the dark
 * theme while costing a phone the width of its titles.
 */
export default function MonitoringFocus({
    member,
    tasks,
    statuses,
    priorities,
    requesters,
    filters,
    doneWindowDays,
    olderDone,
}: {
    member: Member;
    tasks: ProjectGroup[];
    statuses: Option[];
    priorities: Option[];
    requesters: RequesterOption[];
    filters: { from: string | null; to: string | null };
    /** How many days of finished work the page carries. */
    doneWindowDays: number;
    /** Finished work left behind that window, and so not sent to the browser. */
    olderDone: number;
}) {
    const page = usePage();
    const [openTaskId, setOpenTaskId] = useState<number | null>(null);
    const [signal, setSignal] = useState<SignalKey | null>(() =>
        readSignal(page.url),
    );
    const [showDone, setShowDone] = useState(false);
    const [showAllUnscheduled, setShowAllUnscheduled] = useState(false);

    /**
     * Filtering itself stays here: the server already sends every open task,
     * and the count beside each label is counted over all of them, so
     * narrowing the list on the server would leave the numbers describing a
     * list nobody can see.
     *
     * The choice still lands in the URL, the way every other filter in this
     * application does and the way Jira carries the tab of its own landing
     * page — "what is late" becomes a link somebody can send, and it survives
     * a reload. A client-side visit is enough; there is nothing to fetch.
     * `mergeQuery` leaves a date range that is already there alone.
     */
    const chooseSignal = (next: SignalKey | null): void => {
        setSignal(next);

        router.replace({
            url: me({ mergeQuery: { signal: next } }).url,
            preserveState: true,
            preserveScroll: true,
        });
    };

    // Read once per visit, not per render: a task must not change heading
    // under the reader while they are looking at it.
    const now = useMemo(() => new Date(), []);

    const rows = useMemo<Row[]>(
        () =>
            tasks.flatMap((group) =>
                group.tasks.map((task) => ({ task, group })),
            ),
        [tasks],
    );

    const open = useMemo(
        () => rows.filter((row) => row.task.status !== 'done'),
        [rows],
    );

    const done = useMemo(
        () =>
            rows
                .filter((row) => row.task.status === 'done')
                .sort((a, b) =>
                    (b.task.completed_at ?? '').localeCompare(
                        a.task.completed_at ?? '',
                    ),
                ),
        [rows],
    );

    const counts = useMemo(() => {
        const tally: Record<SignalKey, number> = {
            overdue: 0,
            today: 0,
            week: 0,
            later: 0,
            unscheduled: 0,
            review: 0,
        };

        for (const row of open) {
            tally[bucketOf(row.task, now)] += 1;

            if (row.task.status === 'review') {
                tally.review += 1;
            }
        }

        return tally;
    }, [open, now]);

    /**
     * Within a bucket the order is by family, and a family is placed by its
     * most urgent member: due date, then priority, then reference.
     */
    const buckets = useMemo(() => {
        const grouped = new Map<BucketKey, Row[]>(
            BUCKETS.map((bucket) => [bucket.key, []]),
        );

        for (const row of open) {
            const bucket = bucketOf(row.task, now);
            const matches =
                signal === null ||
                (signal === 'review'
                    ? row.task.status === 'review'
                    : bucket === signal);

            if (matches) {
                grouped.get(bucket)?.push(row);
            }
        }

        return new Map<BucketKey, NestedRow[]>(
            [...grouped].map(([key, list]) => [key, nest(list)]),
        );
    }, [open, signal, now]);

    const visible = BUCKETS.filter(
        (bucket) => (buckets.get(bucket.key) ?? []).length > 0,
    );

    /**
     * The open task with the family around it, so the modal shows sub tasks
     * here as it does on a project page.
     *
     * Both are read from the same project block, which holds the tasks
     * assigned to this member and no others. That is the tree this page has
     * everywhere else too: the timeline indents by the same partial hierarchy,
     * and `children_count` is counted from the same rows, so the modal agrees
     * with what the page around it already says.
     */
    const detail = useMemo(() => {
        const row = rows.find(({ task }) => task.id === openTaskId);

        if (row === undefined) {
            return null;
        }

        const family = row.group.tasks;

        return {
            task: row.task,
            assignees: row.group.assignees,
            subtasks: family.filter(
                (item) => item.parent_task_id === row.task.id,
            ),
            parent:
                family.find((item) => item.id === row.task.parent_task_id) ??
                null,
        };
    }, [rows, openTaskId]);

    /**
     * Only the filters that would narrow anything — plus whichever one is on.
     *
     * A dead control is worse than a missing one, which is why the zeroes are
     * left out. The picked one is the exception: finishing the last late task
     * would otherwise make its segment disappear and leave the agenda empty
     * with nothing on screen saying what was filtering it.
     */
    const signals = SIGNALS.filter(
        (item) => counts[item.key] > 0 || signal === item.key,
    );

    return (
        <>
            <Head title="Task saya" />

            {/* Full width, like every other page: the app layout owns the
                gutter and the maximum width, and a page that sets its own
                would sit narrower than the one beside it.

                A phone still stacks the greeting and the filters before the
                first task, so the gaps between them are a step tighter there
                than on a screen that has room to breathe. */}
            <div className="space-y-4 sm:space-y-6">
                {/* The greeting and the filters share a line, the way Jira
                    puts the tabs of its own landing page on the line of the
                    section heading rather than in a band of their own. Three
                    stacked bands stood here before — greeting, view tabs, a
                    sticky strip of chips — ahead of the first task. The way to
                    the gantt left with them; it is a sidebar row now, because
                    a tab that loads another page is not a tab.

                    A phone has no room for both, so the control wraps under
                    the greeting and scrolls inside itself. */}
                <header className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                    <div className="min-w-0 flex-1">
                        {/* Large type takes negative tracking; letters read
                            further apart the bigger they get. A phone holds a
                            step less of it: at 30px the greeting outweighed
                            the two tasks underneath it. */}
                        <h1 className="truncate text-xl font-semibold tracking-tight sm:text-3xl">
                            {greetingFor(now.getHours())}, {member.name}.
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {open.length === 0
                                ? 'Tidak ada task terbuka.'
                                : summarise(counts, open.length)}
                        </p>
                    </div>

                    {signals.length > 0 && (
                        <SignalTabs
                            signals={signals}
                            counts={counts}
                            total={open.length}
                            active={signal}
                            onPick={chooseSignal}
                        />
                    )}
                </header>

                {(filters.from || filters.to) && (
                    <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm">
                        <CalendarClock
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span className="text-muted-foreground">
                            Dibatasi rentang {formatDay(filters.from)} sampai{' '}
                            {formatDay(filters.to)}.
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="ml-auto"
                            /* Clears the range and nothing else: the
                               picked filter is in the same query string and
                               dropping it here would undo a choice the
                               button does not name. */
                            onClick={() =>
                                router.get(
                                    me({
                                        mergeQuery: { from: null, to: null },
                                    }).url,
                                    {},
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            Tampilkan semua
                        </Button>
                    </div>
                )}

                {open.length === 0 ? (
                    <div className="rounded-lg border border-border p-12 text-center">
                        <Sunrise
                            className="mx-auto mb-3 size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Tidak ada yang menunggu</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Belum ada task terbuka yang ditugaskan pada Anda.
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            className="mt-4"
                            asChild
                        >
                            <Link href={projectsIndex()}>Lihat proyek</Link>
                        </Button>
                    </div>
                ) : visible.length === 0 ? (
                    <div className="rounded-lg border border-border p-12 text-center">
                        <Inbox
                            className="mx-auto mb-3 size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Tidak ada yang cocok</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Tidak ada task pada filter ini.
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            className="mt-4"
                            onClick={() => setSignal(null)}
                        >
                            Hapus filter
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-6">
                        {visible.map((bucket, index) => {
                            const rowsHere = buckets.get(bucket.key) ?? [];
                            const folded =
                                bucket.key === 'unscheduled' &&
                                !showAllUnscheduled &&
                                rowsHere.length > UNSCHEDULED_PREVIEW;
                            const shown = folded
                                ? rowsHere.slice(0, UNSCHEDULED_PREVIEW)
                                : rowsHere;

                            return (
                                <section
                                    key={bucket.key}
                                    className="animate-in duration-300 fill-mode-backwards fade-in slide-in-from-bottom-2"
                                    style={{
                                        animationDelay: `${index * 40}ms`,
                                    }}
                                >
                                    {/* The two headings that carry a warning say
                                    it in the word itself, so no dot or rail is
                                    needed to mark them apart. */}
                                    <h2
                                        id={`bucket-${bucket.key}`}
                                        className="flex items-baseline gap-2"
                                    >
                                        <span
                                            className={cn(
                                                'text-xs font-medium tracking-wide uppercase',
                                                bucket.key === 'overdue' &&
                                                    'text-destructive',
                                            )}
                                        >
                                            {bucket.label}
                                        </span>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {rowsHere.length}
                                        </span>
                                    </h2>

                                    <ul
                                        aria-labelledby={`bucket-${bucket.key}`}
                                        className="mt-1 divide-y divide-border border-t border-border"
                                    >
                                        {shown.map(
                                            ({
                                                row: { task, group },
                                                depth,
                                            }) => (
                                                <TaskRow
                                                    key={task.id}
                                                    task={task}
                                                    project={group.project}
                                                    statuses={statuses}
                                                    depth={depth}
                                                    meta={dueLabel(task, now)}
                                                    onOpen={() =>
                                                        setOpenTaskId(task.id)
                                                    }
                                                />
                                            ),
                                        )}
                                    </ul>

                                    {folded && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="mt-1 text-muted-foreground"
                                            onClick={() =>
                                                setShowAllUnscheduled(true)
                                            }
                                        >
                                            Tampilkan{' '}
                                            {rowsHere.length -
                                                UNSCHEDULED_PREVIEW}{' '}
                                            lainnya
                                        </Button>
                                    )}
                                </section>
                            );
                        })}
                    </div>
                )}

                {(done.length > 0 || olderDone > 0) && (
                    <section>
                        {/* Nothing to unfold when the window is empty, so the
                            note below stands on its own rather than under a
                            toggle that reads "0 selesai". */}
                        {done.length > 0 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-muted-foreground"
                                aria-expanded={showDone}
                                onClick={() => setShowDone((value) => !value)}
                            >
                                <ChevronDown
                                    className={cn(
                                        'transition-transform duration-200',
                                        showDone && 'rotate-180',
                                    )}
                                    aria-hidden="true"
                                />
                                {done.length} selesai dalam {doneWindowDays}{' '}
                                hari terakhir
                            </Button>
                        )}

                        {showDone && (
                            <ul className="mt-2 divide-y divide-border border-t border-border">
                                {done.map(({ task, group }) => (
                                    <TaskRow
                                        key={task.id}
                                        task={task}
                                        project={group.project}
                                        statuses={statuses}
                                        meta={
                                            task.completed_at === null
                                                ? ''
                                                : formatDay(
                                                      task.completed_at.slice(
                                                          0,
                                                          10,
                                                      ),
                                                  )
                                        }
                                        onOpen={() => setOpenTaskId(task.id)}
                                    />
                                ))}
                            </ul>
                        )}

                        {olderDone > 0 && (
                            <p className="mt-2 text-xs text-muted-foreground">
                                {olderDone} lainnya ada di{' '}
                                <Link
                                    href={personRoute(member.id)}
                                    className="underline hover:text-foreground"
                                >
                                    timeline
                                </Link>
                                .
                            </p>
                        )}
                    </section>
                )}
            </div>

            <TaskDetailModal
                task={detail?.task ?? null}
                subtasks={detail?.subtasks ?? []}
                parent={detail?.parent ?? null}
                assignees={detail?.assignees ?? []}
                requesters={requesters}
                statuses={statuses}
                priorities={priorities}
                onClose={() => setOpenTaskId(null)}
                onOpenTask={setOpenTaskId}
            />
        </>
    );
}

/**
 * The filters, as one segmented control rather than a row of loose chips.
 *
 * A segmented control says two things a chip cannot: that the choices are
 * exclusive, and that one of them is always on. Both are true here — "Semua"
 * is the resting state, not the absence of a filter — and saying them costs
 * one line beside the greeting instead of a band of its own.
 *
 * The track is the sunken step of the surface ladder and the picked segment is
 * the raised one, so the pair reads in both themes off tokens that already
 * exist: in dark the tone step carries it, in light a white segment sits on a
 * grey track. Nothing here is painted with the accent — a solid accent fill
 * made a filter shout louder than the late work it was filtering for.
 */
function SignalTabs({
    signals,
    counts,
    total,
    active,
    onPick,
}: {
    signals: { key: SignalKey; label: string }[];
    counts: Record<SignalKey, number>;
    /** What "Semua" counts: every open task, filtered or not. */
    total: number;
    active: SignalKey | null;
    onPick: (signal: SignalKey | null) => void;
}) {
    const segments: { key: SignalKey | null; label: string; count: number }[] =
        [
            { key: null, label: 'Semua', count: total },
            ...signals.map((signal) => ({
                key: signal.key,
                label: signal.label,
                count: counts[signal.key],
            })),
        ];

    return (
        <nav
            aria-label="Filter agenda"
            className="flex max-w-full shrink-0 [scrollbar-width:none] gap-1 overflow-x-auto rounded-md bg-muted p-1 [&::-webkit-scrollbar]:hidden"
        >
            {segments.map((segment) => {
                const isActive = active === segment.key;

                return (
                    <button
                        key={segment.key ?? 'all'}
                        type="button"
                        aria-pressed={isActive}
                        onClick={() => onPick(segment.key)}
                        /* Full 44px on a phone, and the 36px this design
                           system gives a control everywhere else. */
                        className={cn(
                            'flex min-h-11 shrink-0 items-center gap-1.5 rounded px-3 text-xs whitespace-nowrap transition-colors duration-150 sm:min-h-9 sm:text-sm',
                            isActive
                                ? 'bg-card font-medium text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {segment.label}
                        <span
                            className={cn(
                                'tabular-nums',
                                isActive
                                    ? 'text-muted-foreground'
                                    : 'text-muted-foreground/70',
                            )}
                        >
                            {segment.count}
                        </span>
                    </button>
                );
            })}
        </nav>
    );
}

/**
 * One agenda line: the status, the reference, the title, where it lives and
 * when it is due.
 *
 * Status is a select rather than a tick box because a task here holds one of
 * four states, and a box can only say two of them. Ticking one would also have
 * to guess which state an untick returns to. The control shows the state it
 * changes, and it sits with the other columns on the right so the title leads
 * the row: what the task is comes before what shape it is in.
 *
 * The columns are a grid rather than a flex row, and every cell is rendered
 * even when it is empty, so the same column lines up down the whole list. On a
 * phone the columns are dropped for a block of two lines: the title on its own,
 * and beneath it the date, anything out of the ordinary, the project and the
 * reference, in small type. A 112px select
 * takes a fifth of a phone and still truncates its own label, which is a poor
 * trade for the title it squeezes; on that width the status is read, not set,
 * and setting it happens where the task itself opens.
 *
 * The row stands 44px tall so the select and the title are both comfortable to
 * hit. The title is a button and the project is a link, which is why the row
 * itself is neither: one cannot nest inside the other.
 */
function TaskRow({
    task,
    project,
    statuses,
    meta,
    onOpen,
    depth = 0,
}: {
    task: TaskNode;
    project: { id: number; name: string };
    statuses: Option[];
    meta: string;
    onOpen: () => void;
    /** How far under a parent standing in the same list this row sits. */
    depth?: number;
}) {
    const [saving, setSaving] = useState(false);

    const finished = task.status === 'done';

    /**
     * How far the entered percentage has drifted from the children's average.
     * The project pages colour the same gap on the progress bar; without the
     * bar here, the number carries it (TSK-17).
     */
    const rollupGap =
        task.rollup_progress === null
            ? 0
            : Math.abs(task.progress - task.rollup_progress);

    return (
        // Every track is a fixed width. Each row is its own grid, so an `auto`
        // track is measured against that row alone and the column after it
        // never lines up with the row above: the titles came out ragged.
        //
        // Hover goes down the surface ladder, not up it, as the list page's
        // rows do. Taking the row to `accent` — the top of the ladder — put it
        // above the `card` the status control paints, and the control sank out
        // of sight the moment it was pointed at.
        // A sub task is indented under its parent by the same step the
        // timeline and the project tree use, so one task means the same shape
        // wherever it is read. Only the left padding moves: the fixed tracks
        // stay where they are, and the title column gives up the width.
        <li
            className="relative grid min-h-11 grid-cols-1 gap-y-0.5 px-2 py-2 hover:bg-accent sm:grid-cols-[6.5rem_minmax(0,1fr)_8rem_5rem_8.5rem_2.75rem_6rem] sm:items-center sm:gap-x-3 sm:gap-y-0"
            style={
                depth === 0 ? undefined : { paddingLeft: `${8 + depth * 14}px` }
            }
        >
            {/* On a wide screen the reference has a column of its own, ahead of
                the title, as it does on the list, the timeline and the member
                pages. A phone has no width to spare for it there, so it leads
                the second line instead. */}
            <span
                title={task.reference}
                className={cn(
                    'hidden truncate text-xs text-muted-foreground tabular-nums sm:block',
                    finished && 'line-through',
                )}
            >
                {task.reference}
            </span>

            {/* Wraps to two lines at any width, then clips. A long title is
                the row's own content and worth the second line; the third
                would let one task push the rest of the day off the screen.
                Rows are consequently not all the same height, so the columns
                beside it centre on whatever height the title takes. */}
            {/* The title carries the whole row's tap area with it. Two lines
                of text inside a 44px row left most of that row dead on a
                phone, which is exactly the part a thumb lands on. The stretched
                overlay covers every cell, so the two controls beside it are
                positioned to sit back on top. */}
            <button
                type="button"
                title={task.title}
                onClick={onOpen}
                className={cn(
                    'line-clamp-2 min-w-0 text-left text-sm after:absolute after:inset-0 hover:underline',
                    finished && 'text-muted-foreground',
                )}
            >
                {task.title}
            </button>

            <Link
                href={showProject(project.id)}
                className="relative hidden truncate text-xs text-muted-foreground hover:text-foreground hover:underline sm:block"
            >
                {project.name}
            </Link>

            {/* "Rendah" is the resting state of every task, so saying it on
                every row would crowd the ones that matter. The cell stays,
                empty, to hold the column. */}
            <span className="hidden sm:block">
                {!finished && task.priority !== 'low' && (
                    <Badge
                        variant="outline"
                        className={cn(
                            'font-normal',
                            TASK_PRIORITY_BADGE[task.priority],
                        )}
                    >
                        {TASK_PRIORITY_LABELS[task.priority]}
                    </Badge>
                )}
            </span>

            <span className="relative hidden sm:block">
                <Select
                    value={task.status}
                    disabled={!task.can_edit || saving}
                    onValueChange={(value) =>
                        router.patch(
                            updateTask(task.id).url,
                            { status: value },
                            {
                                preserveScroll: true,
                                // Changing a status usually moves the row to
                                // another heading, so the control has to say it
                                // is working; otherwise the row simply vanishes
                                // a moment after the click with nothing in
                                // between.
                                onStart: () => setSaving(true),
                                onFinish: () => setSaving(false),
                            },
                        )
                    }
                >
                    <SelectTrigger
                        size="sm"
                        className={cn(
                            'w-full border-transparent hover:border-input',
                            saving && 'animate-pulse',
                        )}
                        aria-label={`Status ${task.title}`}
                        aria-busy={saving}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {statuses.map((status) => (
                            <SelectItem key={status.value} value={status.value}>
                                {status.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </span>

            {/* The number, without the bar the project pages draw. A 96px
                track cannot separate 90% from 98%, and on an agenda the
                percentage is a detail beside the date, not a chart. */}
            <span
                className={cn(
                    'hidden text-right text-xs tabular-nums sm:block',
                    rollupGap >= 20
                        ? 'font-medium text-warning'
                        : 'text-muted-foreground',
                )}
                title={
                    task.rollup_progress === null
                        ? undefined
                        : `Rata-rata sub task: ${task.rollup_progress}%`
                }
            >
                {finished ? '' : `${task.progress}%`}
            </span>

            <span
                className={cn(
                    'hidden text-right text-xs tabular-nums sm:block',
                    task.is_overdue
                        ? 'font-medium text-destructive'
                        : 'text-muted-foreground',
                )}
            >
                {meta}
            </span>

            {/* The second line of the phone layout, carrying what the columns
                carry on a wider screen. The status reads as text here: it is
                changed by opening the task, not from the list.

                Read in the order the next hour is decided: when it is due
                first and in the row's own colour, then only what departs from
                the resting state — a priority above "Sedang", a status past
                "To Do" — then where the task lives. Four segments in one grey
                rhythm, three of them saying what every other row said, is a
                line nobody reads; the defaults are left out so the exceptions
                can be seen. The reference trails at the end, dimmer: it names
                the task and its depth, it does not rank it. */}
            <span className="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground sm:hidden">
                {meta !== '' && (
                    <>
                        <span
                            className={cn(
                                'tabular-nums',
                                task.is_overdue
                                    ? 'font-medium text-destructive'
                                    : 'text-foreground',
                            )}
                        >
                            {meta}
                        </span>
                        <span aria-hidden="true">·</span>
                    </>
                )}
                {!finished &&
                    (task.priority === 'high' ||
                        task.priority === 'urgent') && (
                        <>
                            <span className={TASK_PRIORITY_TEXT[task.priority]}>
                                {TASK_PRIORITY_LABELS[task.priority]}
                            </span>
                            <span aria-hidden="true">·</span>
                        </>
                    )}
                {!finished && task.status !== 'todo' && (
                    <>
                        <span>{TASK_STATUS_LABELS[task.status]}</span>
                        <span aria-hidden="true">·</span>
                    </>
                )}
                <span className="truncate">{project.name}</span>
                <span aria-hidden="true">·</span>
                <span
                    className={cn(
                        'tabular-nums opacity-70',
                        finished && 'line-through',
                    )}
                >
                    {task.reference}
                </span>
            </span>
        </li>
    );
}

MonitoringFocus.layout = () => ({
    breadcrumbs: [{ title: 'Task saya', href: me() }],
});
