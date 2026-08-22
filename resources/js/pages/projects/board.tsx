import {
    DndContext,
    DragOverlay,
    PointerSensor,
    closestCorners,
    useSensor,
    useSensors
    
    
} from '@dnd-kit/core';
import type {DragEndEvent, DragStartEvent} from '@dnd-kit/core';
import {
    SortableContext,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ProjectHeader } from '@/components/project/project-header';
import { TaskCard } from '@/components/task/task-card';
import { TaskCreateDialog } from '@/components/task/task-create-dialog';
import { TaskDetailSheet } from '@/components/task/task-detail-sheet';
import { TaskFilterBar } from '@/components/task/task-filters';
import { Button } from '@/components/ui/button';
import { useTaskFilters } from '@/hooks/use-task-filters';
import { cn } from '@/lib/utils';
import { index as projectsIndex, show } from '@/routes/projects';
import { move } from '@/routes/tasks';
import type { Option } from '@/types/members';
import {
    TASK_STATUS_ACCENT,
    TASK_STATUS_LABELS,
    TASK_STATUS_ORDER,
} from '@/types/tasks';
import type {ProjectSummary, TaskAssignee, TaskFilterState, TaskNode, TaskStatus} from '@/types/tasks';

type PageProps = {
    project: ProjectSummary;
    tasks: TaskNode[];
    filters: TaskFilterState;
    statuses: Option[];
    priorities: Option[];
    assignees: TaskAssignee[];
    maxDepth: number;
    can: { contribute: boolean; edit_project: boolean };
};

export default function ProjectBoard({
    project,
    tasks,
    filters,
    statuses,
    priorities,
    assignees,
    can,
}: PageProps) {
    const [openTaskId, setOpenTaskId] = useState<number | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [draggingId, setDraggingId] = useState<number | null>(null);

    const applyFilters = useTaskFilters(filters, show(project.id).url);

    // Only root tasks appear as cards (BRD-4).
    const rootTasks = useMemo(
        () => tasks.filter((task) => task.depth === 0),
        [tasks],
    );

    const columns = useMemo(() => {
        const grouped: Record<TaskStatus, TaskNode[]> = {
            todo: [],
            in_progress: [],
            done: [],
        };

        for (const task of rootTasks) {
            grouped[task.status].push(task);
        }

        for (const status of TASK_STATUS_ORDER) {
            grouped[status].sort((a, b) => a.position - b.position);
        }

        return grouped;
    }, [rootTasks]);

    const sensors = useSensors(
        // A small distance threshold keeps a tap from becoming a drag.
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    );

    const findTask = (id: number | string) =>
        rootTasks.find((task) => task.id === Number(id)) ?? null;

    const handleDragEnd = (event: DragEndEvent) => {
        setDraggingId(null);

        const { active, over } = event;

        if (!over) {
            return;
        }

        const task = findTask(active.id);

        if (!task) {
            return;
        }

        const overTask = findTask(over.id);
        const targetStatus = (
            overTask ? overTask.status : (over.id as TaskStatus)
        ) as TaskStatus;

        if (!TASK_STATUS_ORDER.includes(targetStatus)) {
            return;
        }

        const column = columns[targetStatus].filter(
            (item) => item.id !== task.id,
        );
        const index = overTask
            ? column.findIndex((item) => item.id === overTask.id)
            : column.length;

        const position = index === -1 ? column.length : index;

        if (targetStatus === task.status && position === task.position) {
            return;
        }

        router.post(
            move(task.id).url,
            { status: targetStatus, position },
            { preserveScroll: true, preserveState: false },
        );
    };

    const openTask = tasks.find((task) => task.id === openTaskId) ?? null;
    const draggingTask = draggingId === null ? null : findTask(draggingId);

    return (
        <>
            <Head title={project.name} />

            <div className="space-y-6">
                <ProjectHeader project={project} active="board" />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <TaskFilterBar
                        filters={filters}
                        assignees={assignees}
                        statuses={statuses}
                        priorities={priorities}
                        onChange={applyFilters}
                    />

                    {can.contribute && (
                        <Button size="sm" onClick={() => setCreateOpen(true)}>
                            <Plus className="size-4" aria-hidden="true" />
                            Task baru
                        </Button>
                    )}
                </div>

                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCorners}
                    onDragStart={(event: DragStartEvent) =>
                        setDraggingId(Number(event.active.id))
                    }
                    onDragCancel={() => setDraggingId(null)}
                    onDragEnd={handleDragEnd}
                >
                    <div className="grid gap-4 overflow-x-auto md:grid-cols-3">
                        {TASK_STATUS_ORDER.map((status) => (
                            <BoardColumn
                                key={status}
                                status={status}
                                tasks={columns[status]}
                                canDrag={can.contribute}
                                statuses={statuses}
                                onOpen={setOpenTaskId}
                            />
                        ))}
                    </div>

                    <DragOverlay>
                        {draggingTask && (
                            <TaskCard
                                task={draggingTask}
                                draggable={false}
                                onOpen={() => undefined}
                            />
                        )}
                    </DragOverlay>
                </DndContext>
            </div>

            <TaskDetailSheet
                task={openTask}
                assignees={assignees}
                statuses={statuses}
                priorities={priorities}
                onClose={() => setOpenTaskId(null)}
            />

            <TaskCreateDialog
                open={createOpen}
                projectId={project.id}
                parent={null}
                assignees={assignees}
                priorities={priorities}
                onClose={() => setCreateOpen(false)}
            />
        </>
    );
}

