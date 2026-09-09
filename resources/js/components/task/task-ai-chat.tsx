import { router, useHttp } from '@inertiajs/react';
import { ArrowUp, Pencil, Plus, Sparkles, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/members';
import type { TaskAssignee, TaskNode } from '@/types/tasks';

/** One change the assistant proposes. Mirrors App\Services\Ai\TaskPlanApplier. */
export type AiOperation = {
    op: 'create' | 'update' | 'delete';
    id?: number;
    ref?: string;
    parent_ref?: string;
    parent_task_id?: number | null;
    project_id?: number | null;
    title?: string;
    status?: string;
    priority?: string;
    assignee_id?: number | null;
    requester_id?: number | null;
    start_date?: string | null;
    due_date?: string | null;
    description?: string | null;
};

type AiPlan = {
    summary: string;
    operations: AiOperation[];
};

/** A turn in the panel. The plan rides along with the assistant's answer. */
type Turn = {
    role: 'user' | 'assistant';
    text: string;
    plan?: AiPlan;
    /** Set once the person acts on the plan, which retires its buttons. */
    settled?: 'applied' | 'dismissed';
};

const MODEL_STORAGE_KEY = 'tasku.ai-model';

const OPERATION_STYLE: Record<
    AiOperation['op'],
    { label: string; icon: typeof Plus; className: string }
> = {
    create: {
        label: 'Buat',
        icon: Plus,
        className: 'text-emerald-700 dark:text-emerald-400',
    },
    update: {
        label: 'Ubah',
        icon: Pencil,
        className: 'text-sky-700 dark:text-sky-400',
    },
    delete: {
        label: 'Hapus',
        icon: Trash2,
        className: 'text-destructive',
    },
};

const EXAMPLES = [
    'Buat task rilis v2 dengan sub task uji regresi dan catatan rilis',
    'Tandai selesai semua task dokumentasi',
    'Ubah deadline task pertama jadi Jumat depan',
];

/**
 * The model this browser picked last, if the deployment still offers it — a
 * saved choice must never outlive the model it names, or the panel would open
 * on something the server refuses.
 */
function rememberedModel(models: Option[]): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const saved = window.localStorage.getItem(MODEL_STORAGE_KEY);

        return models.some((model) => model.value === saved) ? saved : null;
    } catch {
        return null;
    }
}

/**
 * The assistant, as a chat that lives in the bottom right corner.
 *
 * The same panel serves a project view and a monitoring view; only the two
 * endpoints differ, and with them what the conversation is about — one
 * project, or one person's work across every project.
 *
 * A panel rather than a modal on purpose: the page stays visible and usable
 * behind it, so a person can read a column while dictating the change to it —
 * which is the whole reason to talk to a board instead of dragging it. It
 * opens from its own button, so the motion starts where the click did.
 *
 * The conversation is the panel's; the server keeps none. Each turn carries
 * the transcript back, which is what lets "yang tadi" mean anything, and
 * closing the panel is what ends the thread.
 *
 * Every plan is shown before it is written. Deleting takes a whole subtree
 * with it, which is too much to hand to a guess at what a sentence meant.
 */
