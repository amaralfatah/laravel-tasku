import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
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
import { store } from '@/routes/tasks';
import type { Option } from '@/types/members';
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
    const form = useForm({
        title: '',
        parent_task_id: parent?.id ?? null,
        status,
        assignee_id: null as number | null,
        priority: 'medium' as TaskPriority,
        start_date: null as string | null,
        due_date: null as string | null,
    });

    useEffect(() => {
        if (open) {
            form.setDefaults({
                title: '',
                parent_task_id: parent?.id ?? null,
                status,
                assignee_id: null,
                priority: 'medium',
                start_date: null,
                due_date: null,
            });
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, parent?.id, status]);

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {parent ? 'Tambah sub task' : 'Tambah task'}
                    </DialogTitle>
                    <DialogDescription>
                        {parent
                            ? `Sub task dari ${parent.wbs_number} ${parent.title}.`
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

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="new-task-assignee">
                                Penanggung jawab
                            </Label>
                            <Select
                                value={String(
                                    form.data.assignee_id ?? UNASSIGNED,
                                )}
                                onValueChange={(value) =>
                                    form.setData(
                                        'assignee_id',
                                        value === UNASSIGNED
                                            ? null
                                            : Number(value),
                                    )
                                }
                            >
                                <SelectTrigger id="new-task-assignee">
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
                            <InputError message={form.errors.assignee_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="new-task-priority">Prioritas</Label>
                            <Select
                                value={form.data.priority}
                                onValueChange={(value) =>
                                    form.setData(
                                        'priority',
                                        value as TaskPriority,
                                    )
                                }
                            >
                                <SelectTrigger id="new-task-priority">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {priorities.map((priority) => (
                                        <SelectItem
                                            key={priority.value}
                                            value={priority.value}
                                        >
                                            {priority.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
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
