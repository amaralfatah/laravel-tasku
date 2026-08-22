<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Task access (7.1).
 *
 * Reading follows the project; creating and editing require project
 * membership. Deleting is for whoever runs the project; everyone else may
 * only remove the tasks they created themselves.
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

    public function delete(User $user, Task $task): bool
    {
        if (! $this->update($user, $task)) {
            return false;
        }

        if ($task->project->isAdministeredBy($user)) {
            return true;
        }

        return $task->created_by === $user->id;
    }

    protected function inActiveWorkspace(Task $task): bool
    {
        return $task->workspace_id === $this->tenancy->id();
    }
}
