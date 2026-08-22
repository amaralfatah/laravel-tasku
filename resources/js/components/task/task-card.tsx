import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ChevronRight, ListTree, SquareCheckBig, User } from 'lucide-react';
import { useRef } from 'react';
import type {
    MouseEvent as ReactMouseEvent,
    PointerEvent as ReactPointerEvent,
} from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { formatDay } from '@/lib/week';
import { TASK_PRIORITY_BADGE, TASK_PRIORITY_LABELS } from '@/types/tasks';
import type { TaskNode } from '@/types/tasks';

/** A pointer that travelled further than this was dragging, not clicking. */
const CLICK_SLOP = 6;

/**
 * Board card for a root task (BRD-5).
 *
 * The title leads and everything else drops into one muted meta row, so a
 * column reads as a list of task names rather than a stack of dashboards. The
 * whole card is the drag handle; a click only counts as a click when the
 * pointer barely moved, which keeps "open the detail sheet" and "drag" apart
 * without needing a separate grip.
 */
export function TaskCard({
    task,
    draggable,
    overlay = false,
    onOpen,
}: {
    task: TaskNode;
    draggable: boolean;
    /** Rendered inside the DragOverlay rather than in a column. */
    overlay?: boolean;
    onOpen: () => void;
}) {
    const getInitials = useInitials();
    const pressedAt = useRef<{ x: number; y: number } | null>(null);
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: task.id, disabled: !draggable });

    const handlePointerDown = (event: ReactPointerEvent<HTMLDivElement>) => {
        pressedAt.current = { x: event.clientX, y: event.clientY };
    };

    const handleClick = (event: ReactMouseEvent<HTMLDivElement>) => {
        const start = pressedAt.current;

        pressedAt.current = null;

        if (
            start &&
            Math.hypot(event.clientX - start.x, event.clientY - start.y) >
                CLICK_SLOP
        ) {
            return;
        }

        onOpen();
    };

    return (
        <div
            ref={setNodeRef}
            style={{
                transform: CSS.Translate.toString(transform),
                transition,
            }}
            {...attributes}
            {...listeners}
            // `attributes` defaults to role="button", which may not wrap the
            // interactive title button below.
            role="group"
            aria-roledescription={
                draggable ? 'kartu task, bisa digeser' : undefined
            }
            // Capture phase on purpose: a plain `onPointerDown` here would
            // replace the one dnd-kit spreads through `listeners`, which is the
            // sensor's activator, and dragging would stop working entirely.
            onPointerDownCapture={handlePointerDown}
            onClick={handleClick}
            className={cn(
                // No border: the raised surface is a full step lighter than the
                // sunken column it sits in, which is what separates the two.
                'group rounded bg-card p-3 shadow-sm transition-colors',
                draggable
                    ? 'cursor-grab touch-none active:cursor-grabbing'
                    : 'cursor-pointer',
                'hover:bg-accent/25 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                // The card stays in place as a hollow slot while its clone
                // follows the pointer in the DragOverlay.
                isDragging && 'opacity-40',
                overlay && 'rotate-2 cursor-grabbing shadow-lg shadow-black/25',
            )}
        >
            <button
                type="button"
                onClick={(event) => {
                    event.stopPropagation();
                    onOpen();
                }}
                className="block w-full min-w-0 text-left text-sm leading-snug"
            >
                {task.title}
            </button>

            {/* No progress bar here, the way a Jira card carries none: the
                board answers "which column", and progress belongs to the views
                that are about it — the detail modal, the list and the timeline. */}

            {/* Sits where Jira puts its issue labels: directly under the title,
                above the fields. */}
            <Badge
                variant="outline"
                className={cn(
                    'mt-2 rounded-[3px]',
                    TASK_PRIORITY_BADGE[task.priority],
                )}
                title={`Prioritas ${TASK_PRIORITY_LABELS[task.priority]}`}
            >
                {TASK_PRIORITY_LABELS[task.priority]}
            </Badge>

            {/* Jira presents a date as a labelled field, not as an icon and a
                value crammed into the meta row. */}
            {task.due_date && (
                <div className="mt-3">
                    <p className="text-xs text-muted-foreground">
                        Tanggal selesai
                    </p>
                    <p
                        className={cn(
                            'text-sm tabular-nums',
                            task.is_overdue &&
                                'font-medium text-red-600 dark:text-red-400',
                        )}
                        title={
                            task.is_overdue
                                ? 'Tanggal selesai sudah terlewat'
                                : undefined
                        }
                    >
                        {formatDay(task.due_date)}
                        {task.is_overdue && (
                            <span className="sr-only"> — terlambat</span>
                        )}
                    </p>
                </div>
            )}

            {/* Jira's card footer: the issue type icon and the key on the
                left, the assignee avatar pinned to the right edge. */}
            <div className="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                <SquareCheckBig
                    className="size-4 shrink-0 text-emerald-600 dark:text-emerald-500"
                    aria-hidden="true"
                />
                <span className="tabular-nums">{task.wbs_number}</span>

                <span className="ml-auto">
                    {task.assignee ? (
                        <Avatar className="size-6" title={task.assignee.name}>
                            <AvatarImage
                                src={task.assignee.avatar ?? undefined}
                                alt=""
                            />
                            {/* The default `bg-muted` fallback sits within 0.02
                                of the raised card surface and disappears, so the
                                initials get the primary colour instead. */}
                            <AvatarFallback className="bg-primary text-[10px] font-medium text-primary-foreground">
                                {getInitials(task.assignee.name)}
                            </AvatarFallback>
                            <span className="sr-only">
                                Penanggung jawab: {task.assignee.name}
                            </span>
                        </Avatar>
                    ) : (
                        <span
                            className="flex size-6 items-center justify-center rounded-full bg-foreground/10"
                            title="Belum ditugaskan"
                        >
                            <User
                                className="size-3.5 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span className="sr-only">Belum ditugaskan</span>
                        </span>
                    )}
                </span>
            </div>

            {/* Jira hangs the subtask roll-up off the bottom of the card as its
                own rule-separated row instead of squeezing it into the footer
                meta. The chevron opens the parent, where the subtasks live. */}
            {task.children_count > 0 && (
                <button
                    type="button"
                    onClick={(event) => {
                        event.stopPropagation();
                        onOpen();
                    }}
                    className="-mx-3 mt-3 -mb-3 flex w-[calc(100%+1.5rem)] items-center gap-2 rounded-b-lg border-t border-border/60 px-3 py-2 text-xs text-muted-foreground transition-colors hover:bg-accent/40 hover:text-foreground"
                    title="Sub task selesai"
                >
                    <ListTree className="size-3.5" aria-hidden="true" />
                    Sub task
                    <span className="rounded bg-foreground/10 px-1.5 py-0.5 font-medium tabular-nums">
                        {task.done_children_count}/{task.children_count}
                    </span>
                    <ChevronRight
                        className="ml-auto size-4"
                        aria-hidden="true"
                    />
                </button>
            )}
        </div>
    );
}