export function TaskAiChat({
    planUrl,
    applyUrl,
    tasks,
    assignees,
    statuses,
    projects = [],
    models,
    defaultModel,
}: {
    /** Where a plan is asked for, and where a confirmed one is sent. */
    planUrl: string;
    applyUrl: string;
    /** The tasks on the page, so an operation can be shown by name rather than by id. */
    tasks: TaskNode[];
    assignees: TaskAssignee[];
    statuses: Option[];
    /** Named only where the conversation crosses projects, to say where a new task lands. */
    projects?: { id: number; name: string }[];
    /** Models this deployment can actually run, in the order they are offered. */
    models: Option[];
    defaultModel: string;
}) {
    const [open, setOpen] = useState(false);
    const [turns, setTurns] = useState<Turn[]>([]);
    const [applying, setApplying] = useState<number | null>(null);
    const launcher = useRef<HTMLButtonElement>(null);
    const composer = useRef<HTMLTextAreaElement>(null);
    const thread = useRef<HTMLDivElement>(null);

    const form = useHttp<
        {
            instruction: string;
            model: string;
            history: { role: string; text: string }[];
        },
        AiPlan
    >({
        instruction: '',
        // Whichever model this person used last, as long as the deployment
        // still offers it; otherwise the server's own preference.
        model: rememberedModel(models) ?? defaultModel,
        history: [],
    });

    // The newest turn is the one being read, so the thread stays at the bottom.
    useEffect(() => {
        thread.current?.scrollTo({
            top: thread.current.scrollHeight,
            behavior: 'smooth',
        });
    }, [turns, form.processing]);

    const close = () => {
        setOpen(false);
        // Where the panel came from is where the focus goes back to.
        launcher.current?.focus();
    };

    useEffect(() => {
        if (!open) {
            return;
        }

        composer.current?.focus();

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                launcher.current?.focus();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open]);

    const send = () => {
        const instruction = form.data.instruction.trim();

        if (instruction === '' || form.processing) {
            return;
        }

        const history = turns.map((turn) => ({
            role: turn.role,
            text: turn.text,
        }));

        setTurns((current) => [
            ...current,
            { role: 'user', text: instruction },
        ]);

        // The composer is emptied only once the request has been answered.
        // Clearing it first sent the server a blank `instruction` and came
        // back a 422: the hook posts the data it holds at submit time, not the
        // data this render closed over. `transform` carries the transcript,
        // which lives outside the form.
        form.transform((data) => ({ ...data, instruction, history }));

        /** Answer with the failure; the sentence stays put, ready to be edited. */
        const fail = (message: string) =>
            setTurns((current) => [
                ...current,
                { role: 'assistant', text: message },
            ]);

        form.post(planUrl, {
            // The server explains itself on the field it rejected. Read it from
            // the callback rather than from `form.errors`, which is state this
            // turn has not re-rendered with yet — a question left hanging in a
            // chat reads as a broken assistant.
            onError: (errors) =>
                fail(
                    String(
                        errors.instruction ??
                            errors.model ??
                            Object.values(errors)[0] ??
                            'Gagal menyusun rencana. Coba lagi.',
                    ),
                ),
            onHttpException: (response) => {
                fail(`Server menolak permintaan (${response.status}).`);

                return false;
            },
            onNetworkError: () => {
                fail('Tidak bisa menghubungi server.');

                return false;
            },
        })
            .then((plan) => {
                // A rejected request resolves with nothing rather than
                // throwing; `onError` has already answered in that case.
                if (plan === undefined || plan === null) {
                    return;
                }

                form.setData('instruction', '');

                setTurns((current) => [
                    ...current,
                    {
                        role: 'assistant',
                        text: plan.summary || 'Rencananya seperti ini.',
                        plan,
                    },
                ]);
            })
            .catch(() => {
                // Handled above; this only keeps the rejection from surfacing
                // as an unhandled one.
            });
    };

    const applyPlan = (index: number) => {
        const turn = turns[index];

        if (turn?.plan === undefined) {
            return;
        }

        setApplying(index);

        router.post(
            applyUrl,
            { operations: turn.plan.operations },
            {
                preserveScroll: true,
                onSuccess: () => settle(index, 'applied'),
                onFinish: () => setApplying(null),
            },
        );
    };

    const settle = (index: number, how: 'applied' | 'dismissed') =>
        setTurns((current) =>
            current.map((turn, at) =>
                at === index ? { ...turn, settled: how } : turn,
            ),
        );

    return (
        <>
            <button
                ref={launcher}
                type="button"
                aria-label="Asisten task"
                aria-expanded={open}
                onClick={() => setOpen((was) => !was)}
                className={cn(
                    'fixed right-5 bottom-5 z-40 flex size-14 items-center justify-center rounded-full',
                    'bg-primary text-primary-foreground shadow-lg shadow-black/20',
                    'transition-[transform,box-shadow] duration-200 ease-out',
                    'hover:shadow-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
                    // Feedback on the press itself, not on release.
                    'active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100',
                    open && 'scale-95 opacity-0',
                )}
                // The panel takes over the corner while it is open, so the
                // button steps out of the way rather than sitting under it.
                tabIndex={open ? -1 : 0}
            >
                <Sparkles className="size-6" />
            </button>

            {open && (
                <div
                    role="dialog"
                    aria-label="Asisten task"
                    className={cn(
                        'fixed z-40 flex flex-col overflow-hidden rounded-2xl border bg-popover text-popover-foreground shadow-2xl',
                        'inset-x-3 bottom-3 max-h-[80vh] sm:inset-x-auto sm:right-5 sm:bottom-5 sm:w-104',
                        // Grows out of the corner the button sits in.
                        'origin-bottom-right animate-in duration-200 zoom-in-95 fade-in',
                        'motion-reduce:animate-none',
                    )}
                >
                    <header className="flex items-center gap-2 border-b px-4 py-3">
                        <Sparkles className="size-4 text-primary" />
                        <span className="text-sm font-medium">
                            Asisten task
                        </span>

                        <Select
                            value={form.data.model}
                            onValueChange={(value) => {
                                form.setData('model', value);

                                try {
                                    window.localStorage.setItem(
                                        MODEL_STORAGE_KEY,
                                        value,
                                    );
                                } catch {
                                    // A browser refusing storage still gets to
                                    // choose; it just starts over next time.
                                }
                            }}
                        >
                            <SelectTrigger
                                size="sm"
                                className="ml-auto w-auto border-none bg-transparent text-xs text-muted-foreground shadow-none"
                                aria-label="Model AI"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent align="end">
                                {models.map((model) => (
                                    <SelectItem
                                        key={model.value}
                                        value={model.value}
                                    >
                                        {model.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label="Tutup asisten"
                            onClick={close}
                        >
                            <X className="size-4" />
                        </Button>
                    </header>

                    <div
                        ref={thread}
                        className="flex-1 space-y-3 overflow-y-auto px-4 py-4"
                    >
                        {turns.length === 0 && (
                            <div className="space-y-3">
                                <p className="text-sm text-muted-foreground">
                                    Ceritakan perubahan yang kamu mau.
                                    Rencananya ditampilkan dulu — tidak ada yang
                                    tersimpan sebelum kamu menekan Terapkan.
                                </p>

                                <ul className="space-y-1.5">
                                    {EXAMPLES.map((example) => (
                                        <li key={example}>
                                            <button
                                                type="button"
                                                className="w-full rounded-lg border border-dashed px-3 py-2 text-left text-xs text-muted-foreground transition-colors hover:border-solid hover:bg-accent hover:text-accent-foreground"
                                                onClick={() => {
                                                    form.setData(
                                                        'instruction',
                                                        example,
                                                    );
                                                    composer.current?.focus();
                                                }}
                                            >
                                                {example}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {turns.map((turn, index) => (
                            <TurnBubble
                                key={index}
                                turn={turn}
                                tasks={tasks}
                                projects={projects}
                                assignees={assignees}
                                statuses={statuses}
                                applying={applying === index}
                                disabled={applying !== null}
                                onApply={() => applyPlan(index)}
                                onDismiss={() => settle(index, 'dismissed')}
                            />
                        ))}

                        {form.processing && (
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Spinner className="size-4" />
                                Menyusun rencana…
                            </p>
                        )}
                    </div>

                    <div className="flex items-end gap-2 border-t p-3">
                        <Textarea
                            ref={composer}
                            rows={1}
                            value={form.data.instruction}
                            placeholder="Tulis perintah…"
                            className="max-h-28 min-h-9 resize-none py-2"
                            onChange={(event) =>
                                form.setData('instruction', event.target.value)
                            }
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' && !event.shiftKey) {
                                    event.preventDefault();
                                    send();
                                }
                            }}
                        />

                        <Button
                            size="icon"
                            className="size-9 shrink-0 rounded-full"
                            aria-label="Kirim"
                            disabled={
                                form.processing ||
                                form.data.instruction.trim() === ''
                            }
                            onClick={send}
                        >
                            <ArrowUp className="size-4" />
                        </Button>
                    </div>
                </div>
            )}
        </>
    );
}

function TurnBubble({
    turn,
    tasks,
    assignees,
    statuses,
    projects,
    applying,
    disabled,
    onApply,
    onDismiss,
}: {
    turn: Turn;
    tasks: TaskNode[];
    assignees: TaskAssignee[];
    statuses: Option[];
    projects: { id: number; name: string }[];
    applying: boolean;
    disabled: boolean;
    onApply: () => void;
    onDismiss: () => void;
}) {
    if (turn.role === 'user') {
        return (
            <p className="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-sm bg-primary px-3 py-2 text-sm text-primary-foreground">
                {turn.text}
            </p>
        );
    }

    return (
        <div className="w-fit max-w-[95%] space-y-2 rounded-2xl rounded-bl-sm bg-muted px-3 py-2 text-sm">
            <p>{turn.text}</p>

            {turn.plan !== undefined && turn.plan.operations.length > 0 && (
                <>
                    <ul className="space-y-1.5 border-t pt-2">
                        {turn.plan.operations.map((operation, index) => (
                            <OperationRow
                                key={index}
                                operation={operation}
                                tasks={tasks}
                                projects={projects}
                                assignees={assignees}
                                statuses={statuses}
                            />
                        ))}
                    </ul>

                    {turn.settled === undefined ? (
                        <div className="flex gap-2 pt-1">
                            <Button
                                size="sm"
                                disabled={disabled}
                                onClick={onApply}
                            >
                                {applying ? 'Menerapkan…' : 'Terapkan'}
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={disabled}
                                onClick={onDismiss}
                            >
                                Abaikan
                            </Button>
                        </div>
                    ) : (
                        <p className="pt-1 text-xs text-muted-foreground">
                            {turn.settled === 'applied'
                                ? 'Sudah diterapkan.'
                                : 'Diabaikan.'}
                        </p>
                    )}
                </>
            )}
        </div>
    );
}

/**
 * One proposed change, written the way a person would read it back: what
 * happens, to which task, and the fields that move.
 */
function OperationRow({
    operation,
    tasks,
    projects,
    assignees,
    statuses,
}: {
    operation: AiOperation;
    tasks: TaskNode[];
    projects: { id: number; name: string }[];
    assignees: TaskAssignee[];
    statuses: Option[];
}) {
    const style = OPERATION_STYLE[operation.op];
    const Icon = style.icon;
    const target = tasks.find((task) => task.id === operation.id) ?? null;

    const name =
        operation.op === 'create'
            ? (operation.title ?? '(tanpa judul)')
            : target === null
              ? `#${operation.id}`
              : `${target.reference} ${target.title}`;

    const details = [
        // Only worth saying where the conversation crosses projects; on a
        // project page every task is in the one project already named above.
        operation.op === 'create' && operation.project_id
            ? `di ${projects.find((project) => project.id === operation.project_id)?.name ?? `project #${operation.project_id}`}`
            : null,
        operation.op !== 'create' && operation.title
            ? `judul → ${operation.title}`
            : null,
        operation.status
            ? `status → ${statuses.find((status) => status.value === operation.status)?.label ?? operation.status}`
            : null,
        operation.assignee_id
            ? `PIC → ${assignees.find((member) => member.id === operation.assignee_id)?.name ?? `#${operation.assignee_id}`}`
            : null,
        operation.due_date ? `deadline → ${operation.due_date}` : null,
        operation.parent_ref ? 'sub task dari task baru di atas' : null,
        operation.op === 'delete' ? 'termasuk seluruh sub task' : null,
    ].filter(Boolean);

    return (
        <li className="flex items-start gap-2 text-xs">
            <Icon className={cn('mt-0.5 size-3.5 shrink-0', style.className)} />
            <span>
                <span className={cn('font-medium', style.className)}>
                    {style.label}
                </span>{' '}
                {name}
                {details.length > 0 && (
                    <span className="text-muted-foreground">
                        {' '}
                        — {details.join(', ')}
                    </span>
                )}
            </span>
        </li>
    );
}
