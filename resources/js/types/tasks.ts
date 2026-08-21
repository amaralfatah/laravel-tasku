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
    low: 'border-transparent bg-muted text-muted-foreground',
    medium: 'border-transparent bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
    high: 'border-transparent bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    urgent: 'border-transparent bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
};
