import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { CommentBox } from '@/components/task/comment-box';
import { ProgressBar } from '@/components/task/progress-bar';
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
import { WeekPicker } from '@/components/week-picker';
import { formatWeek } from '@/lib/week';
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
    subtasks = [],
    assignees,
    statuses,
    priorities,
    onClose,
}: {
    task: TaskNode | null;
    /** Direct children, when the calling page has the whole tree loaded. */
    subtasks?: TaskNode[];
    assignees: TaskAssignee[];
    statuses: Option[];
    priorities: Option[];
    onClose: () => void;
}) {
    const form = useForm({
        title: task?.title ?? '',
        description: task?.description ?? '',
        assignee_id: task?.assignee?.id ?? null,
        status: (task?.status ?? 'todo') as TaskStatus,
        priority: (task?.priority ?? 'medium') as TaskPriority,
        progress: task?.progress ?? 0,
        start_date: task?.start_date ?? null,
        due_date: task?.due_date ?? null,
    });

    useEffect(() => {
        if (!task) {
            return;
        }

        form.setDefaults({
            title: task.title,
            description: task.description ?? '',
            assignee_id: task.assignee?.id ?? null,
            status: task.status,
            priority: task.priority,
            progress: task.progress,
            start_date: task.start_date,
            due_date: task.due_date,
        });
        form.reset();
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [task?.id]);

    if (!task) {
        return null;
    }

    const readOnly = !task.can_edit;
    const donePercent =
        task.children_count > 0
            ? Math.round((task.done_children_count / task.children_count) * 100)
            : 0;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="flex max-h-[88vh] w-full flex-col gap-0 overflow-hidden p-0 sm:max-w-5xl">
                <DialogHeader className="shrink-0 border-b px-6 py-3 pr-14">
                    <DialogTitle className="flex items-center gap-2 text-sm font-normal">
                        <Badge variant="outline" className="tabular-nums">
                            {task.wbs_number}
                        </Badge>
                        <span className="truncate text-muted-foreground">
                            {task.title}
                        </span>
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Detail dan pengeditan task {task.wbs_number}.
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
                    <div className="grid min-h-0 flex-1 lg:grid-cols-[minmax(0,1fr)_20rem]">
                        <div className="min-h-0 space-y-6 overflow-y-auto px-6 py-5">
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
                                    className="h-auto border-transparent bg-transparent px-2 py-1 text-xl font-semibold shadow-none hover:border-input focus-visible:border-ring md:text-xl"
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

                            {task.children_count > 0 && (
                                <section className="grid gap-3">
                                    <div className="flex items-center gap-3">
                                        <h3 className="text-sm font-semibold">
                                            Sub task
                                        </h3>
                                        <ProgressBar
                                            value={donePercent}
                                            className="flex-1"
                                        />
                                        <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                            {task.done_children_count}/
                                            {task.children_count} selesai
                                        </span>
                                    </div>

                                    {subtasks.length > 0 && (
                                        <ul className="divide-y rounded-md border">
                                            {subtasks.map((child) => (
                                                <li
                                                    key={child.id}
                                                    className="flex items-center gap-3 px-3 py-2 text-sm"
                                                >
                                                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                        {child.wbs_number}
                                                    </span>
                                                    <span className="min-w-0 flex-1 truncate">
                                                        {child.title}
                                                    </span>
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

                        <aside className="min-h-0 space-y-4 overflow-y-auto border-t bg-muted/20 px-5 py-5 lg:border-t-0 lg:border-l">
                            <div className="grid gap-2">
                                <Label htmlFor="task-status">Status</Label>
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
                                    <SelectTrigger id="task-status">
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

                                <div className="space-y-4 px-3 py-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="task-assignee">
                                            Penanggung jawab
                                        </Label>
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
                                            <SelectTrigger id="task-assignee">
                                                <SelectValue placeholder="Belum ditugaskan" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={UNASSIGNED}>
                                                    Belum ditugaskan
                                                </SelectItem>
                                                {assignees.map((assignee) => (
                                                    <SelectItem
                                                        key={assignee.id}
                                                        value={String(
                                                            assignee.id,
                                                        )}
                                                    >
                                                        {assignee.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="text-xs text-muted-foreground">
                                            Hanya anggota project yang bisa
                                            ditugaskan.
                                        </p>
                                        <InputError
                                            message={form.errors.assignee_id}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="task-priority">
                                            Prioritas
                                        </Label>
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
                                            <SelectTrigger id="task-priority">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {priorities.map((priority) => (
                                                    <SelectItem
                                                        key={priority.value}
                                                        value={priority.value}
                                                    >
                                                        <span
                                                            className={`rounded px-1.5 py-0.5 text-xs ${TASK_PRIORITY_CLASSES[priority.value as TaskPriority]}`}
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

                                    <div className="grid gap-2">
                                        <Label htmlFor="task-progress">
                                            Progress: {form.data.progress}%
                                        </Label>
                                        <input
                                            id="task-progress"
                                            type="range"
                                            min={0}
                                            max={100}
                                            step={5}
                                            disabled={readOnly}
                                            value={form.data.progress}
                                            onChange={(event) =>
                                                form.setData(
                                                    'progress',
                                                    Number(event.target.value),
                                                )
                                            }
                                            className="h-11 w-full accent-foreground"
                                        />
                                        <ProgressBar
                                            value={form.data.progress}
                                            rollup={task.rollup_progress}
                                        />
                                        {task.rollup_progress !== null && (
                                            <p className="text-xs text-muted-foreground">
                                                Rata-rata sub task:{' '}
                                                {task.rollup_progress}% (garis
                                                biru). Nilai ini hanya
                                                pembanding, tidak menimpa
                                                progress manual.
                                            </p>
                                        )}
                                        <InputError
                                            message={form.errors.progress}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="task-start">
                                            Mulai
                                        </Label>
                                        <WeekPicker
                                            id="task-start"
                                            edge="start"
                                            disabled={readOnly}
                                            value={form.data.start_date}
                                            onChange={(value) =>
                                                form.setData(
                                                    'start_date',
                                                    value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.start_date}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="task-due">
                                            Selesai
                                        </Label>
                                        <WeekPicker
                                            id="task-due"
                                            edge="end"
                                            disabled={readOnly}
                                            value={form.data.due_date}
                                            onChange={(value) =>
                                                form.setData('due_date', value)
                                            }
                                        />
                                        <InputError
                                            message={form.errors.due_date}
                                        />
                                    </div>

                                    <p className="text-xs text-muted-foreground">
                                        Jadwal:{' '}
                                        {formatWeek(form.data.start_date)} —{' '}
                                        {formatWeek(form.data.due_date)}
                                    </p>
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
