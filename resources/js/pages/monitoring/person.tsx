import { Head, Link, router } from '@inertiajs/react';
import { CalendarOff, ClipboardList, Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ProgressBar } from '@/components/task/progress-bar';
import { TaskDetailModal } from '@/components/task/task-detail-modal';
import {
    TimelineBar,
    TimelineGridLines,
    TimelineHeader,
    TimelineToday,
    ZOOM_LABELS,
    fittingZoom,
    useFillWidth,
    useTimelineScale,
} from '@/components/task/timeline-scale';
import type { Zoom } from '@/components/task/timeline-scale';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { formatDay } from '@/lib/week';
import { people, person as personRoute } from '@/routes/monitoring';
import { exportMethod as exportPerson } from '@/routes/monitoring/person';
import { show as showProject } from '@/routes/projects';
import type { Option } from '@/types/members';
import type { TaskAssignee, TaskNode } from '@/types/tasks';

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

const ZOOMS: Zoom[] = ['week', 'month', 'quarter'];

/**
 * Sticky label column, in pixels.
 *
 * The desktop column is wider than a phone, which left no chart beside it at
 * all; the narrow one drops the progress bar and keeps reference plus title.
 * Applied as an inline width so the constant stays the single source of it.
 */
const LABEL_WIDTH = 352;
const LABEL_WIDTH_MOBILE = 176;

/**
 * One person's work across every project (MON-2..MON-5), laid out as a
 * hierarchy on the left and a weekly bar chart on the right — the same shape
 * as the spreadsheet this replaces.
 */
