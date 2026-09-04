import { router } from '@inertiajs/react';
import { ChevronRight, MoreHorizontal, Plus } from 'lucide-react';
import { ProgressBar } from '@/components/task/progress-bar';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { formatDay } from '@/lib/week';
import { destroy, update } from '@/routes/tasks';
import type { Option } from '@/types/members';
import { TASK_PRIORITY_CLASSES, TASK_PRIORITY_LABELS } from '@/types/tasks';
import type { TaskAssignee, TaskNode, TaskStatus } from '@/types/tasks';

const UNASSIGNED = 'none';

/**
 * One row of the hierarchical task list (LST-1..LST-4).
 *
 * Status, progress and assignee are editable inline; everything else opens the
 * detail sheet.
 */
export function TaskTreeRow({
    task,
    hasChildren,
    collapsed,
    assignees,
    statuses,
    onToggle,
    onOpen,
    onAddChild,
}: {
    task: TaskNode;
    hasChildren: boolean;
    collapsed: boolean;
    assignees: TaskAssignee[];
    statuses: Option[];
    onToggle: () => void;
    onOpen: () => void;
    onAddChild: () => void;
}) {
    const getInitials = useInitials();

    const patch = (data: Record<string, string | number | null>) =>
        router.patch(update(task.id).url, data, { preserveScroll: true });

    return (
        <div
            className={cn(
                'grid min-h-12 grid-cols-[minmax(0,1fr)_repeat(3,auto)] items-center gap-2 border-b px-3 py-1.5 last:border-b-0 hover:bg-muted/40 sm:gap-3 lg:grid-cols-[minmax(0,1fr)_9rem_10rem_7rem_9rem_2rem]',
            )}
        >
            <div
                className="flex min-w-0 items-center gap-1.5"
                style={{
                    // Indentation shrinks with depth so 4 levels stay usable
                    // on a narrow screen (R-7).
                    paddingLeft: `${task.depth * Math.max(10, 20 - task.depth * 2)}px`,
                }}
            >
                {hasChildren ? (
                    <button
                        type="button"
                        onClick={onToggle}
                        aria-expanded={!collapsed}
                        aria-label={
                            collapsed
                                ? `Buka sub task ${task.title}`
                                : `Tutup sub task ${task.title}`
                        }
                        className="flex size-6 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-muted"
                    >
                        <ChevronRight
                            className={cn(
                                'size-4 transition-transform duration-150',
                                !collapsed && 'rotate-90',
                            )}
                        />
                    </button>
                ) : (
                    <span className="size-6 shrink-0" aria-hidden="true" />
                )}

                {/* On a phone the reference took a quarter of the row and left
                    the title truncated to a couple of characters; it is on the
                    card in the detail sheet either way. */}
                <span className="hidden w-24 shrink-0 truncate text-xs text-muted-foreground tabular-nums sm:inline">
                    {task.reference}
                </span>

                <button
                    type="button"
                    onClick={onOpen}
                    className="truncate text-left text-sm hover:underline"
                >
                    {task.title}
                </button>

                {task.children_count > 0 && (
                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                        {task.done_children_count}/{task.children_count}
                    </span>
                )}
            </div>

            <div className="hidden lg:block">
                <ProgressBar
                    value={task.progress}
                    rollup={task.rollup_progress}
                    showLabel
                />
            </div>

            <Select
                value={String(task.assignee?.id ?? UNASSIGNED)}
                disabled={!task.can_edit}
                onValueChange={(value) =>
                    patch({
                        assignee_id:
                            value === UNASSIGNED ? null : Number(value),
                    })
                }
            >
                <SelectTrigger
                    size="sm"
                    className="hidden w-full border-transparent shadow-none hover:border-input lg:flex"
                    aria-label={`Penanggung jawab ${task.title}`}
                >
                    {task.assignee ? (
                        <span className="flex min-w-0 items-center gap-2">
                            <Avatar className="size-5">
                                <AvatarImage
                                    src={task.assignee.avatar ?? undefined}
                                    alt=""
                                />
                                <AvatarFallback className="text-[10px]">
                                    {getInitials(task.assignee.name)}
                                </AvatarFallback>
                            </Avatar>
                            <span className="truncate">
                                {task.assignee.name}
                            </span>
                        </span>
                    ) : (
                        <SelectValue placeholder="Belum ditugaskan" />
                    )}
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={UNASSIGNED}>Belum ditugaskan</SelectItem>
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

            <Select
                value={task.status}
                disabled={!task.can_edit}
                onValueChange={(value) =>
                    patch({ status: value as TaskStatus })
                }
            >
                <SelectTrigger
                    size="sm"
                    className="w-full border-transparent shadow-none hover:border-input"
                    aria-label={`Status ${task.title}`}
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

            <div className="hidden items-center gap-2 lg:flex">
                <Badge
                    className={cn(
                        'shrink-0 font-normal',
                        TASK_PRIORITY_CLASSES[task.priority],
                    )}
                >
                    {TASK_PRIORITY_LABELS[task.priority]}
                </Badge>

                <span
                    className={cn(
                        'text-xs tabular-nums',
                        task.is_overdue
                            ? 'font-medium text-destructive'
                            : 'text-muted-foreground',
                    )}
                    title={
                        task.is_overdue
                            ? 'Tanggal selesai sudah terlewat'
                            : undefined
                    }
                >
                    {formatDay(task.due_date)}
                </span>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 shrink-0"
                        aria-label={`Aksi untuk ${task.title}`}
                    >
                        <MoreHorizontal className="size-4" />
                    </Button>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={onOpen}>
                        Buka detail
                    </DropdownMenuItem>
                    {task.can_edit && (
                        <DropdownMenuItem
                            disabled={!task.can_have_children}
                            onSelect={onAddChild}
                        >
                            <Plus className="size-4" aria-hidden="true" />
                            Tambah sub task
                        </DropdownMenuItem>
                    )}
                    {task.can_delete && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => {
                                    if (
                                        confirm(
                                            task.children_count > 0
                                                ? `Hapus "${task.title}" beserta ${task.children_count} sub task-nya?`
                                                : `Hapus "${task.title}"?`,
                                        )
                                    ) {
                                        router.delete(destroy(task.id).url, {
                                            preserveScroll: true,
                                        });
                                    }
                                }}
                            >
                                Hapus
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}
