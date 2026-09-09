import { TaskAiChat } from '@/components/task/task-ai-chat';
import { apply, plan } from '@/routes/monitoring/ai';
import type { Option } from '@/types/members';
import type { TaskAssignee, TaskNode } from '@/types/tasks';

/** A monitoring page's per-project block. Mirrors PersonController::groupByProject. */
type ProjectGroup = {
    project: { id: number; name: string };
    can_edit: boolean;
    assignees: TaskAssignee[];
    tasks: TaskNode[];
};

/**
 * The assistant on a monitoring page, where the conversation is about one
 * person's work rather than about one project.
 *
 * The person page and the landing page show the same tasks in two shapes, so
 * the flattening lives here rather than twice over. Only blocks the viewer may
 * edit are named as somewhere a new task could land — the page deliberately
 * carries permission per project, because it crosses them.
 */
export function PersonAiChat({
    memberId,
    groups,
    statuses,
    models,
    defaultModel,
}: {
    /** The workspace member whose work this is — the route's own subject. */
    memberId: number;
    groups: ProjectGroup[];
    statuses: Option[];
    models: Option[];
    defaultModel: string;
}) {
    const editable = groups.filter((group) => group.can_edit);

    return (
        <TaskAiChat
            planUrl={plan(memberId).url}
            applyUrl={apply(memberId).url}
            tasks={groups.flatMap((group) => group.tasks)}
            projects={editable.map((group) => group.project)}
            assignees={editable.flatMap((group) => group.assignees)}
            statuses={statuses}
            models={models}
            defaultModel={defaultModel}
        />
    );
}
