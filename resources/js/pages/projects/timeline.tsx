import { Head } from '@inertiajs/react';
import { CalendarOff, ChevronRight, Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ProjectHeader } from '@/components/project/project-header';
import { TaskDetailModal } from '@/components/task/task-detail-modal';
import { TaskFilterBar } from '@/components/task/task-filters';
import {
    TimelineBar,
    TimelineGridLines,
    TimelineHeader,
    TimelineToday,
    ZOOM_LABELS,
    useFillWidth,
    useTimelineScale,
} from '@/components/task/timeline-scale';
import type { Zoom } from '@/components/task/timeline-scale';
import { Button } from '@/components/ui/button';
import { useFocusedTask } from '@/hooks/use-focused-task';
import { useTaskFilters } from '@/hooks/use-task-filters';
import { projectCrumbs } from '@/lib/project-crumbs';
import { cn } from '@/lib/utils';
import { formatDay } from '@/lib/week';
import { timeline } from '@/routes/projects';
import { exportMethod as exportTimeline } from '@/routes/projects/timeline';
import type { Option } from '@/types/members';
import type {
    ProjectSummary,
    TaskAssignee,
    TaskFilterState,
    TaskNode,
} from '@/types/tasks';

const ZOOMS: Zoom[] = ['week', 'month', 'quarter'];
const LEFT_WIDTH = 320;

type PageProps = {
    project: ProjectSummary;
    tasks: TaskNode[];
    filters: TaskFilterState;
    statuses: Option[];
    priorities: Option[];
    assignees: TaskAssignee[];
    maxDepth: number;
    /** Task to open on arrival, e.g. when following a notification (NTF-3). */
    focusTaskId: number | null;
    can: { contribute: boolean; edit_project: boolean };
};

/**
 * A task's own dates, or — for a parent without its own — the span of its
 * descendants (TML-6).
 */
type Span = { start: string | null; end: string | null; derived: boolean };

function resolveSpans(tasks: TaskNode[]): Map<number, Span> {
    const spans = new Map<number, Span>();

    // Deepest first, so a parent can read spans its children already resolved.
    const ordered = [...tasks].sort((a, b) => b.depth - a.depth);

    for (const task of ordered) {
        const descendants = tasks.filter(
            (other) => other.id !== task.id && other.path.startsWith(task.path),
        );

        const starts = [
            task.start_date,
            ...descendants.map((child) => child.start_date),
        ].filter((value): value is string => Boolean(value));

        const ends = [
            task.due_date,
            ...descendants.map((child) => child.due_date),
        ].filter((value): value is string => Boolean(value));

        spans.set(task.id, {
            start: starts.length
                ? starts.reduce((a, b) => (a < b ? a : b))
                : null,
            end: ends.length ? ends.reduce((a, b) => (a > b ? a : b)) : null,
            derived:
                descendants.length > 0 && !(task.start_date && task.due_date),
        });
    }

    return spans;
}

