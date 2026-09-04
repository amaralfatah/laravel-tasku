import {
    DndContext,
    DragOverlay,
    KeyboardSensor,
    MeasuringStrategy,
    PointerSensor,
    closestCorners,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    DragEndEvent,
    DragOverEvent,
    DragStartEvent,
    UniqueIdentifier,
} from '@dnd-kit/core';
import {
    SortableContext,
    sortableKeyboardCoordinates,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { ProjectHeader } from '@/components/project/project-header';
import { TaskCard } from '@/components/task/task-card';
import { TaskCreateDialog } from '@/components/task/task-create-dialog';
import { TaskDetailModal } from '@/components/task/task-detail-modal';
import { TaskFilterBar } from '@/components/task/task-filters';
import { useFocusedTask } from '@/hooks/use-focused-task';
import { useTaskFilters } from '@/hooks/use-task-filters';
import { projectCrumbs } from '@/lib/project-crumbs';
import { cn } from '@/lib/utils';
import { show } from '@/routes/projects';
import { move } from '@/routes/tasks';
import type { Option } from '@/types/members';
import { TASK_STATUS_LABELS, TASK_STATUS_ORDER } from '@/types/tasks';
import type {
    ProjectSummary,
    TaskAssignee,
    TaskFilterState,
    TaskNode,
    TaskStatus,
} from '@/types/tasks';

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
 * Root tasks in the single sibling order the backend keeps them in.
 *
 * The board never sorts by `position` again after this: once a drag starts the
 * array order itself is the source of truth, so an optimistic move survives
 * until the server answers with the same order.
 */
function rootOrder(tasks: TaskNode[]): TaskNode[] {
    return tasks
        .filter((task) => task.depth === 0)
        .sort((a, b) => a.position - b.position);
}

function finishedAt(task: TaskNode): number {
    return task.completed_at ? Date.parse(task.completed_at) : 0;
}

function isStatus(value: unknown): value is TaskStatus {
    return TASK_STATUS_ORDER.includes(value as TaskStatus);
}

/**
 * Place `activeId` next to whatever it is hovering, inside the flat root order.
 *
 * `modifier` is 1 when the pointer sits below the middle of the card it hovers,
 * which is what makes a downward drag land after that card instead of before it.
 */
function applyMove(
    items: TaskNode[],
    activeId: number,
    overId: UniqueIdentifier,
    modifier: number,
): TaskNode[] {
    const activeIndex = items.findIndex((task) => task.id === activeId);

    if (activeIndex === -1) {
        return items;
    }

    const active = items[activeIndex];
    const overNumeric = Number(overId);
    const overTask = Number.isNaN(overNumeric)
        ? undefined
        : items.find((task) => task.id === overNumeric);

    const targetStatus = overTask ? overTask.status : overId;

    if (!isStatus(targetStatus)) {
        return items;
    }

    const next = items.slice();
    next.splice(activeIndex, 1);

    let insertAt: number;

    if (overTask && overTask.id !== activeId) {
        insertAt = next.findIndex((task) => task.id === overTask.id) + modifier;
    } else {
        // Dropped on the column itself, or on its empty area: append.
        const last = next.map((task) => task.status).lastIndexOf(targetStatus);

        insertAt = last === -1 ? next.length : last + 1;
    }

    next.splice(
        Math.max(0, Math.min(insertAt, next.length)),
        0,
        active.status === targetStatus
            ? active
            : { ...active, status: targetStatus },
    );

    return next;
}

export default function ProjectBoard({
    project,
    tasks,
    filters,
    statuses,
    priorities,
    assignees,
    focusTaskId,
    can,
}: PageProps) {
    const [openTaskId, setOpenTaskId] = useFocusedTask(focusTaskId);
    /**
     * What the create dialog is creating: a root task in the column whose
     * "Buat" button was pressed, or a sub task of the open task.
     */
    const [creating, setCreating] = useState<{
        parent: TaskNode | null;
        status: TaskStatus;
    } | null>(null);
    const [draggingId, setDraggingId] = useState<number | null>(null);
    const [items, setItems] = useState<TaskNode[]>(() => rootOrder(tasks));
    const [source, setSource] = useState<TaskNode[]>(tasks);

    /** Order to fall back to when a drag is cancelled or the server rejects it. */
    const snapshot = useRef<TaskNode[]>(items);

    const applyFilters = useTaskFilters(filters, show(project.id).url);

    // Re-sync with the server on every new page response. Done during render
    // rather than in an effect so the board never paints a stale order.
    if (source !== tasks) {
        setSource(tasks);
        setItems(rootOrder(tasks));
    }

    // Only root tasks appear as cards (BRD-4).
    const columns = useMemo(() => {
        const grouped: Record<TaskStatus, TaskNode[]> = {
            todo: [],
            in_progress: [],
            review: [],
            done: [],
        };

        for (const task of items) {
            grouped[task.status].push(task);
        }

        // Selesai reads as a log rather than a queue: the task finished most
        // recently sits on top. Anything without a stamp falls to the bottom
        // and keeps its board order there.
        grouped.done.sort((a, b) => finishedAt(b) - finishedAt(a));

        return grouped;
    }, [items]);

    const sensors = useSensors(
        // A small distance threshold keeps a tap from becoming a drag.
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const handleDragStart = (event: DragStartEvent) => {
        snapshot.current = items;
        setDraggingId(Number(event.active.id));
    };

    /**
     * Cross-column preview: the card is really moved into the hovered column
     * while the pointer is still down, so the columns reflow during the drag.
     */
    const handleDragOver = ({ active, over }: DragOverEvent) => {
        if (!over) {
            return;
        }

        const activeId = Number(active.id);
        const overNumeric = Number(over.id);

        setItems((current) => {
            const activeTask = current.find((task) => task.id === activeId);

            if (!activeTask) {
                return current;
            }

            const overTask = Number.isNaN(overNumeric)
                ? undefined
                : current.find((task) => task.id === overNumeric);

            const targetStatus = overTask ? overTask.status : over.id;

            // Same-column sorting is settled on drop; this handler only deals
            // with the hand-over between two columns.
            if (!isStatus(targetStatus) || targetStatus === activeTask.status) {
                return current;
            }

            return applyMove(current, activeId, over.id, 0);
        });
    };

    const handleDragEnd = ({ active, over }: DragEndEvent) => {
        setDraggingId(null);

        const before = snapshot.current;

        if (!over) {
            setItems(before);

            return;
        }

        const activeId = Number(active.id);
        const translated = active.rect.current.translated;
        const modifier =
            translated && translated.top > over.rect.top + over.rect.height / 2
                ? 1
                : 0;

        const next = applyMove(items, activeId, over.id, modifier);
        const position = next.findIndex((task) => task.id === activeId);

        if (position === -1) {
            setItems(before);

            return;
        }

        const task = next[position];
        const previous = before.findIndex((item) => item.id === activeId);

        if (position === previous && task.status === before[previous]?.status) {
            setItems(before);

            return;
        }

        setItems(next);

        router.post(
            move(task.id).url,
            { status: task.status, position },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => setItems(before),
            },
        );
    };

    const openTask = tasks.find((task) => task.id === openTaskId) ?? null;
    const draggingTask =
        draggingId === null
            ? null
            : (items.find((task) => task.id === draggingId) ?? null);

    return (
        <>
            <Head title={project.name} />

            <div className="space-y-6">
                <ProjectHeader project={project} active="board" />

                {/* Creating a task is done from the column it belongs in, so
                    the board has no second global "new task" button. */}
                <TaskFilterBar
                    filters={filters}
                    assignees={assignees}
                    statuses={statuses}
                    priorities={priorities}
                    onChange={applyFilters}
                />

                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCorners}
                    // Columns change height while dragging, so their rects have
                    // to be re-measured continuously or drops land in the gap.
                    measuring={{
                        droppable: { strategy: MeasuringStrategy.Always },
                    }}
                    onDragStart={handleDragStart}
                    onDragOver={handleDragOver}
                    onDragCancel={() => {
                        setDraggingId(null);
                        setItems(snapshot.current);
                    }}
                    onDragEnd={handleDragEnd}
                >
                    <div className="grid items-start gap-3 overflow-x-auto md:grid-cols-4">
                        {TASK_STATUS_ORDER.map((status) => (
                            <BoardColumn
                                key={status}
                                status={status}
                                tasks={columns[status]}
                                canDrag={can.contribute}
                                isDragging={draggingId !== null}
                                onOpen={setOpenTaskId}
                                onCreate={(column) =>
                                    setCreating({
                                        parent: null,
                                        status: column,
                                    })
                                }
                            />
                        ))}
                    </div>

                    <DragOverlay
                        dropAnimation={{
                            duration: 180,
                            easing: 'cubic-bezier(0.2, 0, 0, 1)',
                        }}
                    >
                        {draggingTask && (
                            <TaskCard
                                task={draggingTask}
                                draggable={false}
                                overlay
                                onOpen={() => undefined}
                            />
                        )}
                    </DragOverlay>
                </DndContext>
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
                onAddSubtask={
                    openTask
                        ? () =>
                              setCreating({
                                  parent: openTask,
                                  status: 'todo',
                              })
                        : undefined
                }
            />

            <TaskCreateDialog
                open={creating !== null}
                project={project}
                parent={creating?.parent ?? null}
                status={creating?.status ?? 'todo'}
                assignees={assignees}
                statuses={statuses}
                priorities={priorities}
                onClose={() => setCreating(null)}
            />
        </>
    );
}