export default function MonitoringPerson({
    member,
    tasks,
    statuses,
    priorities,
    filters,
    isSelf,
}: {
    member: Member;
    tasks: ProjectGroup[];
    statuses: Option[];
    priorities: Option[];
    filters: { from: string | null; to: string | null };
    isSelf: boolean;
}) {
    const getInitials = useInitials();
    const [openTaskId, setOpenTaskId] = useState<number | null>(null);

    const allTasks = useMemo(
        () => tasks.flatMap((group) => group.tasks),
        [tasks],
    );

    const ranges = useMemo(
        () =>
            allTasks.map((task) => ({
                start: task.start_date,
                end: task.due_date,
            })),
        [allTasks],
    );

    // Someone's tasks run across every project they touch, so the span here is
    // usually far wider than one project's — it opens at the level that fits.
    const [zoom, setZoom] = useState<Zoom>(() => fittingZoom(ranges));

    const isMobile = useIsMobile();
    const labelWidth = isMobile ? LABEL_WIDTH_MOBILE : LABEL_WIDTH;

    const [panelRef, fillWidth] = useFillWidth<HTMLDivElement>(labelWidth);

    const scale = useTimelineScale(ranges, zoom, fillWidth);

    const unscheduled = allTasks.filter(
        (task) => !task.start_date || !task.due_date,
    );

    // The sheet needs the assignee list of the project the task belongs to,
    // so the open task is looked up together with its block.
    const open = useMemo(() => {
        const group = tasks.find((item) =>
            item.tasks.some((task) => task.id === openTaskId),
        );
        const task = group?.tasks.find((item) => item.id === openTaskId);

        return group === undefined || task === undefined
            ? null
            : { task, assignees: group.assignees };
    }, [tasks, openTaskId]);

    // The download mirrors what is on screen, so the range filter and the
    // zoom both ride along.
    const exportUrl = exportPerson(member.id, {
        query: {
            from: filters.from ?? undefined,
            to: filters.to ?? undefined,
            zoom,
        },
    }).url;

    const applyRange = (patch: { from?: string | null; to?: string | null }) =>
        router.get(
            personRoute(member.id).url,
            {
                from: (patch.from ?? filters.from) || undefined,
                to: (patch.to ?? filters.to) || undefined,
            },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title={member.name} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center gap-3">
                    <Avatar className="size-12">
                        <AvatarImage src={member.avatar ?? undefined} alt="" />
                        <AvatarFallback>
                            {getInitials(member.name)}
                        </AvatarFallback>
                    </Avatar>

                    <div className="min-w-0 flex-1">
                        <h1 className="flex min-w-0 items-center gap-2 text-xl font-semibold">
                            <span className="truncate">{member.name}</span>
                            {isSelf && (
                                <Badge
                                    variant="secondary"
                                    className="shrink-0 font-normal"
                                >
                                    Anda
                                </Badge>
                            )}
                        </h1>
                        <p className="truncate text-sm text-muted-foreground">
                            {member.org_unit ?? member.email}
                        </p>
                    </div>

                    {/* One wrapping unit: the identity beside it is `flex-1`
                        with `min-w-0`, so loose buttons never pushed a row of
                        their own — they squeezed the name to nothing instead. */}
                    <div className="flex w-full shrink-0 gap-2 sm:w-auto">
                        <Button variant="outline" size="sm" asChild>
                            <a href={exportUrl}>
                                <Download aria-hidden="true" />
                                Ekspor Excel
                            </a>
                        </Button>

                        <Button variant="outline" size="sm" asChild>
                            <Link href={people()}>Semua anggota</Link>
                        </Button>
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="range-from" className="text-xs">
                            Dari tanggal
                        </Label>
                        <Input
                            id="range-from"
                            type="date"
                            className="w-36 sm:w-44"
                            value={filters.from ?? ''}
                            onChange={(event) =>
                                applyRange({ from: event.target.value || null })
                            }
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="range-to" className="text-xs">
                            Sampai tanggal
                        </Label>
                        <Input
                            id="range-to"
                            type="date"
                            className="w-36 sm:w-44"
                            value={filters.to ?? ''}
                            onChange={(event) =>
                                applyRange({ to: event.target.value || null })
                            }
                        />
                    </div>

                    {(filters.from || filters.to) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => applyRange({ from: null, to: null })}
                        >
                            Reset rentang
                        </Button>
                    )}

                    <div
                        className="ml-auto flex rounded-md border p-0.5"
                        role="group"
                        aria-label="Tingkat zoom"
                    >
                        {ZOOMS.map((level) => (
                            <Button
                                key={level}
                                size="sm"
                                variant={zoom === level ? 'secondary' : 'ghost'}
                                aria-pressed={zoom === level}
                                onClick={() => setZoom(level)}
                            >
                                {ZOOM_LABELS[level]}
                            </Button>
                        ))}
                    </div>
                </div>

                {tasks.length === 0 ? (
                    <div className="rounded-lg border p-12 text-center">
                        <ClipboardList
                            className="mx-auto mb-3 size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Belum ada task</p>
                        <p className="text-sm text-muted-foreground">
                            Tidak ada task yang ditugaskan pada rentang ini.
                        </p>
                    </div>
                ) : (
                    <div
                        ref={panelRef}
                        className="overflow-x-auto rounded-lg border"
                    >
                        <div
                            className="min-w-max"
                            style={{
                                // Left column is sticky while the weeks scroll (TML-2).
                                width: `${labelWidth + scale.width}px`,
                            }}
                        >
                            <div className="flex border-b bg-muted">
                                {/* Opaque like the project rows below it: the
                                    week columns scroll underneath this cell,
                                    and a tint shows them through. */}
                                <div
                                    className="sticky left-0 z-10 shrink-0 border-r bg-muted px-3 py-2 text-xs font-medium text-muted-foreground"
                                    style={{ width: `${labelWidth}px` }}
                                >
                                    {isMobile
                                        ? 'Task · Judul'
                                        : 'Task · Judul · Progress'}
                                </div>
                                <TimelineHeader scale={scale} />
                            </div>

                            {tasks.map((group, index) => (
                                <div
                                    key={group.project.id}
                                    className={cn(
                                        // The page crosses projects, so where one
                                        // ends has to read at a glance — a tinted
                                        // row alone was lost among the task rows.
                                        index > 0 && 'border-t-4 border-border',
                                    )}
                                >
                                    <div className="flex border-b bg-muted">
                                        <div
                                            className="sticky left-0 z-10 shrink-0 border-r bg-muted px-3 py-2"
                                            style={{
                                                width: `${labelWidth}px`,
                                            }}
                                        >
                                            <Link
                                                href={showProject(
                                                    group.project.id,
                                                )}
                                                className="block truncate text-sm font-semibold tracking-wide uppercase hover:underline"
                                            >
                                                {group.project.name}
                                            </Link>
                                        </div>
                                        <div
                                            style={{
                                                width: `${scale.width}px`,
                                            }}
                                        />
                                    </div>

                                    {group.tasks.map((task) => (
                                        <div
                                            key={task.id}
                                            className="group flex border-b last:border-b-0 hover:bg-accent"
                                        >
                                            <div
                                                className="sticky left-0 z-10 flex shrink-0 items-center gap-2 border-r bg-background px-3 py-1.5 group-hover:bg-accent"
                                                style={{
                                                    width: `${labelWidth}px`,
                                                    paddingLeft: `${12 + task.depth * 14}px`,
                                                }}
                                            >
                                                <span className="shrink-0 text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                                    {task.reference}
                                                </span>
                                                <button
                                                    type="button"
                                                    title={task.title}
                                                    className="min-w-0 flex-1 truncate text-left text-sm hover:underline"
                                                    onClick={() =>
                                                        setOpenTaskId(task.id)
                                                    }
                                                >
                                                    {task.title}
                                                </button>
                                                {/* The narrow label column has
                                                    no room for it; the bar's
                                                    own fill already carries
                                                    progress on the chart. */}
                                                {!isMobile && (
                                                    <span className="w-24 shrink-0">
                                                        <ProgressBar
                                                            value={
                                                                task.progress
                                                            }
                                                            rollup={
                                                                task.rollup_progress
                                                            }
                                                            showLabel
                                                        />
                                                    </span>
                                                )}
                                            </div>

                                            <div
                                                className="relative h-9"
                                                style={{
                                                    width: `${scale.width}px`,
                                                }}
                                            >
                                                <TimelineGridLines
                                                    scale={scale}
                                                />
                                                <TimelineToday scale={scale} />
                                                <TimelineBar
                                                    scale={scale}
                                                    start={task.start_date}
                                                    end={task.due_date}
                                                    progress={task.progress}
                                                    overdue={task.is_overdue}
                                                    label={`${task.title}: ${formatDay(task.start_date)} sampai ${formatDay(task.due_date)}, progress ${task.progress}%`}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {unscheduled.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="flex items-center gap-2 text-sm font-medium">
                            <CalendarOff
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Belum dijadwalkan ({unscheduled.length})
                        </h2>

                        <ul className="divide-y rounded-lg border text-sm">
                            {unscheduled.map((task) => (
                                <li
                                    key={task.id}
                                    className="flex items-center gap-3 px-3 py-2"
                                >
                                    <span className="shrink-0 text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                        {task.reference}
                                    </span>
                                    <button
                                        type="button"
                                        className="min-w-0 flex-1 truncate text-left hover:underline"
                                        onClick={() => setOpenTaskId(task.id)}
                                    >
                                        {task.title}
                                    </button>
                                    <span
                                        className={cn(
                                            'shrink-0 text-xs',
                                            'text-muted-foreground',
                                        )}
                                    >
                                        {task.progress}%
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            <TaskDetailModal
                task={open?.task ?? null}
                assignees={open?.assignees ?? []}
                statuses={statuses}
                priorities={priorities}
                onClose={() => setOpenTaskId(null)}
            />
        </>
    );
}

MonitoringPerson.layout = ({ member }: { member: Member }) => ({
    breadcrumbs: [
        { title: 'Monitoring per anggota', href: people() },
        { title: member.name, href: personRoute(member.id) },
    ],
});