export default function ProjectTimeline({
    project,
    tasks,
    filters,
    statuses,
    priorities,
    assignees,
    focusTaskId,
}: PageProps) {
    const [zoom, setZoom] = useState<Zoom>('week');
    const [collapsed, setCollapsed] = useState<Set<number>>(new Set());
    const [openTaskId, setOpenTaskId] = useFocusedTask(focusTaskId);

    const applyFilters = useTaskFilters(filters, timeline(project.id).url);

    // The download mirrors what is on screen: the same filters, the same zoom.
    const exportUrl = exportTimeline(project.id, {
        query: {
            assignee_id: filters.assignee_id ?? undefined,
            status: filters.status ?? undefined,
            priority: filters.priority ?? undefined,
            search: filters.search ?? undefined,
            overdue: filters.overdue ? 1 : undefined,
            zoom,
        },
    }).url;

    const spans = useMemo(() => resolveSpans(tasks), [tasks]);

    const scheduled = useMemo(
        () =>
            tasks.filter(
                (task) => spans.get(task.id)?.start && spans.get(task.id)?.end,
            ),
        [tasks, spans],
    );

    // TML-9: tasks with no dates of their own or below them.
    const unscheduled = useMemo(
        () =>
            tasks.filter(
                (task) =>
                    !spans.get(task.id)?.start || !spans.get(task.id)?.end,
            ),
        [tasks, spans],
    );

    const ranges = useMemo(
        () =>
            scheduled.map((task) => ({
                start: spans.get(task.id)?.start ?? null,
                end: spans.get(task.id)?.end ?? null,
            })),
        [scheduled, spans],
    );

    const [panelRef, fillWidth] = useFillWidth<HTMLDivElement>(LEFT_WIDTH);

    const scale = useTimelineScale(ranges, zoom, fillWidth);

    const childCounts = useMemo(() => {
        const counts = new Map<number, number>();

        for (const task of tasks) {
            if (task.parent_task_id !== null) {
                counts.set(
                    task.parent_task_id,
                    (counts.get(task.parent_task_id) ?? 0) + 1,
                );
            }
        }

        return counts;
    }, [tasks]);

    // TML-5: hide the descendants of a collapsed row.
    const visible = useMemo(() => {
        if (collapsed.size === 0) {
            return scheduled;
        }

        const hidden = scheduled
            .filter((task) => collapsed.has(task.id))
            .map((task) => task.path);

        return scheduled.filter(
            (task) =>
                !hidden.some(
                    (path) => task.path !== path && task.path.startsWith(path),
                ),
        );
    }, [scheduled, collapsed]);

    const toggle = (id: number) =>
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const openTask = tasks.find((task) => task.id === openTaskId) ?? null;

    return (
        <>
            <Head title={`Timeline ${project.name}`} />

            <div className="space-y-6">
                <ProjectHeader project={project} active="timeline" />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <TaskFilterBar
                        filters={filters}
                        assignees={assignees}
                        statuses={statuses}
                        priorities={priorities}
                        onChange={applyFilters}
                    />

                    <Button variant="outline" size="sm" asChild>
                        <a href={exportUrl}>
                            <Download aria-hidden="true" />
                            Ekspor Excel
                        </a>
                    </Button>

                    <div
                        className="flex rounded-md border p-0.5"
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

                {scheduled.length === 0 ? (
                    <div className="rounded-lg border p-12 text-center">
                        <CalendarOff
                            className="mx-auto mb-3 size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Belum ada task terjadwal</p>
                        <p className="text-sm text-muted-foreground">
                            Isi tanggal mulai dan selesai agar task muncul di
                            timeline.
                        </p>
                    </div>
                ) : (
                    <div
                        ref={panelRef}
                        className="overflow-x-auto rounded-lg border"
                    >
                        <div
                            className="min-w-max"
                            style={{ width: `${LEFT_WIDTH + scale.width}px` }}
                        >
                            <div className="flex border-b bg-muted">
                                <div
                                    // Opaque, not a tint: the month columns
                                    // scroll underneath this cell, and a
                                    // translucent one shows them through.
                                    className="sticky left-0 z-10 shrink-0 border-r bg-muted px-3 py-1 text-xs font-medium text-muted-foreground"
                                    style={{ width: `${LEFT_WIDTH}px` }}
                                >
                                    <div className="pt-1">Task</div>
                                    <div className="text-[10px] font-normal">
                                        Task &amp; judul
                                    </div>
                                </div>

                                <TimelineHeader scale={scale} />
                            </div>

                            {visible.map((task) => {
                                const span = spans.get(task.id)!;
                                const hasChildren =
                                    (childCounts.get(task.id) ?? 0) > 0;

                                return (
                                    <div
                                        key={task.id}
                                        className="group flex border-b last:border-b-0 hover:bg-accent"
                                    >
                                        <div
                                            // The sticky cell paints over the
                                            // row, so it has to carry the hover
                                            // itself — and both have to be
                                            // opaque to cover the grid behind.
                                            className="sticky left-0 z-10 flex shrink-0 items-center gap-1.5 border-r bg-background px-2 py-1.5 group-hover:bg-accent"
                                            style={{
                                                width: `${LEFT_WIDTH}px`,
                                                paddingLeft: `${8 + task.depth * 14}px`,
                                            }}
                                        >
                                            {hasChildren ? (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        toggle(task.id)
                                                    }
                                                    aria-expanded={
                                                        !collapsed.has(task.id)
                                                    }
                                                    aria-label={
                                                        collapsed.has(task.id)
                                                            ? `Buka ${task.title}`
                                                            : `Tutup ${task.title}`
                                                    }
                                                    className="flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-muted"
                                                >
                                                    <ChevronRight
                                                        className={cn(
                                                            'size-3.5 transition-transform duration-150',
                                                            !collapsed.has(
                                                                task.id,
                                                            ) && 'rotate-90',
                                                        )}
                                                    />
                                                </button>
                                            ) : (
                                                <span
                                                    className="size-5 shrink-0"
                                                    aria-hidden="true"
                                                />
                                            )}

                                            <span className="shrink-0 text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                                {task.reference}
                                            </span>

                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setOpenTaskId(task.id)
                                                }
                                                className="min-w-0 flex-1 truncate text-left text-sm hover:underline"
                                            >
                                                {task.title}
                                            </button>
                                        </div>

                                        <div
                                            className="relative h-9 shrink-0"
                                            style={{
                                                width: `${scale.width}px`,
                                            }}
                                        >
                                            <TimelineGridLines scale={scale} />
                                            <TimelineToday scale={scale} />
                                            <TimelineBar
                                                scale={scale}
                                                start={span.start}
                                                end={span.end}
                                                progress={task.progress}
                                                overdue={task.is_overdue}
                                                muted={span.derived}
                                                onClick={() =>
                                                    setOpenTaskId(task.id)
                                                }
                                                label={`${task.reference} ${task.title}: ${formatDay(span.start)} sampai ${formatDay(span.end)}, progress ${task.progress}%${span.derived ? ', rentang dihitung dari sub task' : ''}`}
                                            />
                                        </div>
                                    </div>
                                );
                            })}
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
                                <li key={task.id}>
                                    <button
                                        type="button"
                                        onClick={() => setOpenTaskId(task.id)}
                                        className="flex min-h-11 w-full items-center gap-3 px-3 text-left hover:bg-muted/40"
                                    >
                                        <span className="shrink-0 text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                            {task.reference}
                                        </span>
                                        <span className="min-w-0 flex-1 truncate">
                                            {task.title}
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                            {task.progress}%
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <p className="text-xs text-muted-foreground">
                    Bar abu-abu berarti rentangnya dihitung dari sub task. Garis
                    merah menandai hari ini. Bar tidak bisa digeser di versi ini
                    — ubah tanggal lewat panel detail task.
                </p>
            </div>

            <TaskDetailModal
                task={openTask}
                subtasks={tasks.filter(
                    (item) => item.parent_task_id === openTaskId,
                )}
                assignees={assignees}
                statuses={statuses}
                priorities={priorities}
                onClose={() => setOpenTaskId(null)}
                onOpenTask={setOpenTaskId}
            />
        </>
    );
}

ProjectTimeline.layout = ({ project }: PageProps) => ({
    breadcrumbs: projectCrumbs(project, timeline(project.id)),
});
