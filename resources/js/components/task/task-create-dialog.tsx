import { useForm, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
import { store } from '@/routes/tasks';
import type { Option } from '@/types/members';
import { TASK_PRIORITY_CLASSES } from '@/types/tasks';
import type {
    TaskAssignee,
    TaskNode,
    TaskPriority,
    TaskStatus,
} from '@/types/tasks';

const UNASSIGNED = 'none';

/**
 * Creates a task, optionally as a child of another (TSK-1, TSK-9).
 * Only the title is required.
 *
 * Shaped like Jira's create dialog: plain stacked fields, each full width with
 * its label above it, and no Details panel — that framing belongs to a task
 * that already exists, not to a form being filled in.
 */
export function TaskCreateDialog({
    open,
    projectId,
    parent,
    status = 'todo',
    assignees,
    priorities,
    onClose,
}: {
    open: boolean;
    projectId: number;
    parent: TaskNode | null;
    /** Column the task should land in when opened from a board column. */
    status?: TaskStatus;
    assignees: TaskAssignee[];
    priorities: Option[];
    onClose: () => void;
}) {
    const getInitials = useInitials();
    const { auth } = usePage().props;
    const form = useForm({
        title: '',
        parent_task_id: parent?.id ?? null,
        status,
        assignee_id: null as number | null,
        priority: 'medium' as TaskPriority,
        start_date: null as string | null,
        due_date: null as string | null,
    });

    // `useForm` keeps its defaults in state, so `setDefaults` followed by
    // `reset` in the same effect resets the form to the *previous* defaults —
    // the ones from the first render, where there is no parent. That dropped
    // `parent_task_id` (and the column's status) on every open, so a sub task
    // was created as a root task. Seed the data directly instead.
    useEffect(() => {
        if (open) {
            const fresh = {
                title: '',
                parent_task_id: parent?.id ?? null,
                status,
                assignee_id: null as number | null,
                priority: 'medium' as TaskPriority,
                start_date: null as string | null,
                due_date: null as string | null,
            };

            form.setDefaults(fresh);
            form.setData(fresh);
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, parent?.id, status]);

    const assignee =
        assignees.find((member) => member.id === form.data.assignee_id) ?? null;
    /** Jira's "Assign to me", offered only when the user may take the work. */
    const canAssignToSelf =
        form.data.assignee_id !== auth.user?.id &&
        assignees.some((member) => member.id === auth.user?.id);

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {parent ? 'Tambah sub task' : 'Tambah task'}
                    </DialogTitle>
                    <DialogDescription>
                        {parent
                            ? `Sub task dari ${parent.reference} ${parent.title}.`
                            : 'Judul wajib diisi, sisanya bisa dilengkapi nanti.'}
                    </DialogDescription>
                </DialogHeader>

                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(store(projectId).url, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="new-task-title">Judul</Label>
                        <Input
                            id="new-task-title"
                            required
                            autoFocus
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            placeholder="Misal: Rancang skema database"
                        />
                        <InputError message={form.errors.title} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="new-task-assignee">
                            Penanggung jawab
                        </Label>
                        <Select
                            value={String(form.data.assignee_id ?? UNASSIGNED)}
                            onValueChange={(value) =>
                                form.setData(
                                    'assignee_id',
                                    value === UNASSIGNED ? null : Number(value),
                                )
                            }
                        >
                            <SelectTrigger
                                id="new-task-assignee"
                                className="w-full"
                                title="Hanya anggota project yang bisa ditugaskan."
                            >
                                {assignee ? (
                                    <span className="flex min-w-0 items-center gap-2">
                                        <Avatar className="size-5">
                                            <AvatarImage
                                                src={
                                                    assignee.avatar ?? undefined
                                                }
                                                alt=""
                                            />
                                            <AvatarFallback className="text-[10px]">
                                                {getInitials(assignee.name)}
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
                                        value={String(member.id)}
                                    >
                                        {member.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {/* Jira's "Assign to me": the common case in one click,
                            without opening the list. */}
                        {canAssignToSelf && (
                            <button
                                type="button"
                                className="justify-self-start text-sm text-primary hover:underline"
                                onClick={() =>
                                    form.setData('assignee_id', auth.user.id)
                                }
                            >
                                Tugaskan ke saya
                            </button>
                        )}
                        <InputError message={form.errors.assignee_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="new-task-priority">Prioritas</Label>
                        <Select
                            value={form.data.priority}
                            onValueChange={(value) =>
                                form.setData('priority', value as TaskPriority)
                            }
                        >
                            <SelectTrigger
                                id="new-task-priority"
                                className="w-full"
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
                        <InputError message={form.errors.priority} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="new-task-start">Mulai</Label>
                            <Input
                                id="new-task-start"
                                type="date"
                                value={form.data.start_date ?? ''}
                                onChange={(event) =>
                                    form.setData(
                                        'start_date',
                                        event.target.value || null,
                                    )
                                }
                            />
                            <InputError message={form.errors.start_date} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="new-task-due">Selesai</Label>
                            <Input
                                id="new-task-due"
                                type="date"
                                value={form.data.due_date ?? ''}
                                onChange={(event) =>
                                    form.setData(
                                        'due_date',
                                        event.target.value || null,
                                    )
                                }
                            />
                            <InputError message={form.errors.due_date} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Menyimpan…' : 'Tambah task'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
