<?php

namespace App\Support;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Requester;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Serialises tasks for the frontend.
 *
 * Board, list, timeline and the monitoring pages all render the same task
 * shape, so the mapping lives here instead of being repeated per controller.
 * Child counts and the rollup average are computed from the collection that is
 * already loaded, which keeps these pages free of N+1 queries.
 */
class TaskPresenter
{
    /**
     * @param  Collection<int, Task>  $tasks
     * @param  string  $projectKey  the tasks' project key, which turns their number into a reference
     * @return array<int, array<string, mixed>>
     */
    public static function collection(Collection $tasks, User $user, bool $canEdit, string $projectKey): array
    {
        $childrenByParent = $tasks
            ->whereNotNull('parent_task_id')
            ->groupBy('parent_task_id');

        return $tasks
            ->map(fn (Task $task): array => static::one(
                $task,
                $user,
                $canEdit,
                $projectKey,
                $childrenByParent->get($task->id),
            ))
            ->values()
            ->all();
    }

    /**
     * The key is passed in rather than read off `$task->project`, so a page
     * that renders a whole board never loads the project once per task.
     *
     * @param  Collection<int, Task>|null  $children  direct children, when already loaded
     * @return array<string, mixed>
     */
    public static function one(Task $task, User $user, bool $canEdit, string $projectKey, ?Collection $children = null): array
    {
        $childCount = $children?->count() ?? 0;

        return [
            'id' => $task->id,
            'parent_task_id' => $task->parent_task_id,
            'reference' => $projectKey.'-'.$task->wbs_number,
            'wbs_number' => $task->wbs_number,
            'depth' => $task->depth,
            'path' => $task->path,
            'title' => $task->title,
            'description' => $task->description,
            'assignee' => $task->assignee === null ? null : [
                'id' => $task->assignee->id,
                'name' => $task->assignee->name,
                'avatar' => $task->assignee->avatar,
            ],
            // Who asked for the work. Kept apart from the assignee and the
            // creator: the requester is usually neither, and often not a user
            // of the application at all.
            'requester' => $task->requester === null ? null : [
                'id' => $task->requester->id,
                'name' => $task->requester->name,
                'organization' => $task->requester->organization,
            ],
            'status' => $task->status->value,
            'progress' => $task->progress,
            'rollup_progress' => $childCount === 0
                ? null
                : (int) round($children->avg('progress')),
            'priority' => $task->priority->value,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'position' => $task->position,
            'completed_at' => $task->completed_at?->toIso8601String(),
            // Jira closes its Details panel with these two, and they cost no
            // query: the columns are already on the row.
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'children_count' => $childCount,
            'done_children_count' => $children === null
                ? 0
                : $children->where('status', TaskStatus::Done)->count(),
            'is_overdue' => $task->isOverdue(),
            'submitted_at' => $task->submitted_at?->toIso8601String(),
            'reviewed_at' => $task->reviewed_at?->toIso8601String(),
            'can_edit' => $canEdit,
            'can_delete' => $canEdit && $user->can('delete', $task),
            // Whether this person is the one who accepts or returns the work.
            'can_review' => $canEdit && $user->can('review', $task),
            'can_have_children' => $task->canHaveChildren(),
        ];
    }

    /**
     * Status options for selects and board columns.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function statusOptions(): array
    {
        return array_map(
            fn (TaskStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
            TaskStatus::cases(),
        );
    }

    /**
     * The workspace's requester list, as a task form offers it: active rows
     * only, by name. Retired ones stay on the tasks that already name them.
     *
     * @return array<int, array{id: int, name: string, organization: string|null}>
     */
    public static function requesterOptions(): array
    {
        return Requester::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'organization'])
            ->map(fn (Requester $requester): array => [
                'id' => $requester->id,
                'name' => $requester->name,
                'organization' => $requester->organization,
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function priorityOptions(): array
    {
        return array_map(
            fn (TaskPriority $priority): array => [
                'value' => $priority->value,
                'label' => $priority->label(),
            ],
            TaskPriority::cases(),
        );
    }
}