function BoardColumn({
    status,
    tasks,
    canDrag,
    statuses,
    onOpen,
}: {
    status: TaskStatus;
    tasks: TaskNode[];
    canDrag: boolean;
    statuses: Option[];
    onOpen: (id: number) => void;
}) {
    return (
        <section
            className="flex min-w-64 flex-col overflow-hidden rounded-xl border bg-muted/40"
            aria-label={TASK_STATUS_LABELS[status]}
        >
            {/* Accent rail carries the column's status colour without tinting
                the cards inside it. */}
            <div
                className={cn('h-1 w-full', TASK_STATUS_ACCENT[status])}
                aria-hidden="true"
            />

            <header className="flex items-center justify-between gap-2 border-b bg-card/60 px-3 py-2.5">
                <h2 className="text-sm font-semibold">
                    {TASK_STATUS_LABELS[status]}
                </h2>
                <span className="rounded-full bg-background px-2 py-0.5 text-xs font-medium text-muted-foreground tabular-nums">
                    {tasks.length}
                </span>
            </header>

            <SortableContext
                id={status}
                items={tasks.map((task) => task.id)}
                strategy={verticalListSortingStrategy}
            >
                <div className="flex min-h-24 flex-1 flex-col gap-2 p-2">
                    {tasks.length === 0 && (
                        <p className="px-1 py-6 text-center text-xs text-muted-foreground">
                            Belum ada task di kolom ini.
                        </p>
                    )}

                    {tasks.map((task) => (
                        <div key={task.id} className="space-y-1">
                            <TaskCard
                                task={task}
                                draggable={canDrag && task.can_edit}
                                onOpen={() => onOpen(task.id)}
                            />

                            {canDrag && task.can_edit && (
                                <StatusFallback
                                    task={task}
                                    statuses={statuses}
                                />
                            )}
                        </div>
                    ))}
                </div>
            </SortableContext>
        </section>
    );
}

/**
 * Keyboard and screen-reader alternative to dragging between columns
 * (accessibility: drag and drop must have a non-drag equivalent).
 */
function StatusFallback({
    task,
    statuses,
}: {
    task: TaskNode;
    statuses: Option[];
}) {
    return (
        <label className="flex items-center gap-1 px-1 text-[11px] text-muted-foreground">
            <span className="sr-only">Pindahkan {task.title} ke kolom</span>
            <select
                value={task.status}
                onChange={(event) =>
                    router.post(
                        move(task.id).url,
                        { status: event.target.value },
                        { preserveScroll: true, preserveState: false },
                    )
                }
                className="w-full rounded border border-transparent bg-transparent py-1 hover:border-input focus-visible:border-ring"
                aria-label={`Pindahkan ${task.title} ke kolom lain`}
            >
                {statuses.map((status) => (
                    <option key={status.value} value={status.value}>
                        Pindah ke {status.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

ProjectBoard.layout = ({ project }: PageProps) => ({
    breadcrumbs: [
        { title: 'Project', href: projectsIndex() },
        { title: project.name, href: show(project.id) },
    ],
});
