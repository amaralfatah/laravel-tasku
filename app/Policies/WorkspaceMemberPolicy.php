<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\Tenancy;

/**
 * Membership management (7.1).
 *
 * Owner and Admin invite, remove and set scope; only an Owner may change
 * someone's role, and the last Owner can neither be demoted nor removed
 * (7.2 rule 6).
 */
class WorkspaceMemberPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->tenancy->member() !== null;
    }

    public function manage(User $user): bool
    {
        return (bool) $this->tenancy->member()?->role->isManager();
    }

    /**
     * Changing a role is an Owner-only action.
     */
    public function changeRole(User $user, WorkspaceMember $member): bool
    {
        return $this->tenancy->member()?->role === WorkspaceRole::Owner
            && $member->workspace_id === $this->tenancy->id()
            && ! $this->isLastOwner($member);
    }

    public function update(User $user, WorkspaceMember $member): bool
    {
        return $this->manage($user) && $member->workspace_id === $this->tenancy->id();
    }

    public function delete(User $user, WorkspaceMember $member): bool
    {
        return $this->update($user, $member) && ! $this->isLastOwner($member);
    }

    /**
     * A workspace must always keep at least one Owner.
     */
    public function isLastOwner(WorkspaceMember $member): bool
    {
        if ($member->role !== WorkspaceRole::Owner) {
            return false;
        }

        return WorkspaceMember::query()
            ->where('role', WorkspaceRole::Owner)
            ->where('id', '!=', $member->id)
            ->doesntExist();
    }
}
