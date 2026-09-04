import { useForm, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    CircleDashed,
    ContactRound,
    CornerDownRight,
    Flag,
    Layers,
    UserRound,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
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
import { Textarea } from '@/components/ui/textarea';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { store } from '@/routes/tasks';
import type { Option } from '@/types/members';
import type { RequesterOption } from '@/types/requesters';
import { TASK_PRIORITY_CLASSES } from '@/types/tasks';
import type {
    ProjectSummary,
    TaskAssignee,
    TaskNode,
    TaskPriority,
    TaskStatus,
} from '@/types/tasks';

const UNASSIGNED = 'none';

/** No requester chosen. Most internal work has none, so this is the default. */
const NO_REQUESTER = 'none';

/** Jira's "Automatic": the task goes to whoever is filing it. */
const AUTOMATIC = 'auto';

/**
 * Jira's borderless field: the control carries no chrome until it is hovered or
 * focused, so a column of eight fields reads as text rather than as a stack of
 * boxes. The chevron follows the same rule — it appears on hover, on focus and
 * while the list is open, and stays out of the way otherwise.
 */
const GHOST_CONTROL =
    'h-8 w-full rounded-sm border-transparent bg-transparent px-0 text-[15px] md:text-[15px] hover:bg-transparent focus-visible:border-transparent';

const GHOST_CHEVRON =
    '[&>svg]:opacity-0 [&>svg]:transition-opacity hover:[&>svg]:opacity-100 focus-visible:[&>svg]:opacity-100 data-[state=open]:[&>svg]:opacity-100';

/**
 * Creates a task, optionally as a child of another (TSK-1, TSK-9).
 * Only the title is required.
 *
 * Laid out like Jira's create dialog: a header naming the project and the work
 * type, a borderless summary and description at the top, then a tight column of
 * fields where an empty one is just its icon and name — the small label only
 * appears once the field carries a value. The footer's "Buat lagi" keeps the
 * dialog open for the next task.
 */
export function TaskCreateDialog({
    open,
    project,
    parent,
    status = 'todo',
    assignees,
    requesters,
    statuses,
    priorities,
    onClose,
}: {
    open: boolean;
    project: ProjectSummary;
    parent: TaskNode | null;
    /** Column the task should land in when opened from a board column. */
    status?: TaskStatus;
    assignees: TaskAssignee[];
    /** The workspace's requester list, active rows only. */
    requesters: RequesterOption[];
    statuses: Option[];
    priorities: Option[];
    onClose: () => void;
}) {
    const getInitials = useInitials();
    const { auth } = usePage().props;
    const [createAnother, setCreateAnother] = useState(false);
    /**
     * Whoever "Otomatis" resolves to: the person filing the task, as long as
     * they are on the project. Someone who is not stays unassigned, because
     * only project members may carry a task (TSK-4).
     */
    const selfAssigneeId = assignees.some(
        (member) => member.id === auth.user?.id,
    )
        ? (auth.user?.id ?? null)
        : null;
    /** Jira's assignee field starts on "Automatic" rather than on nobody. */
    const [assigneeChoice, setAssigneeChoice] = useState<string>(AUTOMATIC);
    const form = useForm({
        title: '',
        description: '',
        parent_task_id: parent?.id ?? null,
        status,
        assignee_id: selfAssigneeId,
        priority: 'medium' as TaskPriority,
        start_date: null as string | null,
        due_date: null as string | null,
        requester_id: null as number | null,
    });

    const blank = () => ({
        title: '',
        description: '',
        parent_task_id: parent?.id ?? null,
        status,
        assignee_id: selfAssigneeId,
        priority: 'medium' as TaskPriority,
        start_date: null as string | null,
        due_date: null as string | null,
        requester_id: null as number | null,
    });

    // `useForm` keeps its defaults in state, so `setDefaults` followed by
    // `reset` in the same effect resets the form to the *previous* defaults —
    // the ones from the first render, where there is no parent. That dropped
    // `parent_task_id` (and the column's status) on every open, so a sub task
    // was created as a root task. Seed the data directly instead.
    useEffect(() => {
        if (open) {
            const fresh = blank();

            form.setDefaults(fresh);
            form.setData(fresh);
            form.clearErrors();
            // Same reason as the fields above: a reopened dialog starts over.
            // eslint-disable-next-line react-hooks/set-state-in-effect
            setAssigneeChoice(AUTOMATIC);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, parent?.id, status]);

    const assignee =
        assignees.find((member) => member.id === form.data.assignee_id) ?? null;
    /** Jira's "Assign to me", offered only when the user may take the work. */
    const canAssignToSelf =
        form.data.assignee_id !== auth.user?.id && selfAssigneeId !== null;

    /**
     * Point the field at one of its three answers: whoever is filing the task,
     * nobody, or a named member.
     */
    const chooseAssignee = (value: string): void => {
        setAssigneeChoice(value);
        form.setData(
            'assignee_id',
            value === AUTOMATIC
                ? selfAssigneeId
                : value === UNASSIGNED
                  ? null
                  : Number(value),
        );
    };
    const priority = priorities.find(
        (option) => option.value === form.data.priority,
    );
    const requester =
        requesters.find((option) => option.id === form.data.requester_id) ??
        null;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                showCloseButton={false}
                className="gap-0 overflow-hidden overflow-y-hidden p-0 sm:max-w-xl"
            >
                {/* Jira's chrome, minus its work-type chip: this app has one
                    kind of work, so the project is the whole heading. */}
                <div className="flex items-center gap-2 border-b px-3 py-2">
                    <span className="flex min-w-0 items-center gap-1.5">
                        <span className="flex size-5 shrink-0 items-center justify-center rounded-xs bg-primary/15 text-primary">
                            <Layers className="size-3.5" />
                        </span>
                        {/* The key, not the name: it is what the task's own
                            reference will read, e.g. GRO-12. */}
                        <DialogTitle className="truncate text-sm font-medium tracking-wide">
                            {project.key}
                        </DialogTitle>
                    </span>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="ml-auto size-7"
                        >
                            <X className="size-4" />
                            <span className="sr-only">Tutup</span>
                        </Button>
                    </DialogClose>
                </div>

                <DialogDescription className="sr-only">
                    {parent
                        ? `Sub task dari ${parent.reference} ${parent.title}.`
                        : 'Judul wajib diisi, sisanya bisa dilengkapi nanti.'}
                </DialogDescription>

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(store(project.id).url, {
                            preserveScroll: true,
                            onSuccess: () => {
                                if (createAnother) {
                                    // Jira keeps the dialog up and clears it so
                                    // a run of tasks can be typed in one go.
                                    const fresh = blank();

                                    form.setData(fresh);
                                    form.clearErrors();

                                    return;
                                }

                                onClose();
                            },
                        });
                    }}
                >
                    <div className="max-h-[65vh] overflow-y-auto px-5 py-4">
                        <Label htmlFor="new-task-title" className="sr-only">
                            Judul
                        </Label>
                        <Input
                            id="new-task-title"
                            required
                            autoFocus
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            placeholder="Judul task"
                            className={cn(
                                GHOST_CONTROL,
                                'h-auto px-2 py-1 text-2xl font-semibold md:text-2xl',
                            )}
                        />
                        <InputError
                            className="px-2"
                            message={form.errors.title}
                        />

                        <Label
                            htmlFor="new-task-description"
                            className="sr-only"
                        >
                            Deskripsi
                        </Label>
                        <Textarea
                            id="new-task-description"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            placeholder="Tambahkan deskripsi…"
                            className={cn(
                                GHOST_CONTROL,
                                'mt-1 h-auto min-h-16 resize-none px-2 py-1.5',
                            )}
                        />
                        <InputError
                            className="px-2"
                            message={form.errors.description}
                        />

                        <div className="mt-6 space-y-0.5">
                            {parent && (
                                <CreateField label="Task induk" filled>
                                    <ReadOnlyValue icon={<CornerDownRight />}>
                                        <span className="text-muted-foreground">
                                            {parent.reference}
                                        </span>{' '}
                                        {parent.title}
                                    </ReadOnlyValue>
                                </CreateField>
                            )}

                            <CreateField
                                label="Status"
                                htmlFor="new-task-status"
                                error={form.errors.status}
                                filled
                            >
                                <Select
                                    value={form.data.status}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'status',
                                            value as TaskStatus,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="new-task-status"
                                        className={cn(
                                            GHOST_CONTROL,
                                            GHOST_CHEVRON,
                                        )}
                                    >
                                        <span className="flex min-w-0 items-center gap-2">
                                            <CircleDashed className="size-4 text-muted-foreground" />
                                            <SelectValue />
                                        </span>
                                    </SelectTrigger>
                                    <SelectContent>
                                        {statuses.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </CreateField>

                            <CreateField
                                label="Penanggung jawab"
                                htmlFor="new-task-assignee"
                                error={form.errors.assignee_id}
                                filled
                                // Jira's "Assign to me" sits on the label line,
                                // opposite the field it fills.
                                action={
                                    canAssignToSelf && (
                                        <button
                                            type="button"
                                            className="text-xs text-primary hover:underline"
                                            onClick={() =>
                                                chooseAssignee(
                                                    String(selfAssigneeId),
                                                )
                                            }
                                        >
                                            Tugaskan ke saya
                                        </button>
                                    )
                                }
                            >
                                <Select
                                    value={assigneeChoice}
                                    onValueChange={chooseAssignee}
                                >
                                    <SelectTrigger
                                        id="new-task-assignee"
                                        className={cn(
                                            GHOST_CONTROL,
                                            GHOST_CHEVRON,
                                        )}
                                    >
                                        {assignee !== null &&
                                        assigneeChoice !== AUTOMATIC ? (
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
                                            <span className="flex min-w-0 items-center gap-2">
                                                <UserRound className="size-4 text-muted-foreground" />
                                                {assigneeChoice === AUTOMATIC
                                                    ? 'Otomatis'
                                                    : 'Belum ditugaskan'}
                                            </span>
                                        )}
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={AUTOMATIC}>
                                            Otomatis
                                            {selfAssigneeId !== null && (
                                                <span className="text-muted-foreground">
                                                    (tugaskan ke saya)
                                                </span>
                                            )}
                                        </SelectItem>
                                        <SelectItem value={UNASSIGNED}>
                                            Belum ditugaskan
                                        </SelectItem>
                                        {assignees.map((member) => (
                                            <SelectItem
                                                key={member.id}
                                                value={String(member.id)}
                                            >
                                                {member.name}
                                                {member.id ===
                                                    auth.user?.id && (
                                                    <span className="text-muted-foreground">
                                                        (tugaskan ke saya)
                                                    </span>
                                                )}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </CreateField>

                            <CreateField
                                label="Prioritas"
                                htmlFor="new-task-priority"
                                error={form.errors.priority}
                                filled
                            >
                                <Select
                                    value={form.data.priority}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'priority',
                                            value as TaskPriority,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="new-task-priority"
                                        className={cn(
                                            GHOST_CONTROL,
                                            GHOST_CHEVRON,
                                        )}
                                    >
                                        <span className="flex min-w-0 items-center gap-2">
                                            <Flag className="size-4 text-muted-foreground" />
                                            <span
                                                className={`rounded border px-1.5 py-0.5 text-xs ${TASK_PRIORITY_CLASSES[form.data.priority]}`}
                                            >
                                                {priority?.label ??
                                                    form.data.priority}
                                            </span>
                                        </span>
                                    </SelectTrigger>
                                    <SelectContent>
                                        {priorities.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                <span
                                                    className={`rounded border px-1.5 py-0.5 text-xs ${TASK_PRIORITY_CLASSES[option.value as TaskPriority]}`}
                                                >
                                                    {option.label}
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </CreateField>

                            <DateField
                                id="new-task-start"
                                label="Mulai"
                                value={form.data.start_date}
                                error={form.errors.start_date}
                                onChange={(value) =>
                                    form.setData('start_date', value)
                                }
                            />

                            <DateField
                                id="new-task-due"
                                label="Selesai"
                                value={form.data.due_date}
                                error={form.errors.due_date}
                                onChange={(value) =>
                                    form.setData('due_date', value)
                                }
                            />

                            {/* Who asked for the work, as opposed to who is
                                filing it. Picked off the workspace list and
                                never typed, so a name means one person. */}
                            <CreateField
                                label="Pemohon"
                                htmlFor="new-task-requester"
                                error={form.errors.requester_id}
                                filled={form.data.requester_id !== null}
                            >
                                <Select
                                    value={
                                        form.data.requester_id === null
                                            ? NO_REQUESTER
                                            : String(form.data.requester_id)
                                    }
                                    onValueChange={(value) =>
                                        form.setData(
                                            'requester_id',
                                            value === NO_REQUESTER
                                                ? null
                                                : Number(value),
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="new-task-requester"
                                        className={cn(
                                            GHOST_CONTROL,
                                            GHOST_CHEVRON,
                                        )}
                                    >
                                        <span className="flex min-w-0 items-center gap-2">
                                            <ContactRound className="size-4 text-muted-foreground" />
                                            <span className="truncate">
                                                {requester?.name ?? 'Pemohon'}
                                            </span>
                                        </span>
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NO_REQUESTER}>
                                            Tanpa pemohon
                                        </SelectItem>
                                        {requesters.map((option) => (
                                            <SelectItem
                                                key={option.id}
                                                value={String(option.id)}
                                            >
                                                {option.name}
                                                {option.organization && (
                                                    <span className="text-muted-foreground">
                                                        {option.organization}
                                                    </span>
                                                )}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </CreateField>

                            {/* Jira's Reporter: who is filing the task. The
                                backend stamps it from the session, so it is
                                shown, not edited. */}
                            <CreateField label="Pelapor" filled>
                                <ReadOnlyValue
                                    icon={
                                        <Avatar className="size-5">
                                            <AvatarImage
                                                src={
                                                    auth.user?.avatar ??
                                                    undefined
                                                }
                                                alt=""
                                            />
                                            <AvatarFallback className="text-[10px]">
                                                {getInitials(
                                                    auth.user?.name ?? '',
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                    }
                                >
                                    {auth.user?.name}
                                </ReadOnlyValue>
                            </CreateField>
                        </div>
                    </div>

                    <div className="flex items-center gap-3 border-t px-3 py-2.5">
                        <label className="ml-auto flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={createAnother}
                                onCheckedChange={(checked) =>
                                    setCreateAnother(checked === true)
                                }
                            />
                            Buat lagi
                        </label>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Menyimpan…' : 'Buat'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * One row of the create form. An empty field carries no label — its control
 * names itself — and the label appears above the value once there is one.
 */
function CreateField({
    label,
    htmlFor,
    error,
    filled,
    action,
    children,
}: {
    label: string;
    htmlFor?: string;
    error?: string;
    /** Whether the field holds a value, which is what brings its label out. */
    filled: boolean;
    /** Shortcut shown opposite the label, e.g. "Tugaskan ke saya". */
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div>
            {/* Every row occupies the same height whether or not it carries a
                label, so the column keeps one rhythm — and the hover highlight
                covers the whole row, label included, the way Jira's does. */}
            <div className="flex min-h-16 flex-col justify-center gap-1 rounded-sm px-2 py-1.5 transition-colors focus-within:bg-accent/40 hover:bg-accent/40">
                {filled && (
                    <div className="flex items-center justify-between gap-2">
                        <Label
                            htmlFor={htmlFor}
                            className="text-xs leading-none font-normal text-muted-foreground"
                        >
                            {label}
                        </Label>
                        {action}
                    </div>
                )}
                {children}
            </div>
            <InputError className="px-2" message={error} />
        </div>
    );
}

/**
 * A date the way Jira shows one: the field's name until it is set, a date input
 * from the moment it is opened. A native date input cannot carry a placeholder,
 * so an empty one would otherwise sit there as `mm/dd/yyyy`.
 */
function DateField({
    id,
    label,
    value,
    error,
    onChange,
}: {
    id: string;
    label: string;
    value: string | null;
    error?: string;
    onChange: (value: string | null) => void;
}) {
    const [editing, setEditing] = useState(false);
    const filled = value !== null && value !== '';

    if (!filled && !editing) {
        return (
            <CreateField label={label} error={error} filled={false}>
                <button
                    type="button"
                    id={id}
                    className={cn(
                        GHOST_CONTROL,
                        'flex items-center gap-2 text-left',
                    )}
                    onClick={() => setEditing(true)}
                >
                    <CalendarDays className="size-4 text-muted-foreground" />
                    {label}
                </button>
            </CreateField>
        );
    }

    return (
        <CreateField label={label} htmlFor={id} error={error} filled>
            <Input
                id={id}
                type="date"
                autoFocus={editing && !filled}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value || null)}
                onBlur={() => setEditing(false)}
                className={GHOST_CONTROL}
            />
        </CreateField>
    );
}

/** A field the form shows but does not let the user change. */
function ReadOnlyValue({
    icon,
    children,
}: {
    icon: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="flex h-8 min-w-0 items-center gap-2 text-[15px] [&_svg]:size-4 [&_svg]:text-muted-foreground">
            {icon}
            <span className="truncate">{children}</span>
        </div>
    );
}
