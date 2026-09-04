<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy;

/**
 * Who owns what about a workspace.
 *
 * Two different things live on this row and they belong to two different
 * people. Where the workspace sits — `root_org_unit_id`, the holding above it,
 * whether it is switched on at all — is the operator's, decided when the
 * entity was handed out, and stays on the super admin routes in
 * `routes/workspaces.php`. What the workspace is called and the mark it goes
 * by is the customer's own identity, the same way a Jira site is renamed by
 * the org admin who created it rather than by Atlassian.
 *
 * So there is no `update` here, only `manageIdentity`: an ability narrow
 * enough that it cannot grow into the operator's half by accident.
 */
class WorkspacePolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    /**
     * Name and logo of the workspace one is standing in. Full scope means the
     * whole entity, which is exactly what an identity covers — a Manager runs
     * a branch, and renaming the company from there would be a reach past
     * their slice.
     */
    public function manageIdentity(User $user, Workspace $workspace): bool
    {
        return $workspace->id === $this->tenancy->id()
            && (bool) $this->tenancy->member()?->hasFullScope();
    }
}
