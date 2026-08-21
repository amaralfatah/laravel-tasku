import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { CalendarClock, GripVertical, ListTree } from 'lucide-react';
import { ProgressBar } from '@/components/task/progress-bar';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { formatWeek } from '@/lib/week';
import {
    TASK_PRIORITY_CLASSES,
    TASK_PRIORITY_LABELS
    
} from '@/types/tasks';
import type {TaskNode} from '@/types/tasks';

/**
 * Board card for a root task (BRD-5).
 *
 * Dragging is offered through a dedicated handle rather than the whole card,
 * so tapping the card still opens the detail sheet on touch devices.
 */
export function TaskCard({
    task,
    draggable,
    onOpen,
}: {
    task: TaskNode;
    draggable: boolean;
    onOpen: () => void;
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
    } = useSortable({ id: task.id, disabled: !draggable });

    return (
        <div
            ref={setNodeRef}
            style={{
                transform: CSS.Translate.toString(transform),
                transition,
            }}
            className={cn(
                'group rounded-lg border bg-card p-3 shadow-xs',
                isDragging && 'opacity-40',
            )}
        >
            <div className="flex items-start gap-2">
                {draggable && (
                    <button
                        ref={setActivatorNodeRef}
                        type="button"
                        className="mt-0.5 cursor-grab touch-none rounded p-0.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100 active:cursor-grabbing"
                        aria-label={`Geser ${task.title}`}
                        {...attributes}
                        {...listeners}
                    >
                        <GripVertical className="size-4" />
                    </button>
                )}

                <button
                    type="button"
                    onClick={onOpen}
                    className="min-w-0 flex-1 text-left"
                >
                    <span className="block text-xs text-muted-foreground tabular-nums">
                        {task.wbs_number}
                    </span>
                    <span className="mt-0.5 block text-sm font-medium">
                        {task.title}
                    </span>
                </button>
            </div>

            <div className="mt-3 space-y-2">
                <ProgressBar
                    value={task.progress}
                    rollup={task.rollup_progress}
                    showLabel
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Badge
                        className={cn(
                            'font-normal',
                            TASK_PRIORITY_CLASSES[task.priority],
                        )}
                    >
                        {TASK_PRIORITY_LABELS[task.priority]}
                    </Badge>

                    {task.due_date && (
                        <span
                            className={cn(
                                'flex items-center gap-1 text-xs tabular-nums',
                                task.is_overdue
                                    ? 'font-medium text-red-600 dark:text-red-400'
                                    : 'text-muted-foreground',
                            )}
                            title={
                                task.is_overdue
                                    ? 'Tanggal selesai sudah terlewat'
                                    : 'Tanggal selesai'
                            }
                        >
                            <CalendarClock
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            {formatWeek(task.due_date)}
                            {task.is_overdue && (
                                <span className="sr-only">terlambat</span>
                            )}
                        </span>
                    )}

                    {task.children_count > 0 && (
                        <span
                            className="flex items-center gap-1 text-xs text-muted-foreground tabular-nums"
                            title="Sub task selesai"
                        >
                            <ListTree className="size-3.5" aria-hidden="true" />
                            {task.done_children_count}/{task.children_count}
                        </span>
                    )}

                    <span className="ml-auto">
                        {task.assignee ? (
                            <Avatar className="size-6">
                                <AvatarImage
                                    src={task.assignee.avatar ?? undefined}
                                    alt=""
                                />
                                <AvatarFallback className="text-[10px]">
                                    {getInitials(task.assignee.name)}
                                </AvatarFallback>
                            </Avatar>
                        ) : (
                            <span className="text-xs text-muted-foreground">
                                Belum ditugaskan
                            </span>
                        )}
                    </span>
                </div>
            </div>
        </div>
    );
}
