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

    protected function inActiveWorkspace(Task $task): bool
    {
        return $task->workspace_id === $this->tenancy->id();
    }
}
