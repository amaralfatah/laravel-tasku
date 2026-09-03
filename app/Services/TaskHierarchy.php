<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the structural columns of a task: `path`, `depth`, `wbs_number` and
 * `position` (TSK-9..TSK-13).
 *
 * `path` carries the task's own id — `/12/45/78/` — so a subtree is a prefix
 * match with no recursive query. WBS renumbering is deliberately scoped to the
 * affected branch rather than the whole project (R-3), and everything that can
 * touch more than one row runs in a transaction.
 */
class TaskHierarchy
{
    /**
     * Create a task, optionally under a parent, and number it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Project $project, array $attributes, ?Task $parent = null): Task
    {
        $this->guardDepth($parent);

        return DB::transaction(function () use ($project, $attributes, $parent): Task {
            $task = new Task($attributes);
            $task->project_id = $project->id;
            $task->workspace_id = $project->workspace_id;
            $task->parent_task_id = $parent?->id;
            $task->depth = $parent === null ? 0 : $parent->depth + 1;
            $task->position = $this->nextPosition($project, $parent);
            $task->save();

            $task->forceFill(['path' => ($parent->path ?? '/').$task->id.'/'])->save();

            $this->renumber($project, $parent);

            return $task->refresh();
        });
    }

    /**
     * Move a task under a new parent and/or to a new position among siblings.
     *
     * Both the old and the new branch are renumbered, since removing a task
     * shifts the numbers of everything after it (TSK-13).
     */
    public function move(Task $task, ?Task $parent, ?int $position = null): Task
    {
        $this->guardMove($task, $parent);

        $project = $task->project;
        $oldParent = $task->parent;
        $oldPath = $task->path;
        $newPath = ($parent->path ?? '/').$task->id.'/';
        $depthShift = (($parent->depth ?? -1) + 1) - $task->depth;

        $this->guardSubtreeDepth($task, $depthShift);

        return DB::transaction(function () use ($task, $parent, $oldParent, $oldPath, $newPath, $depthShift, $position, $project): Task {
            if ($oldPath !== $newPath) {
                // Swap the path prefix on every descendant in one statement.
                // Fully bound, so no path value is interpolated into SQL.
                DB::update(
                    'update tasks
                        set path = ? || substring(path from ?),
                            depth = depth + ?
                      where project_id = ?
                        and path like ?',
                    [$newPath, strlen($oldPath) + 1, $depthShift, $task->project_id, $oldPath.'_%'],
                );

                $task->forceFill([
                    'parent_task_id' => $parent?->id,
                    'path' => $newPath,
                    'depth' => $task->depth + $depthShift,
                ])->save();
            }

            $this->reposition($task->refresh(), $position);

            $this->renumber($project, $parent);

            if ($oldParent?->id !== $parent?->id) {
                $this->renumber($project, $oldParent);
            }

            return $task->refresh();
        });
    }

    /**
     * Reorder a task among its current siblings (BRD-3).
     */
    public function reorder(Task $task, int $position): Task
    {
        return DB::transaction(function () use ($task, $position): Task {
            $this->reposition($task, $position);
            $this->renumber($task->project, $task->parent);

            return $task->refresh();
        });
    }

    /**
     * Soft delete a task together with its whole subtree (TSK-3).
     */
    public function delete(Task $task): void
    {
        DB::transaction(function () use ($task): void {
            $parent = $task->parent;
            $project = $task->project;

            Task::query()->inSubtree($task)->delete();

            $this->renumber($project, $parent);

            // A mass delete fires no model events, so the parent's roll-up has
            // to be asked for here.
            $this->syncParentProgress($parent?->refresh());
        });
    }

    /**
     * Recompute a task's progress from its direct sub tasks (TSK-17).
     *
     * Nobody types a percentage for a task that has sub tasks: finishing a sub
     * task is what moves the task above it, and a status set by hand on such a
     * task is overruled here. A task without sub tasks is left alone — its own
     * status is what drives its progress. Saving fires the observer, which
     * calls this again for the level above; an unchanged task stops the climb.
     */
    public function syncParentProgress(?Task $parent): void
    {
        $average = $parent?->children()->avg('progress');

        if ($parent === null || $average === null) {
            return;
        }

        $average = (int) round($average);

        // Progress and status must not contradict each other (TSK-16).
        $status = match (true) {
            $average >= 100 => TaskStatus::Done,
            $average > 0 => TaskStatus::InProgress,
            default => TaskStatus::Todo,
        };

        if ($parent->progress === $average && $parent->status === $status) {
            return;
        }

        // The observer calls this from `saved`, which Eloquent fires before it
        // syncs the originals. Without this the rollup would be compared
        // against the values the task held *before* the request and dropped as
        // "not dirty" — a parent marked done by hand then kept its 100%.
        $parent->syncOriginal();

        $parent->forceFill([
            'progress' => $average,
            'status' => $status,
        ])->save();
    }

