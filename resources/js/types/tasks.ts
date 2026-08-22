import type { ProjectStatus } from '@/types/projects';

export type ProjectSummary = {
    id: number;
    name: string;
    description: string | null;
    status: ProjectStatus;
    status_label: string;
    org_unit: { id: number; name: string };
};

export type TaskFilterState = {
    assignee_id: number | null;
    status: TaskStatus | null;
    priority: TaskPriority | null;
    search: string | null;
    sort: 'wbs' | 'due_date' | 'priority' | 'created_at';
};

export type TaskStatus = 'todo' | 'in_progress' | 'done';

export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';

export type TaskAssignee = {
    id: number;
    name: string;
    avatar: string | null;
};

export type TaskNode = {
    id: number;
    parent_task_id: number | null;
    wbs_number: string;
    depth: number;
    path: string;
    title: string;
    description: string | null;
    assignee: TaskAssignee | null;
    status: TaskStatus;
    progress: number;
    /** Average progress of the direct children, null when there are none (TSK-17). */
    rollup_progress: number | null;
    priority: TaskPriority;
    start_date: string | null;
    due_date: string | null;
    position: number;
    children_count: number;
    done_children_count: number;
    is_overdue: boolean;
    can_edit: boolean;
    can_delete: boolean;
    can_have_children: boolean;
};

export const TASK_STATUS_LABELS: Record<TaskStatus, string> = {
    todo: 'To Do',
    in_progress: 'Dikerjakan',
    done: 'Selesai',
};

export const TASK_STATUS_ORDER: TaskStatus[] = ['todo', 'in_progress', 'done'];

export const TASK_PRIORITY_LABELS: Record<TaskPriority, string> = {
    low: 'Rendah',
    medium: 'Sedang',
    high: 'Tinggi',
    urgent: 'Mendesak',
};

/**
 * Priority colours double up with the label text, so colour is never the only
 * signal (WCAG: do not rely on colour alone).
 */
export const TASK_PRIORITY_CLASSES: Record<TaskPriority, string> = {
    low: 'border-border bg-muted text-muted-foreground',
    medium: 'border-border bg-secondary text-secondary-foreground',
    high: 'border-border bg-accent text-accent-foreground',
    urgent: 'border-destructive/25 bg-destructive/10 text-destructive',
};

/**
 * Board and list status colours. Paired with {@link TASK_STATUS_LABELS} so the
 * state is always readable without colour.
 */
export const TASK_STATUS_VARIANT: Record<
    TaskStatus,
    'secondary' | 'default' | 'outline'
> = {
    todo: 'secondary',
    in_progress: 'default',
    done: 'outline',
};

/**
 * Priority as an outlined chip for the board card, in the style Jira uses for
 * issue labels: the colour lives in the border only, so a row of chips stays
 * quieter than the task title above it. Pair with the `outline` badge variant.
 * The chip's text is the priority name, so colour is never the only signal.
 */
export const TASK_PRIORITY_BADGE: Record<TaskPriority, string> = {
    low: 'border-muted-foreground/50',
    medium: 'border-foreground/40',
    high: 'border-foreground/70',
    urgent: 'border-destructive',
};
