import type { OrgUnitLocation, ProjectStatus } from '@/types/projects';
import type { RequesterOption } from '@/types/requesters';

export type ProjectSummary = {
    id: number;
    name: string;
    key: string;
    description: string | null;
    status: ProjectStatus;
    status_label: string;
    org_unit: OrgUnitLocation;
};

export type TaskFilterState = {
    assignee_id: number | null;
    status: TaskStatus | null;
    priority: TaskPriority | null;
    search: string | null;
    sort: 'wbs' | 'due_date' | 'priority' | 'created_at';
    /** Past its due date and still open — the "needs attention" filter. */
    overdue: boolean;
};

export type TaskStatus = 'todo' | 'in_progress' | 'review' | 'done';

export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';

export type TaskAssignee = {
    id: number;
    name: string;
    avatar: string | null;
};

export type TaskNode = {
    id: number;
    parent_task_id: number | null;
    /**
     * The task as people say it: the project key plus the WBS number, e.g.
     * `GROWMATE-1.2`. It moves when the branch is renumbered, so key rows and
     * links off `id`, never off this.
     */
    reference: string;
    wbs_number: string;
    depth: number;
    path: string;
    title: string;
    description: string | null;
    assignee: TaskAssignee | null;
    /** Who asked for the work, off the workspace's managed list. */
    requester: RequesterOption | null;
    status: TaskStatus;
    progress: number;
    /** Average progress of the direct children, null when there are none (TSK-17). */
    rollup_progress: number | null;
    priority: TaskPriority;
    start_date: string | null;
    due_date: string | null;
    position: number;
    /** ISO 8601, stamped when the status turned done. */
    completed_at: string | null;
    /** ISO 8601. Closes the detail modal's Details panel, as Jira's does. */
    created_at: string | null;
    updated_at: string | null;
    children_count: number;
    done_children_count: number;
    is_overdue: boolean;
    /** ISO 8601, stamped when the work was handed up for review. */
    submitted_at: string | null;
    /** ISO 8601, stamped when someone accepted or returned it. */
    reviewed_at: string | null;
    can_edit: boolean;
    can_delete: boolean;
    /** True for the person who accepts or returns this task's work. */
    can_review: boolean;
    can_have_children: boolean;
};

export const TASK_STATUS_LABELS: Record<TaskStatus, string> = {
    todo: 'To Do',
    in_progress: 'Dikerjakan',
    review: 'Menunggu review',
    done: 'Selesai',
};

export const TASK_STATUS_ORDER: TaskStatus[] = [
    'todo',
    'in_progress',
    'review',
    'done',
];

export const TASK_PRIORITY_LABELS: Record<TaskPriority, string> = {
    low: 'Rendah',
    medium: 'Sedang',
    high: 'Tinggi',
    urgent: 'Mendesak',
};

/**
 * Priority colours double up with the label text, so colour is never the only
 * signal (WCAG: do not rely on colour alone).
 *
 * The four steps run on their own `--priority-*` tokens rather than on the
 * generic surface ones. Borrowing those put `secondary` and `accent` — the same
 * colour in the light theme — on two neighbouring steps, and handed the dark
 * theme a near-white chip for "Sedang" and a chip darker than the page for
 * "Tinggi". Every pair here clears 5.9:1 in both themes.
 */
export const TASK_PRIORITY_CLASSES: Record<TaskPriority, string> = {
    // "Rendah" is the one step with no hue to be recognised by, so its edge is
    // drawn harder than the rest to keep the chip from dissolving into a
    // popover or a card.
    low: 'border-priority-low-foreground/35 bg-priority-low text-priority-low-foreground',
    medium: 'border-priority-medium-foreground/20 bg-priority-medium text-priority-medium-foreground',
    high: 'border-priority-high-foreground/20 bg-priority-high text-priority-high-foreground',
    urgent: 'border-priority-urgent-foreground/25 bg-priority-urgent text-priority-urgent-foreground',
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
    review: 'outline',
    done: 'outline',
};

/**
 * Priority as an outlined chip for the board card, in the style Jira uses for
 * issue labels: no fill, so a row of chips stays quieter than the task title
 * above it. Pair with the `outline` badge variant. The chip's text is the
 * priority name, so colour is never the only signal.
 *
 * The hue sits in the text as well as the border. Carrying it in a 1px border
 * alone left the first three steps apart only by the opacity of a neutral
 * line — a distinction that survives neither a small screen nor a glance.
 */
export const TASK_PRIORITY_BADGE: Record<TaskPriority, string> = {
    low: 'border-priority-low-foreground/45 text-priority-low-foreground',
    medium: 'border-priority-medium-foreground/50 text-priority-medium-foreground',
    high: 'border-priority-high-foreground/60 text-priority-high-foreground',
    urgent: 'border-priority-urgent-foreground/70 text-priority-urgent-foreground',
};
