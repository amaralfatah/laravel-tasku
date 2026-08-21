<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Project access (7.1, 7.2).
 *
 * Viewing follows Project::visibleTo — project membership, a subtree scope, or
 * a manager role. Creating, editing and deleting are manager-only.
 */
class ProjectPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->tenancy->member() !== null;
    }

    public function view(User $user, Project $project): bool
    {
        if (! $this->inActiveWorkspace($project)) {
            return false;
        }

        return Project::query()->visibleTo($user)->whereKey($project->id)->exists();
    }

    public function create(User $user): bool
    {
        return (bool) $this->tenancy->member()?->role->isManager();
    }

    public function update(User $user, Project $project): bool
    {
        return $this->inActiveWorkspace($project) && $this->create($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /**
     * Whether the user may add or change tasks inside this project.
     *
     * Wider than `update`: plain members work on their projects, they just
     * cannot change the project itself.
     */
    public function contribute(User $user, Project $project): bool
    {
        return $this->inActiveWorkspace($project) && $project->isEditableBy($user);
    }

    /**
     * Blocks reaching another tenant's project by changing the id in the URL (7.2 rule 5).
     */
    protected function inActiveWorkspace(Project $project): bool
    {
        return $project->workspace_id === $this->tenancy->id();
    }
}
