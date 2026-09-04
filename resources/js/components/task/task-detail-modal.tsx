import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    GripVertical,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import InputError from '@/components/input-error';
import { CommentBox } from '@/components/task/comment-box';
import { ProgressBar } from '@/components/task/progress-bar';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { formatDateTime } from '@/lib/week';
import { destroy, move, review, update } from '@/routes/tasks';
import type { Option } from '@/types/members';
import type { RequesterOption } from '@/types/requesters';
import {
    TASK_PRIORITY_BADGE,
    TASK_PRIORITY_CLASSES,
    TASK_PRIORITY_LABELS,
    TASK_STATUS_LABELS,
    TASK_STATUS_VARIANT,
} from '@/types/tasks';
import type { TaskAssignee, TaskNode, TaskPriority } from '@/types/tasks';

const UNASSIGNED = 'none';

/** No requester chosen. Most internal work has none. */
const NO_REQUESTER = 'none';

/**
 * Jira's Details panel and sub task table read as a list of facts, not as a
 * form: a control draws neither a box nor a raised surface of its own until it
 * is hovered. `bg-card` is what the base trigger and input paint, and in dark
 * mode that is a visible step up the surface ladder — so it has to go, not just
 * the border.
 */
const QUIET_CONTROL =
    'border-transparent bg-transparent shadow-none hover:border-input hover:bg-accent';

/**
 * The one place a task is edited (BRD-6, TML-10). Board, list, timeline and the
 * monitoring pages all open this modal, so the editing rules live in a single
 * component.
 *
 * Laid out the way Jira lays out an issue: a wide centred dialog with the work
 * itself on the left — title, description, sub tasks, activity — and a Details
 * panel pinned to the right, each column scrolling on its own. There is no
 * footer and no save button, again as in Jira: every field writes itself.
 */
export function TaskDetailModal({
    task,
    ...props
}: TaskDetailModalProps & { task: TaskNode | null }) {
    if (!task) {
        return null;
    }

    // Keyed on the task: opening another one — a sub task, say — mounts fresh
    // drafts instead of trying to re-seed the current ones. Re-seeding from an
    // effect ran a render late and left the panel showing the task the user
    // came from.
    return <TaskDetail key={task.id} task={task} {...props} />;
}

type TaskDetailModalProps = {
    /** Direct children, when the calling page has the whole tree loaded. */
    subtasks?: TaskNode[];
    /**
     * The task above this one, when the calling page has it. Opening a sub
     * task is a one-way trip otherwise: the modal replaces itself, so the way
     * back up has to be in the modal.
     */
    parent?: TaskNode | null;
    assignees: TaskAssignee[];
    /**
     * The workspace's requester list, active rows only. Defaults to empty so a
     * page that does not offer the field still renders the task's own value.
     */
    requesters?: RequesterOption[];
    statuses: Option[];
    priorities: Option[];
    onClose: () => void;
    /**
     * Swap the modal over to another task. Only pages holding the whole tree
     * can honour this, so a sub task row is only clickable when it is given.
     */
    onOpenTask?: (id: number) => void;
    /** Opens the calling page's create dialog with this task as the parent. */
    onAddSubtask?: () => void;
};

