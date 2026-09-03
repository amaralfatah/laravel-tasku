<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Task access (7.1).
 *
 * Reading follows the project; creating, editing and deleting require project
 * membership. Deleting is deliberately as wide as editing: a team-managed
 * project is run by the people on it, so an ODS may clear out a task a
 * colleague opened without waiting for a leader.
 */
class TaskPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function view(User $user, Task $task): bool
    {
        return $this->inActiveWorkspace($task)
            && $user->can('view', $task->project);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->inActiveWorkspace($task)
            && $user->can('contribute', $task->project);
    }

    /**
     * Whoever may change a task may also remove it. Deleting is soft and takes
     * the whole subtree with it (TSK-3), so the project's own membership is the
     * boundary — not authorship, which seeded and imported tasks do not carry.
     */
    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /**
     * Accept or return work somebody handed up.
     *
     * Two people qualify, which is exactly the two-tier flow a graded
     * organisation runs: whoever owns the task above this one — the assistant
     * over a staff member's subtask, the sub division head over the
     * assistant's — and whoever administers the project, so a leader is never
     * blocked by an absent reviewer. Nobody signs off their own work.
     */
    public function review(User $user, Task $task): bool
    {
        if (! $this->inActiveWorkspace($task) || ! $user->can('contribute', $task->project)) {
            return false;
        }

        if ($task->assignee_id === $user->id && ! $task->project->isAdministeredBy($user)) {
            return false;
        }

        return $task->parent?->assignee_id === $user->id
            || $task->project->isAdministeredBy($user);
    }

    protected function inActiveWorkspace(Task $task): bool
    {
        return $task->workspace_id === $this->tenancy->id();
    }
}
