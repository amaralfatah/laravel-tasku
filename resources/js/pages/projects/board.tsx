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
import { Head, router, useForm } from '@inertiajs/react';
import { ChevronDown, Plus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { ProjectHeader } from '@/components/project/project-header';
import { TaskAiChat } from '@/components/task/task-ai-chat';
import { TaskCard } from '@/components/task/task-card';
import { TaskCreateDialog } from '@/components/task/task-create-dialog';
import { TaskDetailModal } from '@/components/task/task-detail-modal';
import { TaskFilterBar } from '@/components/task/task-filters';
import { Textarea } from '@/components/ui/textarea';
import { useFocusedTask } from '@/hooks/use-focused-task';
import { useTaskFilters } from '@/hooks/use-task-filters';
import { projectCrumbs } from '@/lib/project-crumbs';
import { today } from '@/lib/today';
import { cn } from '@/lib/utils';
import { show } from '@/routes/projects';
import { move, store } from '@/routes/tasks';
import { apply as aiApply, plan as aiPlan } from '@/routes/tasks/ai';
import type { Option } from '@/types/members';
import type { RequesterOption } from '@/types/requesters';
import {
    STATUS_CATEGORY,
    TASK_STATUS_LABELS,
    TASK_STATUS_ORDER,
} from '@/types/tasks';
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
    requesters: RequesterOption[];
    maxDepth: number;
    /** Task to open on arrival, e.g. when following a notification (NTF-3). */
    focusTaskId: number | null;
    /** AI models this deployment can run; empty when none can. */
    aiModels: Option[];
    aiModel: string | null;
    can: {
        contribute: boolean;
        edit_project: boolean;
        /** False wherever no AI model is available to plan with. */
        ai: boolean;
    };
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

/**
 * When a card was finished, for ordering and for folding the old ones away.
 *
 * `completed_at` is the real answer, but imported work and rows closed before
 * the column existed carry none. Those fall back to `updated_at` — the last
 * time anyone touched the card — rather than to zero, which sent a card closed
 * last year and one closed this morning to the same place.
 */
function finishedAt(task: TaskNode): number {
    const stamp = task.completed_at ?? task.updated_at;

    return stamp ? Date.parse(stamp) : 0;
}

/**
 * Cards finished before this moment are folded away by default.
 *
 * Selesai and Dibatalkan only ever grow, so a quarter of closed work buries
 * the card that closed this morning. A month back is the same horizon the
 * sidebar uses for finished projects.
 */
function archiveCutoff(): number {
    const cutoff = new Date();

    cutoff.setMonth(cutoff.getMonth() - 1);

    return cutoff.getTime();
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
    requesters,
    focusTaskId,
    aiModels,
    aiModel,
    can,
}: PageProps) {
    const [openTaskId, setOpenTaskId] = useFocusedTask(focusTaskId);
    /**
     * What the create dialog is creating: a sub task of the open task. A card
     * on the board itself is typed into the column instead, so the dialog is
     * only reached from the detail modal.
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

    const applyFilters = useTaskFilters(
        filters,
        show(project.id).url,
        project.id,
    );

    // Re-sync with the server on every new page response. Done during render
    // rather than in an effect so the board never paints a stale order.
    if (source !== tasks) {
        setSource(tasks);
        setItems(rootOrder(tasks));
    }

    // Only root tasks appear as cards (BRD-4).
    const columns = useMemo(() => {
        const grouped = Object.fromEntries(
            TASK_STATUS_ORDER.map((status) => [status, [] as TaskNode[]]),
        ) as Record<TaskStatus, TaskNode[]>;

        for (const task of items) {
            if (grouped[task.status]) {
                grouped[task.status].push(task);
            }
        }

        // Selesai reads as a log rather than a queue: the task finished most
        // recently sits on top. Anything without a stamp falls to the bottom
        // and keeps its board order there.
        for (const status of TASK_STATUS_ORDER) {
            if (STATUS_CATEGORY[status] === 'done') {
                grouped[status].sort((a, b) => finishedAt(b) - finishedAt(a));
            }
        }

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

            <div className="flex min-h-0 min-w-0 flex-1 flex-col gap-4">
                <div className="shrink-0">
                    <ProjectHeader project={project} active="board" />
                </div>

                {/* Creating a task is done from the column it belongs in, so
                    the board has no second global "new task" button. The
                    assistant is not in this row either — it floats in the
                    corner, because a sentence may touch several columns and
                    the board stays readable behind it. */}
                <div className="shrink-0">
                    <TaskFilterBar
                        filters={filters}
                        assignees={assignees}
                        statuses={statuses}
                        priorities={priorities}
                        onChange={applyFilters}
                    />
                </div>

                <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                    <DndContext
                        // Without a fixed id, dnd-kit numbers its aria-describedby
                        // from a counter that restarts on the client, so the SSR
                        // markup and the hydrated markup disagree.
                        id="project-board"
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
                        {/* Jira's board is a row of fixed-width wells that
                            scrolls sideways, not a grid that stretches: on a full
                            width page an equal-share column made a card wider than
                            the title it carries. */}
                        <div className="flex min-h-0 w-full min-w-0 flex-1 items-start gap-3 overflow-x-auto pb-2">
                            {TASK_STATUS_ORDER.map((status) => (
                                <BoardColumn
                                    key={status}
                                    projectId={project.id}
                                    status={status}
                                    tasks={columns[status]}
                                    canDrag={can.contribute}
                                    isDragging={draggingId !== null}
                                    onOpen={setOpenTaskId}
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
            </div>

            <TaskDetailModal
                task={openTask}
                subtasks={tasks.filter(
                    (item) => item.parent_task_id === openTaskId,
                )}
                parent={
                    tasks.find(
                        (item) => item.id === openTask?.parent_task_id,
                    ) ?? null
                }
                assignees={assignees}
                requesters={requesters}
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
                requesters={requesters}
                statuses={statuses}
                priorities={priorities}
                onClose={() => setCreating(null)}
            />

            {can.ai && aiModel !== null && (
                <TaskAiChat
                    planUrl={aiPlan(project).url}
                    applyUrl={aiApply(project).url}
                    tasks={tasks}
                    assignees={assignees}
                    statuses={statuses}
                    models={aiModels}
                    defaultModel={aiModel}
                />
            )}
        </>
    );
}

function BoardColumn({
    projectId,
    status,
    tasks,
    canDrag,
    isDragging,
    onOpen,
}: {
    projectId: number;
    status: TaskStatus;
    tasks: TaskNode[];
    canDrag: boolean;
    isDragging: boolean;
    onOpen: (id: number) => void;
}) {
    // The column is a droppable in its own right; without it an empty column
    // has nothing to collide with and cards cannot be dropped into it at all.
    const { setNodeRef, isOver } = useDroppable({ id: status });
    const [composing, setComposing] = useState(false);
    /** Whether the column's older finished cards are unfolded. */
    const [showArchived, setShowArchived] = useState(false);
    /**
     * What was typed into the composer, kept out here so closing it — by
     * clicking away, or with Escape — does not throw the sentence away. Reopening
     * the column's composer hands it back.
     */
    const [draft, setDraft] = useState('');

    // Only the done categories fold: a task can sit in To Do for a year and
    // still be the thing that needs doing.
    const archived = useMemo(() => {
        if (STATUS_CATEGORY[status] !== 'done') {
            return [] as TaskNode[];
        }

        const cutoff = archiveCutoff();

        return tasks.filter((task) => {
            const finished = finishedAt(task);

            // A card with no usable stamp at all stays in sight: silence is
            // not evidence that the work is old.
            return finished > 0 && finished < cutoff;
        });
    }, [status, tasks]);

    // The column is already newest first, so the folded cards are its tail and
    // unfolding them keeps that order.
    const shown = useMemo(
        () =>
            archived.length === 0 || showArchived
                ? tasks
                : tasks.filter((task) => !archived.includes(task)),
        [archived, showArchived, tasks],
    );

    return (
        <section
            className={cn(
                // The sunken well: a step darker than the page, so the raised
                // cards inside it read without needing borders. It hugs its
                // cards the way Jira's does — a near empty column stays short
                // instead of drawing a tall well down the whole page — and only
                // grows until it hits the viewport, where the list scrolls.
                'flex max-h-full min-h-0 w-72 shrink-0 flex-col rounded bg-muted transition-colors',
                isOver && 'bg-primary/10',
            )}
            aria-label={TASK_STATUS_LABELS[status]}
        >
            <header className="flex shrink-0 items-center gap-2 px-3 pt-3 pb-2">
                <h2 className="text-sm font-medium text-muted-foreground">
                    {TASK_STATUS_LABELS[status]}
                </h2>
                <span className="rounded bg-foreground/10 px-1.5 py-0.5 text-xs font-medium text-muted-foreground tabular-nums">
                    {tasks.length}
                </span>
            </header>

            <SortableContext
                id={status}
                items={shown.map((task) => task.id)}
                strategy={verticalListSortingStrategy}
            >
                <div
                    ref={setNodeRef}
                    className="flex min-h-16 flex-col gap-2 overflow-y-auto px-2 pb-1"
                >
                    {/* An empty column shows nothing but its "Buat" button,
                        exactly as it does on a Jira board. The drop target only
                        announces itself once something is being dragged. */}
                    {shown.length === 0 && isDragging && (
                        <p className="rounded border border-dashed border-primary/40 px-1 py-5 text-center text-xs text-primary">
                            Lepas di sini
                        </p>
                    )}

                    {shown.map((task) => (
                        <TaskCard
                            key={task.id}
                            task={task}
                            draggable={canDrag && task.can_edit}
                            onOpen={() => onOpen(task.id)}
                        />
                    ))}

                    {archived.length > 0 && (
                        <button
                            type="button"
                            onClick={() => setShowArchived((open) => !open)}
                            aria-expanded={showArchived}
                            className="flex items-center justify-center gap-1 rounded px-1.5 py-1.5 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        >
                            <ChevronDown
                                className={cn(
                                    'size-3.5 transition-transform',
                                    showArchived && 'rotate-180',
                                )}
                                aria-hidden="true"
                            />
                            {showArchived
                                ? 'Sembunyikan yang lama'
                                : `${archived.length} selesai lebih dari sebulan`}
                        </button>
                    )}

                    {composing && (
                        <TaskComposer
                            projectId={projectId}
                            status={status}
                            draft={draft}
                            onDraft={setDraft}
                            onClose={() => setComposing(false)}
                        />
                    )}
                </div>
            </SortableContext>

            {canDrag && !composing && (
                <button
                    type="button"
                    onClick={() => setComposing(true)}
                    className="m-2 flex shrink-0 items-center gap-1.5 rounded px-1.5 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
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

/**
 * Jira's inline card composer: a card-shaped box at the foot of the column
 * that takes a title and nothing else. Everything a task can carry is set on
 * the card afterwards, so filing one costs a sentence and an Enter rather
 * than a modal over the board the person is reading.
 */
function TaskComposer({
    projectId,
    status,
    draft,
    onDraft,
    onClose,
}: {
    projectId: number;
    status: TaskStatus;
    draft: string;
    onDraft: (title: string) => void;
    onClose: () => void;
}) {
    const field = useRef<HTMLTextAreaElement>(null);
    const box = useRef<HTMLDivElement>(null);
    const form = useForm({
        title: draft,
        status,
        /** Same default as the full dialog: work starts the day it is filed. */
        start_date: today(),
    });

    // Closing on the press itself rather than on the field's blur: a press on a
    // card is swallowed by dnd-kit's pointer sensor, which calls
    // `preventDefault` and so never moves focus — the first click did nothing
    // and only the second one closed the box. Capture phase, for the same
    // reason: the sensor stops the event before it bubbles.
    useEffect(() => {
        const closeOnOutsidePress = (event: PointerEvent): void => {
            if (!box.current?.contains(event.target as Node)) {
                onClose();
            }
        };

        document.addEventListener('pointerdown', closeOnOutsidePress, true);

        return () =>
            document.removeEventListener(
                'pointerdown',
                closeOnOutsidePress,
                true,
            );
    }, [onClose]);

    const submit = (): void => {
        // A second Enter while the first is still in flight would file the
        // card twice, since the field is not cleared until the server answers.
        if (form.processing) {
            return;
        }

        if (form.data.title.trim() === '') {
            onClose();

            return;
        }

        form.post(store(projectId).url, {
            preserveScroll: true,
            onSuccess: () => {
                // The composer stays open for the next card, the way Jira's
                // does — a column is usually filled in one sitting.
                form.setData('title', '');
                form.clearErrors();
                onDraft('');
                field.current?.focus();
            },
        });
    };

    return (
        // `mb-1` tops the list's own `pb-1` up to the 8px the column keeps on
        // its sides: with the "Buat" button hidden, nothing else holds the
        // composer off the bottom of the well.
        <div
            ref={box}
            className="mb-1 rounded border border-primary/60 bg-card p-2"
        >
            <Textarea
                ref={field}
                autoFocus
                rows={2}
                value={form.data.title}
                placeholder="Apa yang perlu dikerjakan?"
                aria-label={`Judul task baru di kolom ${TASK_STATUS_LABELS[status]}`}
                className="min-h-0 resize-none border-0 bg-transparent p-1 text-sm focus-visible:outline-none"
                onChange={(event) => {
                    form.setData('title', event.target.value);
                    onDraft(event.target.value);
                }}
                onKeyDown={(event) => {
                    // Enter files the card; a title is one line, so the key
                    // that ends it is the key that submits. Escape backs out.
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        submit();
                    }

                    // Escape is the deliberate "never mind", so it throws the
                    // draft away where clicking elsewhere keeps it.
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        onDraft('');
                        onClose();
                    }
                }}
                // Tabbing out leaves the composer too, but a blur from the
                // window itself must not: switching apps mid sentence and
                // coming back to a closed box reads as a lost draft.
                onBlur={(event) => {
                    if (
                        !form.processing &&
                        event.relatedTarget !== null &&
                        !box.current?.contains(event.relatedTarget)
                    ) {
                        onClose();
                    }
                }}
            />

            <InputError message={form.errors.title} className="px-1" />
        </div>
    );
}

ProjectBoard.layout = ({ project }: PageProps) => ({
    breadcrumbs: projectCrumbs(project, show(project.id)),
    wide: true,
    fitViewport: true,
});