function TaskDetail({
    task,
    subtasks = [],
    parent = null,
    assignees,
    requesters = [],
    statuses,
    priorities,
    onClose,
    onOpenTask,
    onAddSubtask,
}: TaskDetailModalProps & { task: TaskNode }) {
    const getInitials = useInitials();
    const readOnly = !task.can_edit;

    /**
     * Jira has no save button: a field is written the moment it is decided, and
     * the task on the page props is the only copy of the truth. So there is no
     * form here either — a picker saves on change, the two text fields save on
     * blur, and `errors` is whatever the last write answered with.
     */
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const save = (payload: Record<string, string | number | null>) => {
        router.patch(update(task.id).url, payload, {
            preserveScroll: true,
            // Without this the page remounts and the modal closes on every
            // change.
            preserveState: true,
            onStart: () => setSaving(true),
            onSuccess: () => setErrors({}),
            onError: (answered) => setErrors(answered),
            onFinish: () => setSaving(false),
        });
    };

    /**
     * Drafts for the two fields that are typed into. Everything else is a
     * picker and needs none: its value is read straight off the task.
     */
    const [title, setTitle] = useState(task.title);
    const [description, setDescription] = useState(task.description ?? '');

    /** Writes a typed field, if it was actually changed. A title may not be emptied. */
    const commitTitle = () => {
        const next = title.trim();

        if (next === '' || next === task.title) {
            setTitle(task.title);

            return;
        }

        save({ title: next });
    };

    const commitDescription = () => {
        if (description === (task.description ?? '')) {
            return;
        }

        save({ description });
    };

    /**
     * Closing is the last chance to keep what is in the two drafts: a click on
     * the X does not always blur the field under it first.
     */
    const closeAfterSaving = () => {
        if (!readOnly) {
            commitTitle();
            commitDescription();
        }

        onClose();
    };

    /**
     * Swap the modal over to another task, keeping whatever is in the two
     * drafts. Going up to the parent and down into a sub task are the same
     * move as far as the open editors are concerned, so both run through here.
     */
    const openTask = (id: number) => {
        if (!readOnly) {
            commitTitle();
            commitDescription();
        }

        onOpenTask?.(id);
    };

    /**
     * The navigating version of {@see openTask}, withheld entirely on a page
     * that cannot swap task — a sub task row reads that as "not clickable".
     */
    const swapTask = onOpenTask === undefined ? undefined : openTask;

    /**
     * The person on the task, for the avatar in the trigger. A task can carry an
     * assignee who is no longer in the project's member list, so the task's own
     * one is the fallback.
     */
    const assignee =
        assignees.find((member) => member.id === task.assignee?.id) ??
        task.assignee;
    /** Depth is capped, so a task at the bottom level takes no children (TSK-9). */
    const canAddSubtask = Boolean(
        onAddSubtask && !readOnly && task.can_have_children,
    );
    /**
     * Inline rename of a sub task, the way Jira lets a row be edited from the
     * parent. The row is PATCHed on its own, so the parent's unsaved edits stay
     * untouched and the draft lives here rather than on the row.
     */
    const [editingSubtaskId, setEditingSubtaskId] = useState<number | null>(
        null,
    );
    const [subtaskDraft, setSubtaskDraft] = useState('');
    /**
     * The description reads as text until it is clicked, the way Jira's does.
     * The draft lives in `description` either way, so this only decides which of
     * the two the section is showing.
     */
    const [editingDescription, setEditingDescription] = useState(false);
    /** Jira's Details panel folds away; it opens with the modal. */
    const [detailsOpen, setDetailsOpen] = useState(true);
    /**
     * Optimistic sibling order while a drop is in flight. Only the ids are kept
     * here — the rows themselves still come from the page props, so a title or
     * status that changes underneath is not held back by the drag.
     */
    const [draggedOrder, setDraggedOrder] = useState<number[] | null>(null);

    /**
     * The accept/return decision on a task waiting in review. Kept outside
     * `form` since it posts to its own endpoint rather than the task update.
     */
    const [reviewNote, setReviewNote] = useState('');
    const [reviewing, setReviewing] = useState(false);

    const decideReview = (decision: 'approve' | 'return') => {
        setReviewing(true);

        router.post(
            review(task.id).url,
            { decision, note: reviewNote.trim() || undefined },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setReviewNote(''),
                onFinish: () => setReviewing(false),
            },
        );
    };

    const orderedSubtasks = useMemo(() => {
        const byPosition = [...subtasks].sort(
            (a, b) => a.position - b.position,
        );

        if (draggedOrder === null) {
            return byPosition;
        }

        const rows = new Map(byPosition.map((child) => [child.id, child]));
        const picked = draggedOrder
            .map((id) => rows.get(id))
            .filter((child) => child !== undefined);

        // A sub task added or removed while the drop was in flight makes the
        // remembered order stale; the server order is the truth again.
        return picked.length === byPosition.length ? picked : byPosition;
    }, [subtasks, draggedOrder]);

    const sensors = useSensors(
        // A small distance threshold keeps a tap from becoming a drag.
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    /**
     * Sub tasks are siblings under this task, so a drop is a `position` change
     * inside the same parent (BRD-3 applied to the tree). The parent id is sent
     * along because the endpoint reads a missing one as "move to the root".
     */
    const handleSubtaskDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) {
            return;
        }

        const from = orderedSubtasks.findIndex(
            (child) => child.id === active.id,
        );
        const to = orderedSubtasks.findIndex((child) => child.id === over.id);

        if (from === -1 || to === -1) {
            return;
        }

        setDraggedOrder(
            arrayMove(orderedSubtasks, from, to).map((child) => child.id),
        );

        router.post(
            move(Number(active.id)).url,
            { parent_task_id: task.id, position: to },
            {
                preserveScroll: true,
                // Without this the page remounts and the modal closes.
                preserveState: true,
                // Answered or rejected, the props now carry the real order.
                onFinish: () => setDraggedOrder(null),
            },
        );
    };

    const startSubtaskRename = (child: TaskNode) => {
        setEditingSubtaskId(child.id);
        setSubtaskDraft(child.title);
    };

    const saveSubtaskTitle = (child: TaskNode) => {
        const title = subtaskDraft.trim();

        setEditingSubtaskId(null);

        if (title === '' || title === child.title) {
            return;
        }

        router.patch(
            update(child.id).url,
            { title },
            { preserveScroll: true, preserveState: true },
        );
    };

    const donePercent =
        task.children_count > 0
            ? Math.round((task.done_children_count / task.children_count) * 100)
            : 0;

    return (
        <Dialog open onOpenChange={(open) => !open && closeAfterSaving()}>
            <DialogContent
                className="flex h-[92vh] w-[96vw] flex-col gap-0 overflow-hidden overflow-y-hidden p-0 sm:max-w-[84rem]"
                // Radix focuses the first field on open, which selects the
                // whole title and invites an accidental overwrite. The modal is
                // for reading first, so nothing is focused until it is clicked.
                onOpenAutoFocus={(event) => event.preventDefault()}
                // The header carries its own close button, matched to the
                // other actions beside it; the built-in floating one would
                // sit on top of them.
                showCloseButton={false}
            >
                <DialogHeader className="shrink-0 flex-row items-center gap-2 border-b px-4 py-3 sm:px-6">
                    {/* Jira's breadcrumb, and only that: references, separated
                        by a slash, with no titles on them. The title belongs to
                        the heading below — repeating it here made the modal
                        open with the same sentence twice, once truncated.

                        The parent's reference is the way back up. Without it
                        the modal is a trapdoor: it replaces itself on the way
                        down into a sub task, and closing lands you on the board
                        rather than on the task you came from. Drawn only when
                        the calling page can actually swap tasks. */}
                    <DialogTitle className="flex min-w-0 flex-1 items-center gap-1.5 text-sm font-normal tabular-nums">
                        {parent !== null && onOpenTask !== undefined && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => openTask(parent.id)}
                                    title={`Kembali ke ${parent.reference} ${parent.title}`}
                                    className="shrink-0 rounded-sm text-muted-foreground hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {parent.reference}
                                </button>
                                <span
                                    className="shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    /
                                </span>
                            </>
                        )}
                        <span className="truncate text-muted-foreground">
                            {task.reference}
                        </span>
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Detail dan pengeditan task {task.reference}.
                    </DialogDescription>

                    {/* Jira's header actions: a row of equal outlined squares,
                        the destructive ones tucked into the "..." menu rather
                        than sitting under the cursor. */}
                    <div className="flex shrink-0 items-center gap-2">
                        {/* With no save button, this is the only sign a change
                            reached the server. */}
                        <span
                            aria-live="polite"
                            className="text-xs text-muted-foreground"
                        >
                            {saving ? 'Menyimpan…' : ''}
                        </span>

                        {task.can_delete && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        aria-label="Tindakan lain"
                                    >
                                        <MoreHorizontal
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={() => {
                                            if (
                                                !confirm(
                                                    task.children_count > 0
                                                        ? `Hapus task "${task.title}" beserta ${task.children_count} sub task-nya?`
                                                        : `Hapus task "${task.title}"?`,
                                                )
                                            ) {
                                                return;
                                            }

                                            router.delete(
                                                destroy(task.id).url,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: onClose,
                                                },
                                            );
                                        }}
                                    >
                                        <Trash2
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Hapus
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}

                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Tutup"
                            onClick={closeAfterSaving}
                        >
                            <X className="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                </DialogHeader>

                <div className="flex min-h-0 flex-1 flex-col">
                    {/*
                     * Two side-by-side panes that scroll on their own only once
                     * they are side by side. Stacked on a phone they became two
                     * auto-height rows inside a fixed-height dialog, so the
                     * fields below the fold could not be reached at all — there
                     * the whole body is the one scroller instead.
                     */}
                    <div className="flex min-h-0 flex-1 flex-col overflow-y-auto lg:grid lg:grid-cols-[minmax(0,1fr)_26rem] lg:overflow-y-hidden">
                        {/* `min-w-0` plus `overflow-x-hidden`: the work column
                            never scrolls sideways, so a long sub task title
                            wraps and is clamped instead of widening the row. */}
                        <div className="min-w-0 space-y-7 overflow-x-hidden px-4 py-6 sm:px-8 lg:min-h-0 lg:overflow-y-auto">
                            <div className="grid gap-2">
                                <Label htmlFor="task-title" className="sr-only">
                                    Judul
                                </Label>
                                <Input
                                    id="task-title"
                                    required
                                    disabled={readOnly}
                                    value={title}
                                    onChange={(event) =>
                                        setTitle(event.target.value)
                                    }
                                    onBlur={commitTitle}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            event.currentTarget.blur();
                                        }

                                        if (event.key === 'Escape') {
                                            setTitle(task.title);
                                        }
                                    }}
                                    // `-mx-2 px-2`: the field keeps its padding
                                    // so the hover and focus box has room, but
                                    // pays for it out of the column's own
                                    // gutter. Without the negative margin the
                                    // heading sat 8px right of the breadcrumb
                                    // above it and of every heading below it —
                                    // the one line in the column that did not
                                    // start where the others do.
                                    className="-mx-2 h-auto border-transparent bg-transparent px-2 py-1 text-2xl font-semibold shadow-none hover:border-input focus-visible:border-ring md:text-2xl"
                                />
                                <InputError message={errors.title} />
                            </div>

                            <section className="grid gap-2">
                                <h3 className="text-sm font-semibold">
                                    Deskripsi
                                </h3>

                                {/* Jira never parks an open textarea on the
                                    page: the description reads as text until it
                                    is clicked, so the modal opens as something
                                    to read rather than a form to fill. */}
                                {editingDescription ? (
                                    <>
                                        <Label
                                            htmlFor="task-description"
                                            className="sr-only"
                                        >
                                            Deskripsi
                                        </Label>
                                        <textarea
                                            id="task-description"
                                            autoFocus
                                            rows={5}
                                            value={description}
                                            onChange={(event) =>
                                                setDescription(
                                                    event.target.value,
                                                )
                                            }
                                            onBlur={() => {
                                                commitDescription();
                                                setEditingDescription(false);
                                            }}
                                            placeholder="Tambahkan deskripsi…"
                                            // Same gutter as the button it
                                            // replaces, so the text does not
                                            // shift sideways when the
                                            // description is clicked open.
                                            className="-mx-2 min-h-28 w-[calc(100%+1rem)] rounded-md border border-input bg-transparent px-2 py-1.5 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        />
                                    </>
                                ) : readOnly ? (
                                    <p className="-mx-2 px-2 py-1.5 text-sm whitespace-pre-wrap">
                                        {description.trim() || (
                                            <span className="text-muted-foreground">
                                                Tidak ada deskripsi.
                                            </span>
                                        )}
                                    </p>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setEditingDescription(true)
                                        }
                                        className="-mx-2 rounded-md px-2 py-1.5 text-left text-sm whitespace-pre-wrap hover:bg-accent"
                                    >
                                        {description.trim() || (
                                            <span className="text-muted-foreground">
                                                Tambahkan deskripsi…
                                            </span>
                                        )}
                                    </button>
                                )}

                                <InputError message={errors.description} />
                            </section>

                            {(task.children_count > 0 || canAddSubtask) && (
                                <section className="grid gap-3">
                                    <div className="flex items-center gap-3">
                                        <h3 className="text-sm font-semibold">
                                            Sub task
                                        </h3>

                                        {task.children_count > 0 && (
                                            <>
                                                <ProgressBar
                                                    value={donePercent}
                                                    className="flex-1"
                                                />
                                                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                    {task.done_children_count}/
                                                    {task.children_count}{' '}
                                                    selesai
                                                </span>
                                            </>
                                        )}

                                        {canAddSubtask && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="ml-auto size-8 shrink-0"
                                                aria-label="Tambah sub task"
                                                title="Tambah sub task"
                                                onClick={onAddSubtask}
                                            >
                                                <Plus
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            </Button>
                                        )}
                                    </div>

                                    {orderedSubtasks.length > 0 && (
                                        <div className="overflow-hidden rounded-md border">
                                            {/* Jira's sub task table names its
                                                columns, so a row carries the
                                                same facts as a row on the list
                                                view. The two middle columns
                                                fold away when the modal is too
                                                narrow to hold them. */}
                                            <div
                                                className={cn(
                                                    SUBTASK_COLUMNS,
                                                    'border-b bg-muted py-2 text-xs font-medium text-muted-foreground',
                                                )}
                                            >
                                                <span>Sub task</span>
                                                <span className="hidden md:block">
                                                    Prioritas
                                                </span>
                                                <span className="hidden md:block">
                                                    Penanggung jawab
                                                </span>
                                                <span>Status</span>
                                                <span aria-hidden="true" />
                                            </div>

                                            <DndContext
                                                id="task-subtasks"
                                                sensors={sensors}
                                                collisionDetection={
                                                    closestCenter
                                                }
                                                onDragEnd={handleSubtaskDragEnd}
                                            >
                                                <SortableContext
                                                    items={orderedSubtasks.map(
                                                        (child) => child.id,
                                                    )}
                                                    strategy={
                                                        verticalListSortingStrategy
                                                    }
                                                >
                                                    <ul className="divide-y">
                                                        {orderedSubtasks.map(
                                                            (child) => (
                                                                <SubtaskRow
                                                                    key={
                                                                        child.id
                                                                    }
                                                                    child={
                                                                        child
                                                                    }
                                                                    assignees={
                                                                        assignees
                                                                    }
                                                                    statuses={
                                                                        statuses
                                                                    }
                                                                    priorities={
                                                                        priorities
                                                                    }
                                                                    isEditing={
                                                                        editingSubtaskId ===
                                                                        child.id
                                                                    }
                                                                    draft={
                                                                        subtaskDraft
                                                                    }
                                                                    onDraftChange={
                                                                        setSubtaskDraft
                                                                    }
                                                                    onStartRename={() =>
                                                                        startSubtaskRename(
                                                                            child,
                                                                        )
                                                                    }
                                                                    onSaveRename={() =>
                                                                        saveSubtaskTitle(
                                                                            child,
                                                                        )
                                                                    }
                                                                    onCancelRename={() => {
                                                                        // Put the stored title
                                                                        // back first: the blur
                                                                        // that follows then has
                                                                        // nothing to save.
                                                                        setSubtaskDraft(
                                                                            child.title,
                                                                        );
                                                                        setEditingSubtaskId(
                                                                            null,
                                                                        );
                                                                    }}
                                                                    onOpenTask={
                                                                        swapTask
                                                                    }
                                                                />
                                                            ),
                                                        )}
                                                    </ul>
                                                </SortableContext>
                                            </DndContext>
                                        </div>
                                    )}
                                </section>
                            )}

                            <section className="border-t pt-5">
                                <h3 className="mb-3 text-sm font-semibold">
                                    Aktivitas
                                </h3>
                                <CommentBox
                                    key={task.id}
                                    taskId={task.id}
                                    canComment={!readOnly}
                                />
                            </section>
                        </div>

                        <aside className="space-y-4 border-t bg-muted/20 px-4 py-6 sm:px-6 lg:min-h-0 lg:overflow-y-auto lg:border-t-0 lg:border-l">
                            {/* Jira leads the panel with the status as a
                                standalone button, unlabelled — the value names
                                the field well enough. */}
                            <div className="grid gap-2">
                                <Label
                                    htmlFor="task-status"
                                    className="sr-only"
                                >
                                    Status
                                </Label>
                                <Select
                                    value={task.status}
                                    disabled={readOnly}
                                    onValueChange={(value) =>
                                        save({ status: value })
                                    }
                                >
                                    {/* No colour override here: the trigger
                                        already carries a dark-mode background,
                                        and painting `text-secondary-foreground`
                                        over it left dark text on dark. */}
                                    <SelectTrigger
                                        id="task-status"
                                        className="w-auto justify-self-start font-medium"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {statuses.map((status) => (
                                            <SelectItem
                                                key={status.value}
                                                value={status.value}
                                            >
                                                {status.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.status} />
                            </div>

                            {/*
                             * Work handed up sits here until somebody decides
                             * on it. Only the person who may decide sees the
                             * two buttons; everyone else is told who is next,
                             * so a task in review never looks stuck for no
                             * reason.
                             */}
                            {task.status === 'review' && (
                                <div className="space-y-2 rounded-md border bg-background p-3">
                                    <h3 className="text-sm font-semibold">
                                        Menunggu review
                                    </h3>

                                    {task.can_review ? (
                                        <>
                                            <Textarea
                                                value={reviewNote}
                                                rows={2}
                                                placeholder="Catatan untuk pelaksana (opsional)"
                                                onChange={(event) =>
                                                    setReviewNote(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <div className="flex gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    disabled={reviewing}
                                                    onClick={() =>
                                                        decideReview('approve')
                                                    }
                                                >
                                                    Setujui
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={reviewing}
                                                    onClick={() =>
                                                        decideReview('return')
                                                    }
                                                >
                                                    Kembalikan
                                                </Button>
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                Menyetujui menandai task
                                                selesai. Mengembalikan
                                                mengubahnya kembali jadi
                                                dikerjakan, dan catatan Anda
                                                dikirim sebagai komentar.
                                            </p>
                                        </>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            Pekerjaan sudah diserahkan dan
                                            menunggu persetujuan pemilik task di
                                            atasnya.
                                        </p>
                                    )}
                                </div>
                            )}

                            <div className="rounded-md border bg-background">
                                {/* Jira folds the panel from its heading, so a
                                    long sub task list can have the whole modal
                                    to itself. */}
                                <h3>
                                    <button
                                        type="button"
                                        aria-expanded={detailsOpen}
                                        onClick={() =>
                                            setDetailsOpen((open) => !open)
                                        }
                                        className={cn(
                                            'flex w-full items-center gap-1.5 px-3 py-2 text-sm font-semibold',
                                            detailsOpen && 'border-b',
                                        )}
                                    >
                                        {detailsOpen ? (
                                            <ChevronDown
                                                className="size-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <ChevronRight
                                                className="size-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        )}
                                        Detail
                                    </button>
                                </h3>

                                {/* Jira's Details panel: a muted label in a
                                    fixed left column, the value beside it, and
                                    controls that only draw a border on hover so
                                    the panel reads as a list of facts rather
                                    than a form. */}
                                <div
                                    className={cn(
                                        'grid grid-cols-[8rem_minmax(0,1fr)] items-center gap-x-3 gap-y-2 px-4 py-4',
                                        !detailsOpen && 'hidden',
                                    )}
                                >
                                    <Label
                                        htmlFor="task-assignee"
                                        className="text-sm font-normal text-muted-foreground"
                                    >
                                        Penanggung jawab
                                    </Label>
                                    <div className="min-w-0">
                                        <Select
                                            value={String(
                                                task.assignee?.id ?? UNASSIGNED,
                                            )}
                                            disabled={readOnly}
                                            onValueChange={(value) =>
                                                save({
                                                    assignee_id:
                                                        value === UNASSIGNED
                                                            ? null
                                                            : Number(value),
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                id="task-assignee"
                                                className={cn(
                                                    'w-full',
                                                    QUIET_CONTROL,
                                                )}
                                                title="Hanya anggota project yang bisa ditugaskan."
                                            >
                                                <AssigneeLabel
                                                    assignee={assignee}
                                                    getInitials={getInitials}
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={UNASSIGNED}>
                                                    Belum ditugaskan
                                                </SelectItem>
                                                {assignees.map((member) => (
                                                    <SelectItem
                                                        key={member.id}
                                                        value={String(
                                                            member.id,
                                                        )}
                                                    >
                                                        {member.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.assignee_id}
                                        />
                                    </div>

                                    <Label
                                        htmlFor="task-priority"
                                        className="text-sm font-normal text-muted-foreground"
                                    >
                                        Prioritas
                                    </Label>
                                    <div className="min-w-0">
                                        <Select
                                            value={task.priority}
                                            disabled={readOnly}
                                            onValueChange={(value) =>
                                                save({ priority: value })
                                            }
                                        >
                                            <SelectTrigger
                                                id="task-priority"
                                                className={cn(
                                                    'w-full',
                                                    QUIET_CONTROL,
                                                )}
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {priorities.map((priority) => (
                                                    <SelectItem
                                                        key={priority.value}
                                                        value={priority.value}
                                                    >
                                                        <span
                                                            className={`rounded border px-1.5 py-0.5 text-xs ${TASK_PRIORITY_CLASSES[priority.value as TaskPriority]}`}
                                                        >
                                                            {priority.label}
                                                        </span>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.priority} />
                                    </div>

                                    <Label
                                        htmlFor="task-start"
                                        className="text-sm font-normal text-muted-foreground"
                                    >
                                        Mulai
                                    </Label>
                                    <div className="min-w-0">
                                        <Input
                                            id="task-start"
                                            type="date"
                                            disabled={readOnly}
                                            value={task.start_date ?? ''}
                                            onChange={(event) =>
                                                save({
                                                    start_date:
                                                        event.target.value ||
                                                        null,
                                                })
                                            }
                                            className={cn(
                                                'h-9 px-2 text-sm',
                                                QUIET_CONTROL,
                                            )}
                                        />
                                        <InputError
                                            message={errors.start_date}
                                        />
                                    </div>

                                    <Label
                                        htmlFor="task-due"
                                        className="text-sm font-normal text-muted-foreground"
                                    >
                                        Selesai
                                    </Label>
                                    <div className="min-w-0">
                                        {/* Jira paints a passed due date red in
                                            this panel, and it is the one fact
                                            here somebody must not miss. */}
                                        <Input
                                            id="task-due"
                                            type="date"
                                            disabled={readOnly}
                                            value={task.due_date ?? ''}
                                            title={
                                                task.is_overdue
                                                    ? 'Tanggal selesai sudah terlewat'
                                                    : undefined
                                            }
                                            onChange={(event) =>
                                                save({
                                                    due_date:
                                                        event.target.value ||
                                                        null,
                                                })
                                            }
                                            className={cn(
                                                'h-9 px-2 text-sm',
                                                QUIET_CONTROL,
                                                task.is_overdue &&
                                                    'border-destructive/50 font-medium text-destructive',
                                            )}
                                        />
                                        {task.is_overdue && (
                                            <span className="sr-only">
                                                Tanggal selesai sudah terlewat.
                                            </span>
                                        )}
                                        <InputError message={errors.due_date} />
                                    </div>

                                    {/* Jira keeps Reporter at the foot of the
                                        Details panel; the requester is the same
                                        kind of fact — who the work is for rather
                                        than who is doing it — so it sits in that
                                        slot rather than beside the assignee. */}
                                    <Label
                                        htmlFor="task-requester"
                                        className="text-sm font-normal text-muted-foreground"
                                    >
                                        Pemohon
                                    </Label>
                                    <div className="min-w-0">
                                        <Select
                                            value={String(
                                                task.requester?.id ??
                                                    NO_REQUESTER,
                                            )}
                                            disabled={readOnly}
                                            onValueChange={(value) =>
                                                save({
                                                    requester_id:
                                                        value === NO_REQUESTER
                                                            ? null
                                                            : Number(value),
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                id="task-requester"
                                                className={cn(
                                                    'w-full',
                                                    QUIET_CONTROL,
                                                )}
                                                title="Daftar pemohon dikelola di halaman Pemohon."
                                            >
                                                <span className="truncate">
                                                    {task.requester?.name ??
                                                        'Tanpa pemohon'}
                                                </span>
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    value={NO_REQUESTER}
                                                >
                                                    Tanpa pemohon
                                                </SelectItem>
                                                {/* A retired requester is not
                                                    in the list, so the task
                                                    that still names one keeps
                                                    its own row here. */}
                                                {task.requester &&
                                                    !requesters.some(
                                                        (option) =>
                                                            option.id ===
                                                            task.requester?.id,
                                                    ) && (
                                                        <SelectItem
                                                            value={String(
                                                                task.requester
                                                                    .id,
                                                            )}
                                                        >
                                                            {
                                                                task.requester
                                                                    .name
                                                            }
                                                            <span className="text-muted-foreground">
                                                                (nonaktif)
                                                            </span>
                                                        </SelectItem>
                                                    )}
                                                {requesters.map((option) => (
                                                    <SelectItem
                                                        key={option.id}
                                                        value={String(
                                                            option.id,
                                                        )}
                                                    >
                                                        {option.name}
                                                        {option.organization && (
                                                            <span className="text-muted-foreground">
                                                                {
                                                                    option.organization
                                                                }
                                                            </span>
                                                        )}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.requester_id}
                                        />
                                    </div>

                                    {/* Read-only: progress is derived from the
                                        sub tasks that are done, or from the
                                        task's own status when it is a leaf. */}
                                    <span className="self-start pt-1.5 text-sm text-muted-foreground">
                                        Progress
                                    </span>
                                    <div className="min-w-0 space-y-1.5 py-1">
                                        <ProgressBar
                                            value={task.progress}
                                            showLabel
                                        />
                                        {task.children_count > 0 && (
                                            <p className="text-xs text-muted-foreground">
                                                {task.done_children_count} dari{' '}
                                                {task.children_count} sub task
                                                selesai.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Jira closes the panel with these two lines, and
                                nothing else: there is no save button under
                                them, so the timestamps are what tells somebody
                                the task was written. */}
                            <dl className="px-1 text-xs text-muted-foreground">
                                <div className="flex gap-1">
                                    <dt>Dibuat</dt>
                                    <dd>{formatDateTime(task.created_at)}</dd>
                                </div>
                                <div className="flex gap-1">
                                    <dt>Diperbarui</dt>
                                    <dd>{formatDateTime(task.updated_at)}</dd>
                                </div>
                            </dl>
                        </aside>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The sub task table's column track, shared by the header and every row so the
 * two line up. Priority and assignee are dropped below `md`, where the modal is
 * one column and there is no room for five fields beside a title.
 */
/**
 * Jira's sub task table runs on one gap the whole way across — grip, key,
 * summary and the pickers are all 8px apart. Widening it to 12px on the larger
 * breakpoints was what made the row read as a set of loose columns rather than
 * as a line of text with controls after it, so the gap is uniform and only the
 * column widths change with the viewport.
 */
const SUBTASK_COLUMNS =
    'grid grid-cols-[minmax(0,1fr)_6.5rem_2rem] items-center gap-2 pr-3 pl-5 sm:grid-cols-[minmax(0,1fr)_9rem_2rem] md:grid-cols-[minmax(0,1fr)_7.5rem_9rem_9rem_2rem]';

/**
 * A sub task row edits itself. Every picker on the row PATCHes that row alone,
 * so a row and its parent never overwrite each other, and the modal keeps its
 * state while the page props refresh.
 */
const patchSubtask = (
    id: number,
    payload: Record<string, string | number | null>,
) => {
    router.patch(update(id).url, payload, {
        preserveScroll: true,
        // Without this the page remounts and the modal closes on every change.
        preserveState: true,
    });
};

/**
 * One row of the sub task table, dragged by its grip the way Jira sorts a sub
 * task list.
 *
 * The row already carries a rename input, three pickers and a menu, so unlike a
 * board card it cannot be the drag handle itself: only the grip takes the
 * listeners, and it stays out of sight until the row is hovered or the grip
 * takes focus.
 */
function SubtaskRow({
    child,
    assignees,
    statuses,
    priorities,
    isEditing,
    draft,
    onDraftChange,
    onStartRename,
    onSaveRename,
    onCancelRename,
    onOpenTask,
}: {
    child: TaskNode;
    assignees: TaskAssignee[];
    statuses: Option[];
    priorities: Option[];
    isEditing: boolean;
    draft: string;
    onDraftChange: (value: string) => void;
    onStartRename: () => void;
    onSaveRename: () => void;
    onCancelRename: () => void;
    onOpenTask?: (id: number) => void;
}) {
    const getInitials = useInitials();
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: child.id, disabled: !child.can_edit });

    return (
        <li
            ref={setNodeRef}
            style={{
                transform: CSS.Translate.toString(transform),
                transition,
            }}
            className={cn(
                SUBTASK_COLUMNS,
                'group relative py-2 text-sm',
                // Lifted over its neighbours so the moving row stays readable
                // while the rest of the list slides under it.
                isDragging && 'z-10 bg-accent',
            )}
        >
            {/* Out of the grid and into the row's left padding. A reserved grip
                column pushed the key 40px in from the panel's edge, where Jira
                starts it at 12px — and it only ever held something while the
                row was hovered. Positioned rather than laid out, it costs the
                key nothing and still nudges down to the summary's first line. */}
            {child.can_edit && (
                <button
                    type="button"
                    ref={setActivatorNodeRef}
                    {...attributes}
                    {...listeners}
                    aria-label={`Urutkan ${child.reference}`}
                    title="Geser untuk mengurutkan"
                    className="absolute top-2.5 left-0.5 cursor-grab touch-none text-muted-foreground opacity-0 group-hover:opacity-100 focus-visible:opacity-100 active:cursor-grabbing"
                >
                    <GripVertical className="size-4" aria-hidden="true" />
                </button>
            )}

            {/* The key and the summary are one cluster in Jira, set closer to
                each other than the row's own columns are.

                Aligned to the top, not the centre: a summary that wraps to two
                lines used to push the key down between them, so the key sat in
                a different place on every row depending on how long its title
                was. Each piece then carries the nudge that puts it on the first
                line — the key's own line box is 16px against the summary's
                20px, and the pencil is a 32px control. */}
            <div className="flex min-w-0 items-start gap-1.5">
                {isEditing ? (
                    <Input
                        autoFocus
                        value={draft}
                        aria-label={`Judul ${child.reference}`}
                        className="h-8 min-w-0 flex-1"
                        onChange={(event) => onDraftChange(event.target.value)}
                        onBlur={onSaveRename}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                onSaveRename();
                            }

                            if (event.key === 'Escape') {
                                event.preventDefault();
                                onCancelRename();
                            }
                        }}
                    />
                ) : (
                    <>
                        {/* The reference and the pencil both go on a phone:
                            together they left the title no width at all, and
                            the pencil only ever appears on hover anyway, which
                            a touch screen has none of — the title opens the sub
                            task, where it can be renamed. */}
                        <span className="hidden shrink-0 pt-0.5 text-xs text-muted-foreground tabular-nums sm:inline">
                            {child.reference}
                        </span>

                        {onOpenTask ? (
                            <button
                                type="button"
                                onClick={() => onOpenTask(child.id)}
                                className="line-clamp-2 min-w-0 flex-1 text-left break-words hover:underline"
                            >
                                {child.title}
                            </button>
                        ) : (
                            <span className="line-clamp-2 min-w-0 flex-1 break-words">
                                {child.title}
                            </span>
                        )}

                        {/* Jira's pencil: out of the way until the row is
                            hovered or the button takes focus. */}
                        {child.can_edit && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="-mt-1.5 hidden size-8 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 focus-visible:opacity-100 sm:inline-flex"
                                aria-label={`Ubah judul ${child.reference}`}
                                title="Ubah judul"
                                onClick={onStartRename}
                            >
                                <Pencil className="size-4" aria-hidden="true" />
                            </Button>
                        )}
                    </>
                )}
            </div>

            <div className="hidden min-w-0 md:block">
                {child.can_edit ? (
                    <Select
                        value={child.priority}
                        onValueChange={(value) =>
                            patchSubtask(child.id, { priority: value })
                        }
                    >
                        <SelectTrigger
                            size="sm"
                            className={cn('w-full', QUIET_CONTROL)}
                            aria-label={`Prioritas ${child.title}`}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {priorities.map((priority) => (
                                <SelectItem
                                    key={priority.value}
                                    value={priority.value}
                                >
                                    <span
                                        className={`rounded border px-1.5 py-0.5 text-xs ${TASK_PRIORITY_CLASSES[priority.value as TaskPriority]}`}
                                    >
                                        {priority.label}
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ) : (
                    <Badge
                        variant="outline"
                        className={cn(
                            'font-normal',
                            TASK_PRIORITY_BADGE[child.priority],
                        )}
                    >
                        {TASK_PRIORITY_LABELS[child.priority]}
                    </Badge>
                )}
            </div>

            <div className="hidden min-w-0 md:block">
                {child.can_edit ? (
                    <Select
                        value={String(child.assignee?.id ?? UNASSIGNED)}
                        onValueChange={(value) =>
                            patchSubtask(child.id, {
                                assignee_id:
                                    value === UNASSIGNED ? null : Number(value),
                            })
                        }
                    >
                        <SelectTrigger
                            size="sm"
                            className={cn('w-full', QUIET_CONTROL)}
                            aria-label={`Penanggung jawab ${child.title}`}
                        >
                            <AssigneeLabel
                                assignee={child.assignee}
                                getInitials={getInitials}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={UNASSIGNED}>
                                Belum ditugaskan
                            </SelectItem>
                            {assignees.map((member) => (
                                <SelectItem
                                    key={member.id}
                                    value={String(member.id)}
                                >
                                    {member.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ) : (
                    <div className="px-2">
                        <AssigneeLabel
                            assignee={child.assignee}
                            getInitials={getInitials}
                        />
                    </div>
                )}
            </div>

            {/* Jira lets a sub task's status be changed from the parent,
                without opening it. */}
            {child.can_edit ? (
                <Select
                    value={child.status}
                    onValueChange={(value) =>
                        patchSubtask(child.id, { status: value })
                    }
                >
                    <SelectTrigger
                        size="sm"
                        className={cn('w-full', QUIET_CONTROL)}
                        aria-label={`Status ${child.title}`}
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
            ) : (
                <Badge
                    variant={TASK_STATUS_VARIANT[child.status]}
                    className="justify-self-start font-normal"
                >
                    {TASK_STATUS_LABELS[child.status]}
                </Badge>
            )}

            {/* Same "..." menu Jira gives a sub task row. Deleting never closes
                the parent: the row goes on its own request, and the modal keeps
                its state while the page props refresh. */}
            {child.can_delete ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0 text-muted-foreground"
                            aria-label={`Tindakan ${child.title}`}
                        >
                            <MoreHorizontal
                                className="size-4"
                                aria-hidden="true"
                            />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => {
                                if (
                                    !confirm(
                                        child.children_count > 0
                                            ? `Hapus sub task "${child.title}" beserta ${child.children_count} sub task-nya?`
                                            : `Hapus sub task "${child.title}"?`,
                                    )
                                ) {
                                    return;
                                }

                                router.delete(destroy(child.id).url, {
                                    preserveScroll: true,
                                    preserveState: true,
                                });
                            }}
                        >
                            <Trash2 className="size-4" aria-hidden="true" />
                            Hapus
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : (
                <span aria-hidden="true" />
            )}
        </li>
    );
}

/** Avatar and name, shared by the row's picker trigger and its read-only cell. */
function AssigneeLabel({
    assignee,
    getInitials,
}: {
    assignee: TaskAssignee | null;
    getInitials: (name: string) => string;
}) {
    return (
        <span className="flex min-w-0 items-center gap-2">
            <Avatar className="size-5">
                <AvatarImage src={assignee?.avatar ?? undefined} alt="" />
                <AvatarFallback className="text-[10px]">
                    {assignee ? getInitials(assignee.name) : '—'}
                </AvatarFallback>
            </Avatar>
            <span
                className={cn(
                    'truncate',
                    assignee === null && 'text-muted-foreground',
                )}
            >
                {assignee?.name ?? 'Belum ditugaskan'}
            </span>
        </span>
    );
}
