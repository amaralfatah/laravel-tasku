import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { CommentBox } from '@/components/task/comment-box';
import { ProgressBar } from '@/components/task/progress-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { WeekPicker } from '@/components/week-picker';
import { formatWeek } from '@/lib/week';
import { destroy, update } from '@/routes/tasks';
import type { Option } from '@/types/members';
import {
    TASK_PRIORITY_CLASSES
    
    
    
    
} from '@/types/tasks';
import type {TaskAssignee, TaskNode, TaskPriority, TaskStatus} from '@/types/tasks';

const UNASSIGNED = 'none';

/**
 * The one place a task is edited (BRD-6, TML-10). Board, list and timeline all
 * open this sheet so the editing rules live in a single component.
 */
export function TaskDetailSheet({
    task,
    assignees,
    statuses,
    priorities,
    onClose,
}: {
    task: TaskNode | null;
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

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="w-full gap-0 overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle className="flex items-center gap-2">
                        <Badge variant="outline" className="tabular-nums">
                            {task.wbs_number}
                        </Badge>
                        <span className="truncate">{task.title}</span>
                    </SheetTitle>
                    <SheetDescription>
                        {task.children_count > 0
                            ? `${task.done_children_count} dari ${task.children_count} sub task selesai`
                            : 'Tidak punya sub task'}
                    </SheetDescription>
                </SheetHeader>

                <form
                    className="space-y-5 px-4 pb-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(update(task.id).url, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="task-title">Judul</Label>
                        <Input
                            id="task-title"
                            required
                            disabled={readOnly}
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                        />
                        <InputError message={form.errors.title} />
                    </div>

                    <div className="grid gap-2 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="task-status">Status</Label>
                            <Select
                                value={form.data.status}
                                disabled={readOnly}
                                onValueChange={(value) =>
                                    form.setData('status', value as TaskStatus)
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

                        <div className="grid gap-2">
                            <Label htmlFor="task-priority">Prioritas</Label>
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
                            <InputError message={form.errors.priority} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="task-assignee">Penanggung jawab</Label>
                        <Select
                            value={String(form.data.assignee_id ?? UNASSIGNED)}
                            disabled={readOnly}
                            onValueChange={(value) =>
                                form.setData(
                                    'assignee_id',
                                    value === UNASSIGNED ? null : Number(value),
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
                                        value={String(assignee.id)}
                                    >
                                        {assignee.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            Hanya anggota project yang bisa ditugaskan.
                        </p>
                        <InputError message={form.errors.assignee_id} />
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
                                Rata-rata sub task: {task.rollup_progress}%
                                (garis biru). Nilai ini hanya pembanding, tidak
                                menimpa progress manual.
                            </p>
                        )}
                        <InputError message={form.errors.progress} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="task-start">Mulai</Label>
                            <WeekPicker
                                id="task-start"
                                edge="start"
                                disabled={readOnly}
                                value={form.data.start_date}
                                onChange={(value) =>
                                    form.setData('start_date', value)
                                }
                            />
                            <InputError message={form.errors.start_date} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="task-due">Selesai</Label>
                            <WeekPicker
                                id="task-due"
                                edge="end"
                                disabled={readOnly}
                                value={form.data.due_date}
                                onChange={(value) =>
                                    form.setData('due_date', value)
                                }
                            />
                            <InputError message={form.errors.due_date} />
                        </div>
                    </div>

                    <p className="text-xs text-muted-foreground">
                        Jadwal: {formatWeek(form.data.start_date)} —{' '}
                        {formatWeek(form.data.due_date)}
                    </p>

                    <div className="grid gap-2">
                        <Label htmlFor="task-description">Deskripsi</Label>
                        <textarea
                            id="task-description"
                            rows={4}
                            disabled={readOnly}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            className="min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50"
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    <div className="border-t pt-4">
                        <CommentBox
                            key={task.id}
                            taskId={task.id}
                            canComment={!readOnly}
                        />
                    </div>

                    <SheetFooter className="flex-row justify-between px-0">
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
                                    {form.processing
                                        ? 'Menyimpan…'
                                        : 'Simpan'}
                                </Button>
                            )}
                        </div>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
