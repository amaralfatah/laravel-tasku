import { Head } from '@inertiajs/react';
import { ListChecks, ListTree, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ProjectHeader } from '@/components/project/project-header';
import { TaskCreateDialog } from '@/components/task/task-create-dialog';
import { TaskDetailModal } from '@/components/task/task-detail-modal';
import { TaskFilterBar } from '@/components/task/task-filters';
import { TaskTreeRow } from '@/components/task/task-tree-row';
import { Button } from '@/components/ui/button';
import { useFocusedTask } from '@/hooks/use-focused-task';
import { useTaskFilters } from '@/hooks/use-task-filters';
import { index as projectsIndex, list } from '@/routes/projects';
import type { Option } from '@/types/members';
import type {
    ProjectSummary,
    TaskAssignee,
    TaskFilterState,
    TaskNode,
} from '@/types/tasks';

type PageProps = {
    project: ProjectSummary;
    tasks: TaskNode[];
    filters: TaskFilterState;
    statuses: Option[];
    priorities: Option[];
    assignees: TaskAssignee[];
    maxDepth: number;
    /** Task to open on arrival, e.g. when following a notification (NTF-3). */
    focusTaskId: number | null;
    can: { contribute: boolean; edit_project: boolean };
};

export default function ProjectList({
    project,
    tasks,
    filters,
    statuses,
    priorities,
    assignees,
    focusTaskId,
    can,
}: PageProps) {
    const [collapsed, setCollapsed] = useState<Set<number>>(new Set());
    const [openTaskId, setOpenTaskId] = useFocusedTask(focusTaskId);
    const [createParent, setCreateParent] = useState<TaskNode | null>(null);
    const [createOpen, setCreateOpen] = useState(false);

    const applyFilters = useTaskFilters(filters, list(project.id).url);

    const childCounts = useMemo(() => {
        const counts = new Map<number, number>();

        for (const task of tasks) {
            if (task.parent_task_id !== null) {
                counts.set(
                    task.parent_task_id,
                    (counts.get(task.parent_task_id) ?? 0) + 1,
                );
            }
        }

        return counts;
    }, [tasks]);

    /**
     * Hide the descendants of a collapsed row. Sorting by anything other than
     * the hierarchy flattens the list, so collapsing only applies there.
     */
    const visibleTasks = useMemo(() => {
        if (filters.sort !== 'wbs' || collapsed.size === 0) {
            return tasks;
        }

        const hiddenPaths = tasks
            .filter((task) => collapsed.has(task.id))
            .map((task) => task.path);

        return tasks.filter(
            (task) =>
                !hiddenPaths.some(
                    (path) => task.path !== path && task.path.startsWith(path),
                ),
        );
    }, [tasks, collapsed, filters.sort]);

    const toggle = (id: number) =>
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const openTask = tasks.find((task) => task.id === openTaskId) ?? null;

    return (
        <>
            <Head title={project.name} />

            <div className="space-y-6">
                <ProjectHeader project={project} active="list" />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <TaskFilterBar
                        filters={filters}
                        assignees={assignees}
                        statuses={statuses}
                        priorities={priorities}
                        showSort
                        onChange={applyFilters}
                    />

                    <div className="flex gap-2">
                        {tasks.length > 0 && filters.sort === 'wbs' && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setCollapsed((current) =>
                                        current.size > 0
                                            ? new Set()
                                            : new Set(
                                                  tasks
                                                      .filter(
                                                          (task) =>
                                                              childCounts.get(
                                                                  task.id,
                                                              ) ?? 0,
                                                      )
                                                      .map((task) => task.id),
                                              ),
                                    )
                                }
                            >
                                <ListTree
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {collapsed.size > 0
                                    ? 'Buka semua'
                                    : 'Tutup semua'}
                            </Button>
                        )}

                        {can.contribute && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    setCreateParent(null);
                                    setCreateOpen(true);
                                }}
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                Task baru
                            </Button>
                        )}
                    </div>
                </div>

                <div className="rounded-lg border">
                    <div className="hidden grid-cols-[minmax(0,1fr)_9rem_10rem_7rem_9rem_2rem] gap-3 border-b bg-muted/40 px-3 py-2 text-xs font-medium text-muted-foreground lg:grid">
                        <span>Task &amp; judul</span>
                        <span>Progress</span>
                        <span>Penanggung jawab</span>
                        <span>Status</span>
                        <span>Prioritas &amp; selesai</span>
                        <span className="sr-only">Aksi</span>
                    </div>

                    {visibleTasks.length === 0 ? (
                        <div className="p-12 text-center">
                            <ListChecks
                                className="mx-auto mb-3 size-8 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p className="font-medium">
                                {tasks.length === 0
                                    ? 'Belum ada task'
                                    : 'Tidak ada task yang cocok'}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {tasks.length === 0
                                    ? 'Mulai dengan membuat task tingkat pertama.'
                                    : 'Ubah atau reset filter untuk melihat task lain.'}
                            </p>
                        </div>
                    ) : (
                        visibleTasks.map((task) => (
                            <TaskTreeRow
                                key={task.id}
                                task={task}
                                hasChildren={
                                    filters.sort === 'wbs' &&
                                    (childCounts.get(task.id) ?? 0) > 0
                                }
                                collapsed={collapsed.has(task.id)}
                                assignees={assignees}
                                statuses={statuses}
                                onToggle={() => toggle(task.id)}
                                onOpen={() => setOpenTaskId(task.id)}
                                onAddChild={() => {
                                    setCreateParent(task);
                                    setCreateOpen(true);
                                }}
                            />
                        ))
                    )}
                </div>
            </div>

            <TaskDetailModal
                task={openTask}
                subtasks={tasks.filter(
                    (item) => item.parent_task_id === openTaskId,
                )}
                assignees={assignees}
                statuses={statuses}
                priorities={priorities}
                onClose={() => setOpenTaskId(null)}
                onOpenTask={setOpenTaskId}
                onAddSubtask={
                    openTask
                        ? () => {
                              setCreateParent(openTask);
                              setCreateOpen(true);
                          }
                        : undefined
                }
            />

            <TaskCreateDialog
                open={createOpen}
                projectId={project.id}
                parent={createParent}
                assignees={assignees}
                priorities={priorities}
                onClose={() => setCreateOpen(false)}
            />
        </>
    );
}

ProjectList.layout = ({ project }: PageProps) => ({
    breadcrumbs: [
        { title: 'Project', href: projectsIndex() },
        { title: project.name, href: list(project.id) },
    ],
});
