<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Project access (7.1, 7.2).
 *
 * Viewing follows Project::visibleTo — project membership or a leader's own
 * subtree. Editing and deleting need someone who runs the project: a leader
 * whose scope covers its unit, or the person who created it.
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

    /**
     * Anyone with a place in the org tree may start a project; a leader has
     * their whole subtree to choose from, everyone else only gets their own
     * unit. Someone who has not been placed anywhere has nowhere to put one.
     */
    public function create(User $user): bool
    {
        $member = $this->tenancy->member();

        return $member !== null && ($member->managesTeam() || $member->org_unit_id !== null);
    }

    /**
     * Hang a project off this unit.
     */
    public function createIn(User $user, ?int $orgUnitId): bool
    {
        $member = $this->tenancy->member();

        if ($member === null || ! $this->create($user)) {
            return false;
        }

        return $member->managesTeam()
            ? $member->covers($orgUnitId)
            : $orgUnitId !== null && $orgUnitId === $member->org_unit_id;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->inActiveWorkspace($project) && $project->isAdministeredBy($user);
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