    /**
     * Apply the status/progress synchronisation rules (TSK-15, TSK-16).
     *
     * A status change wins over a progress value sent in the same request,
     * because the user just clicked the status.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function syncProgress(array $attributes, Task $task): array
    {
        $status = isset($attributes['status'])
            ? TaskStatus::from($attributes['status'])
            : $task->status;

        $statusChanged = isset($attributes['status']) && $status !== $task->status;
        $forced = $status->forcedProgress();

        if ($statusChanged && $forced !== null) {
            $attributes['progress'] = $forced;

            return $attributes;
        }

        if (! array_key_exists('progress', $attributes)) {
            return $attributes;
        }

        $progress = (int) $attributes['progress'];

        // Progress and status must not contradict each other (TSK-16).
        if ($status === TaskStatus::Todo && $progress > 0) {
            $attributes['status'] = TaskStatus::InProgress->value;
        }

        if ($status === TaskStatus::Done && $progress < 100) {
            $attributes['status'] = TaskStatus::InProgress->value;
        }

        if ($progress === 100 && $status !== TaskStatus::Done) {
            $attributes['status'] = TaskStatus::Done->value;
        }

        return $attributes;
    }

    /**
     * Average progress of a task's direct children (TSK-17).
     *
     * Reported alongside the manual value rather than replacing it, so a parent
     * that is out of step with its children is visible instead of silently
     * overwritten.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<int, int> keyed by task id
     */
    public function rollupProgress(Collection $tasks): array
    {
        $byParent = $tasks
            ->whereNotNull('parent_task_id')
            ->groupBy('parent_task_id');

        return $byParent
            ->map(fn (Collection $children): int => (int) round($children->avg('progress')))
            ->all();
    }

    /**
     * Rebuild path, depth and WBS for a whole project, used by the repair command.
     */
    public function rebuild(Project $project): int
    {
        return DB::transaction(function () use ($project): int {
            $tasks = Task::withoutGlobalScopes()
                ->withTrashed()
                ->where('project_id', $project->id)
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            $touched = 0;

            foreach ($tasks as $task) {
                $path = '/'.$task->id.'/';
                $depth = 0;
                $cursor = $task;

                while ($cursor->parent_task_id !== null && $tasks->has($cursor->parent_task_id)) {
                    $cursor = $tasks[$cursor->parent_task_id];
                    $path = '/'.$cursor->id.$path;
                    $depth++;
                }

                if ($task->path !== $path || $task->depth !== $depth) {
                    $task->forceFill(['path' => $path, 'depth' => $depth])->saveQuietly();
                    $touched++;
                }
            }

            $this->renumberBranch($project, null, '');

            return $touched;
        });
    }

    /**
     * Renumber one branch: the children of `$parent` and everything below them.
     */
    protected function renumber(Project $project, ?Task $parent): void
    {
        $prefix = $parent === null ? '' : $parent->refresh()->wbs_number;

        $this->renumberBranch($project, $parent?->id, $prefix);
    }

    /**
     * @param  string  $prefix  WBS of the parent, empty at the root
     */
    protected function renumberBranch(Project $project, ?int $parentId, string $prefix): void
    {
        $siblings = Task::query()
            ->where('project_id', $project->id)
            ->where('parent_task_id', $parentId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($siblings->values() as $index => $sibling) {
            $number = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);

            if ($sibling->wbs_number !== $number || $sibling->position !== $index) {
                $sibling->forceFill(['wbs_number' => $number, 'position' => $index])->saveQuietly();
            }

            $this->renumberBranch($project, $sibling->id, $number);
        }
    }

    /**
     * Place a task at `$position` among its siblings, shifting the rest.
     */
    protected function reposition(Task $task, ?int $position): void
    {
        if ($position === null) {
            return;
        }

        $siblings = Task::query()
            ->where('project_id', $task->project_id)
            ->where('parent_task_id', $task->parent_task_id)
            ->where('id', '!=', $task->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->values();

        $target = max(0, min($position, $siblings->count()));
        $ordered = $siblings->all();
        array_splice($ordered, $target, 0, [$task]);

        foreach ($ordered as $index => $sibling) {
            if ($sibling->position !== $index) {
                $sibling->forceFill(['position' => $index])->saveQuietly();
            }
        }
    }

    protected function nextPosition(Project $project, ?Task $parent): int
    {
        return (int) Task::query()
            ->where('project_id', $project->id)
            ->where('parent_task_id', $parent?->id)
            ->max('position') + 1;
    }

    protected function guardDepth(?Task $parent): void
    {
        if ($parent !== null && ! $parent->canHaveChildren()) {
            throw ValidationException::withMessages([
                'parent_task_id' => 'Task sudah berada di level terdalam ('.Task::MAX_DEPTH.' tingkat).',
            ]);
        }
    }

    /**
     * A task may not become its own descendant (TSK-11).
     */
    protected function guardMove(Task $task, ?Task $parent): void
    {
        if ($parent === null) {
            return;
        }

        if ($parent->id === $task->id || str_starts_with($parent->path, $task->path)) {
            throw ValidationException::withMessages([
                'parent_task_id' => 'Task tidak bisa dipindahkan ke dalam dirinya sendiri.',
            ]);
        }

        if ($parent->project_id !== $task->project_id) {
            throw ValidationException::withMessages([
                'parent_task_id' => 'Task induk harus berada di project yang sama.',
            ]);
        }

        $this->guardDepth($parent);
    }

    /**
     * Reject a move that would push any descendant past the depth limit.
     */
    protected function guardSubtreeDepth(Task $task, int $depthShift): void
    {
        $deepest = (int) Task::query()->inSubtree($task)->max('depth');

        if ($deepest + $depthShift >= Task::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_task_id' => 'Pemindahan ini melebihi batas '.Task::MAX_DEPTH.' tingkat.',
            ]);
        }
    }
}