function BoardColumn({
    status,
    tasks,
    canDrag,
    isDragging,
    onOpen,
    onCreate,
}: {
    status: TaskStatus;
    tasks: TaskNode[];
    canDrag: boolean;
    isDragging: boolean;
    onOpen: (id: number) => void;
    onCreate: (status: TaskStatus) => void;
}) {
    // The column is a droppable in its own right; without it an empty column
    // has nothing to collide with and cards cannot be dropped into it at all.
    const { setNodeRef, isOver } = useDroppable({ id: status });

    return (
        <section
            className={cn(
                // The sunken well: a step darker than the page, so the raised
                // cards inside it read without needing borders.
                'flex min-w-64 flex-col rounded bg-muted transition-colors',
                isOver && 'bg-primary/10',
            )}
            aria-label={TASK_STATUS_LABELS[status]}
        >
            <header className="flex items-center gap-2 px-3 pt-3 pb-2">
                <h2 className="text-sm font-medium text-muted-foreground">
                    {TASK_STATUS_LABELS[status]}
                </h2>
                <span className="rounded bg-foreground/10 px-1.5 py-0.5 text-xs font-medium text-muted-foreground tabular-nums">
                    {tasks.length}
                </span>
            </header>

            <SortableContext
                id={status}
                items={tasks.map((task) => task.id)}
                strategy={verticalListSortingStrategy}
            >
                <div
                    ref={setNodeRef}
                    className="flex min-h-16 flex-1 flex-col gap-2 overflow-y-auto px-2 pb-1 md:max-h-[calc(100vh-19rem)]"
                >
                    {/* An empty column shows nothing but its "Buat" button,
                        exactly as it does on a Jira board. The drop target only
                        announces itself once something is being dragged. */}
                    {tasks.length === 0 && isDragging && (
                        <p className="rounded border border-dashed border-primary/40 px-1 py-5 text-center text-xs text-primary">
                            Lepas di sini
                        </p>
                    )}

                    {tasks.map((task) => (
                        <TaskCard
                            key={task.id}
                            task={task}
                            draggable={canDrag && task.can_edit}
                            onOpen={() => onOpen(task.id)}
                        />
                    ))}
                </div>
            </SortableContext>

            {canDrag && (
                <button
                    type="button"
                    onClick={() => onCreate(status)}
                    className="m-2 mt-1 flex items-center gap-1.5 rounded px-1.5 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <Plus className="size-4" aria-hidden="true" />
                    Buat
                    <span className="sr-only">
                        {' '}
                        task di kolom {TASK_STATUS_LABELS[status]}
                    </span>
                </button>
            )}
        </section>
    );
}

ProjectBoard.layout = ({ project }: PageProps) => ({
    breadcrumbs: projectCrumbs(project, show(project.id)),
});
