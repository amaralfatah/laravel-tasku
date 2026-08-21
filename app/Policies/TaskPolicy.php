<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Task access (7.1).
 *
 * Reading follows the project; creating and editing require project
 * membership. An ODS may only delete tasks they created themselves.
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

        if ($this->tenancy->member()?->role->managesProjects()) {
            return true;
        }

        return $task->created_by === $user->id;
    }

    protected function inActiveWorkspace(Task $task): bool
    {
        return $task->workspace_id === $this->tenancy->id();
    }
}
