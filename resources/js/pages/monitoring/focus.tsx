import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock, ChevronDown, Inbox, Sunrise } from 'lucide-react';
import { useMemo, useState } from 'react';
import { MyWorkTabs } from '@/components/monitoring/my-work-tabs';
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

type BucketKey = 'overdue' | 'today' | 'week' | 'later' | 'unscheduled';

/** The filters at the top; the first three are buckets, the last is a state. */
type SignalKey = BucketKey | 'review';

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
    const [openTaskId, setOpenTaskId] = useState<number | null>(null);
    const [signal, setSignal] = useState<SignalKey | null>(null);
    const [showDone, setShowDone] = useState(false);

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
     * Within a bucket the order is due date, then priority, then reference:
     * soonest first, and among equals the one that costs most to miss.
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

        for (const list of grouped.values()) {
            list.sort((a, b) => {
                const due = (a.task.due_date ?? '').localeCompare(
                    b.task.due_date ?? '',
                );

                if (due !== 0) {
                    return due;
                }

                const priority =
                    PRIORITY_RANK[a.task.priority] -
                    PRIORITY_RANK[b.task.priority];

                return priority !== 0
                    ? priority
                    : a.task.reference.localeCompare(b.task.reference);
            });
        }

        return grouped;
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

    const signals: { key: SignalKey; label: string }[] = [
        { key: 'overdue', label: 'Telat' },
        { key: 'today', label: 'Hari ini' },
        { key: 'week', label: 'Minggu ini' },
        { key: 'review', label: 'Menunggu review' },
    ];

    return (
        <>
            <Head title="Task saya" />

            {/* Full width, like every other page: the app layout owns the
                gutter and the maximum width, and a page that sets its own
                would sit narrower than the one beside it. */}
            <div className="space-y-6">
                <header className="min-w-0">
                    {/* Large type takes negative tracking; letters read further
                        apart the bigger they get. A phone holds a step less of
                        it: at 30px the greeting outweighed the two tasks
                        underneath it. */}
                    <h1 className="truncate text-xl font-semibold tracking-tight sm:text-3xl">
                        {greetingFor(now.getHours())}, {member.name}.
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {open.length === 0
                            ? 'Tidak ada task terbuka.'
                            : summarise(counts, open.length)}
                    </p>
                </header>

                <MyWorkTabs memberId={member.id} active="agenda" />

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
                            onClick={() =>
                                router.get(
                                    me().url,
                                    {},
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            Tampilkan semua
                        </Button>
                    </div>
                )}

                {/* The filters float over the agenda as it scrolls, offset by
                    the height of the app header above them. The negative
                    margins undo the layout's gutter so the frosted strip
                    reaches the edges; without them a sliver of the list slid
                    past unblurred on either side. */}
                <div className="surface-frosted sticky top-(--header-height) z-20 -mx-4 flex flex-wrap gap-2 px-4 py-2 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
                    {signals.map((item) => {
                        const active = signal === item.key;

                        return (
                            <button
                                key={item.key}
                                type="button"
                                disabled={counts[item.key] === 0}
                                aria-pressed={active}
                                onClick={() =>
                                    setSignal(active ? null : item.key)
                                }
                                className={cn(
                                    'flex min-h-9 items-center gap-2 rounded-md border px-3 text-sm transition-[background-color,color,transform] duration-150',
                                    'active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50',
                                    active
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border hover:bg-accent',
                                )}
                            >
                                {item.label}
                                <span
                                    className={cn(
                                        'tabular-nums',
                                        active
                                            ? 'text-primary-foreground/80'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {counts[item.key]}
                                </span>
                            </button>
                        );
                    })}
                </div>

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
                        {visible.map((bucket, index) => (
                            <section
                                key={bucket.key}
                                className="animate-in duration-300 fill-mode-backwards fade-in slide-in-from-bottom-2"
                                style={{ animationDelay: `${index * 40}ms` }}
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
                                        {(buckets.get(bucket.key) ?? []).length}
                                    </span>
                                </h2>

                                <ul
                                    aria-labelledby={`bucket-${bucket.key}`}
                                    className="mt-1 divide-y divide-border border-t border-border"
                                >
                                    {(buckets.get(bucket.key) ?? []).map(
                                        ({ task, group }) => (
                                            <TaskRow
                                                key={task.id}
                                                task={task}
                                                project={group.project}
                                                statuses={statuses}
                                                meta={dueLabel(task, now)}
                                                onOpen={() =>
                                                    setOpenTaskId(task.id)
                                                }
                                            />
                                        ),
                                    )}
                                </ul>
                            </section>
                        ))}
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
 * and the identity, status and date beneath it in small type. A 112px select
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
}: {
    task: TaskNode;
    project: { id: number; name: string };
    statuses: Option[];
    meta: string;
    onOpen: () => void;
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
        <li className="grid min-h-11 grid-cols-1 gap-y-0.5 px-2 py-2 hover:bg-muted/40 sm:grid-cols-[6.5rem_minmax(0,1fr)_8rem_5rem_8.5rem_2.75rem_6rem] sm:items-center sm:gap-x-3 sm:gap-y-0">
            {/* On a wide screen the reference has a column of its own, ahead of
                the title, as it does on the list, the timeline and the member
                pages. A phone has no width to spare for it there, so it leads
                the second line instead. */}
            <span
                title={task.reference}
                className="hidden truncate text-xs text-muted-foreground tabular-nums sm:block"
            >
                {task.reference}
            </span>

            {/* Wraps to two lines at any width, then clips. A long title is
                the row's own content and worth the second line; the third
                would let one task push the rest of the day off the screen.
                Rows are consequently not all the same height, so the columns
                beside it centre on whatever height the title takes. */}
            <button
                type="button"
                title={task.title}
                onClick={onOpen}
                className={cn(
                    'line-clamp-2 min-w-0 text-left text-sm hover:underline',
                    finished && 'text-muted-foreground line-through',
                )}
            >
                {task.title}
            </button>

            <Link
                href={showProject(project.id)}
                className="hidden truncate text-xs text-muted-foreground hover:text-foreground hover:underline sm:block"
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

            <span className="hidden sm:block">
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

                The reference leads it, then what decides the next hour: the
                date, the priority, and the state the work is in. */}
            <span className="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground sm:hidden">
                <span className="tabular-nums">{task.reference}</span>
                <span aria-hidden="true">·</span>
                {meta !== '' && (
                    <>
                        <span
                            className={cn(
                                'tabular-nums',
                                task.is_overdue &&
                                    'font-medium text-destructive',
                            )}
                        >
                            {meta}
                        </span>
                        <span aria-hidden="true">·</span>
                    </>
                )}
                {!finished && task.priority !== 'low' && (
                    <>
                        <span className={TASK_PRIORITY_TEXT[task.priority]}>
                            {TASK_PRIORITY_LABELS[task.priority]}
                        </span>
                        <span aria-hidden="true">·</span>
                    </>
                )}
                <span>{TASK_STATUS_LABELS[task.status]}</span>
            </span>
        </li>
    );
}

MonitoringFocus.layout = () => ({
    breadcrumbs: [{ title: 'Task saya', href: me() }],
});
