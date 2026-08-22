import { router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';

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
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import { destroy, update } from '@/routes/tasks';
import type { Option } from '@/types/members';
import {
    TASK_PRIORITY_CLASSES,
    TASK_STATUS_LABELS,
    TASK_STATUS_VARIANT,
} from '@/types/tasks';
import type {
    TaskAssignee,
    TaskNode,
    TaskPriority,
    TaskStatus,
} from '@/types/tasks';

const UNASSIGNED = 'none';

/**
 * The one place a task is edited (BRD-6, TML-10). Board, list, timeline and the
 * monitoring pages all open this modal, so the editing rules live in a single
 * component.
 *
 * Laid out the way Jira lays out an issue: a wide centred dialog with the work
 * itself on the left — title, description, sub tasks, activity — and a Details
 * panel pinned to the right. Each column scrolls on its own so the footer
 * actions never drift off screen.
 */
export function TaskDetailModal({
    task,
    ...props
}: TaskDetailModalProps & { task: TaskNode | null }) {
    if (!task) {
        return null;
    }

    // Keyed on the task: opening another one — a sub task, say — mounts a fresh
    // form instead of trying to re-seed the current one. `useForm` keeps its
    // defaults in state, so `setDefaults` followed by `reset` in an effect
    // resets to the *previous* task's values, which left the panel showing the
    // task the user came from.
    return <TaskDetail key={task.id} task={task} {...props} />;
}

type TaskDetailModalProps = {
    /** Direct children, when the calling page has the whole tree loaded. */
    subtasks?: TaskNode[];
    assignees: TaskAssignee[];
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
    assignees,
    statuses,
    priorities,
    onClose,
    onOpenTask,
    onAddSubtask,
}: TaskDetailModalProps & { task: TaskNode }) {
    const getInitials = useInitials();
    const form = useForm({
        title: task.title,
        description: task.description ?? '',
        assignee_id: task.assignee?.id ?? null,
        status: task.status,
        priority: task.priority,

        start_date: task.start_date,
        due_date: task.due_date,
    });

    const readOnly = !task.can_edit;
    /**
     * The picked person, for the avatar in the trigger. A task can carry an
     * assignee who is no longer in the project's member list, so the task's own
     * one is the fallback.
     */
    const assignee =
        assignees.find((member) => member.id === form.data.assignee_id) ??
        (task.assignee?.id === form.data.assignee_id ? task.assignee : null);
    /** Depth is capped, so a task at the bottom level takes no children (TSK-9). */
    const canAddSubtask = Boolean(
        onAddSubtask && !readOnly && task.can_have_children,
    );
    const donePercent =
        task.children_count > 0
            ? Math.round((task.done_children_count / task.children_count) * 100)
            : 0;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="flex max-h-[92vh] w-full flex-col gap-0 overflow-hidden p-0 sm:max-w-6xl"
                // Radix focuses the first field on open, which selects the
                // whole title and invites an accidental overwrite. The modal is
                // for reading first, so nothing is focused until it is clicked.
                onOpenAutoFocus={(event) => event.preventDefault()}
            >
                <DialogHeader className="shrink-0 border-b px-8 py-4 pr-14">
                    <DialogTitle className="flex items-center gap-2 text-base font-normal">
                        <Badge variant="outline" className="tabular-nums">
                            {task.reference}
                        </Badge>
                        <span className="truncate text-muted-foreground">
                            {task.title}
                        </span>
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Detail dan pengeditan task {task.reference}.
                    </DialogDescription>
                </DialogHeader>

                <form
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(update(task.id).url, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <div className="grid min-h-0 flex-1 lg:grid-cols-[minmax(0,1fr)_23rem]">
                        <div className="min-h-0 space-y-7 overflow-y-auto px-8 py-6">
                            <div className="grid gap-2">
                                <Label htmlFor="task-title" className="sr-only">
                                    Judul
                                </Label>
                                <Input
                                    id="task-title"
                                    required
                                    disabled={readOnly}
                                    value={form.data.title}
                                    onChange={(event) =>
                                        form.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                    className="h-auto border-transparent bg-transparent px-2 py-1 text-2xl font-semibold shadow-none hover:border-input focus-visible:border-ring md:text-2xl"
                                />
                                <InputError message={form.errors.title} />
                            </div>

                            <section className="grid gap-2">
                                <Label htmlFor="task-description">
                                    Deskripsi
                                </Label>
                                <textarea
                                    id="task-description"
                                    rows={5}
                                    disabled={readOnly}
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Tambahkan deskripsi…"
                                    className="min-h-28 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50"
                                />
                                <InputError message={form.errors.description} />
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

                                    {subtasks.length > 0 && (
                                        <ul className="divide-y rounded-md border">
                                            {subtasks.map((child) => (
                                                <li
                                                    key={child.id}
                                                    className="flex items-center gap-3 px-3 py-2 text-sm"
                                                >
                                                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                        {child.reference}
                                                    </span>

                                                    {onOpenTask ? (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                onOpenTask(
                                                                    child.id,
                                                                )
                                                            }
                                                            className="min-w-0 flex-1 truncate text-left hover:underline"
                                                        >
                                                            {child.title}
                                                        </button>
                                                    ) : (
                                                        <span className="min-w-0 flex-1 truncate">
                                                            {child.title}
                                                        </span>
                                                    )}

                                                    {/* Jira lets a sub task's
                                                        status be changed from
                                                        the parent, without
                                                        opening it. The parent's
                                                        own unsaved edits are
                                                        untouched: this PATCHes
                                                        the child on its own. */}
                                                    {child.can_edit ? (
                                                        <Select
                                                            value={child.status}
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                router.patch(
                                                                    update(
                                                                        child.id,
                                                                    ).url,
                                                                    {
                                                                        status: value,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                        // Without this the page
                                                                        // remounts and the modal
                                                                        // closes on every change.
                                                                        preserveState: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger
                                                                size="sm"
                                                                className="w-32 shrink-0 border-transparent shadow-none hover:border-input"
                                                                aria-label={`Status ${child.title}`}
                                                            >
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {statuses.map(
                                                                    (
                                                                        status,
                                                                    ) => (
                                                                        <SelectItem
                                                                            key={
                                                                                status.value
                                                                            }
                                                                            value={
                                                                                status.value
                                                                            }
                                                                        >
                                                                            {
                                                                                status.label
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                    ) : (
                                                        <Badge
                                                            variant={
                                                                TASK_STATUS_VARIANT[
                                                                    child.status
                                                                ]
                                                            }
                                                            className="shrink-0 font-normal"
                                                        >
                                                            {
                                                                TASK_STATUS_LABELS[
                                                                    child.status
                                                                ]
                                                            }
                                                        </Badge>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
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

                        <aside className="min-h-0 space-y-4 overflow-y-auto border-t bg-muted/20 px-6 py-6 lg:border-t-0 lg:border-l">
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
                                    value={form.data.status}
                                    disabled={readOnly}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'status',
                                            value as TaskStatus,
                                        )
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
                                <InputError message={form.errors.status} />
                            </div>

                            <div className="rounded-md border bg-background">
                                <h3 className="border-b px-3 py-2 text-sm font-semibold">
                                    Detail
                                </h3>

                                {/* Jira's Details panel: a muted label in a
                                    fixed left column, the value beside it, and
                                    controls that only draw a border on hover so
                                    the panel reads as a list of facts rather
                                    than a form. */}
                                <div className="grid grid-cols-[8rem_minmax(0,1fr)] items-center gap-x-3 gap-y-2 px-4 py-4">
                                    <Label
                                        htmlFor="task-assignee"
                                        className="text-sm font-normal text-muted-foreground"
                                    >
                                        Penanggung jawab
                                    </Label>
                                    <div className="min-w-0">
                                        <Select
                                            value={String(
                                                form.data.assignee_id ??
                                                    UNASSIGNED,
                                            )}
                                            disabled={readOnly}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'assignee_id',
                                                    value === UNASSIGNED
                                                        ? null
                                                        : Number(value),
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="task-assignee"

                                                className="w-full border-transparent shadow-none hover:border-input"
                                                title="Hanya anggota project yang bisa ditugaskan."
                                            >
                                                {assignee ? (
                                                    <span className="flex min-w-0 items-center gap-2">
                                                        <Avatar className="size-5">
                                                            <AvatarImage
                                                                src={
                                                                    assignee.avatar ??
                                                                    undefined
                                                                }
                                                                alt=""
                                                            />
                                                            <AvatarFallback className="text-[10px]">
                                                                {getInitials(
                                                                    assignee.name,
                                                                )}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <span className="truncate">
                                                            {assignee.name}
                                                        </span>
                                                    </span>
                                                ) : (
                                                    <SelectValue placeholder="Belum ditugaskan" />
                                                )}
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
                                            message={form.errors.assignee_id}
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
                                            value={form.data.priority}
                                            disabled={readOnly}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'priority',
                                                    value as TaskPriority,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="task-priority"

                                                className="w-full border-transparent shadow-none hover:border-input"
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
                                        <InputError
                                            message={form.errors.priority}
                                        />
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
                                            value={form.data.start_date ?? ''}
                                            onChange={(event) =>
                                                form.setData(
                                                    'start_date',
                                                    event.target.value || null,
                                                )
                                            }
                                            className="h-9 border-transparent px-2 text-sm shadow-none hover:border-input"
                                        />
                                        <InputError
                                            message={form.errors.start_date}
                                        />
                                    </div>

                                    <Label
                                        htmlFor="task-due"
                                        className="text-sm font-normal text-muted-foreground"
                                    >
                                        Selesai
                                    </Label>
                                    <div className="min-w-0">
                                        <Input
                                            id="task-due"
                                            type="date"
                                            disabled={readOnly}
                                            value={form.data.due_date ?? ''}
                                            onChange={(event) =>
                                                form.setData(
                                                    'due_date',
                                                    event.target.value || null,
                                                )
                                            }
                                            className="h-9 border-transparent px-2 text-sm shadow-none hover:border-input"
                                        />
                                        <InputError
                                            message={form.errors.due_date}
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
                        </aside>
                    </div>

                    <DialogFooter className="shrink-0 flex-row justify-between border-t px-6 py-3 sm:justify-between">
                        {task.can_delete ? (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-destructive hover:text-destructive"
                                onClick={() => {
                                    if (
                                        confirm(
                                            task.children_count > 0
                                                ? `Hapus task "${task.title}" beserta ${task.children_count} sub task-nya?`
                                                : `Hapus task "${task.title}"?`,
                                        )
                                    ) {
                                        router.delete(destroy(task.id).url, {
                                            preserveScroll: true,
                                            onSuccess: onClose,
                                        });
                                    }
                                }}
                            >
                                <Trash2 className="size-4" aria-hidden="true" />
                                Hapus
                            </Button>
                        ) : (
                            <span />
                        )}

                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onClose}
                            >
                                Tutup
                            </Button>
                            {!readOnly && (
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {form.processing ? 'Menyimpan…' : 'Simpan'}
                                </Button>
                            )}
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
