<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\Tenancy;

/**
 * Membership management (7.1).
 *
 * BOD-1 and BOD-2 invite, remove and set scope; only BOD-1 may change
 * someone's role, and the last BOD-1 can neither be demoted nor removed
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
     * Open the people roster (MON-1).
     *
     * Someone whose scope covers nobody but themselves is kept out: for them
     * the roster is a one row list of their own name, which "Task saya"
     * already shows in full.
     */
    public function monitorPeople(User $user): bool
    {
        $viewer = $this->tenancy->member();

        return $viewer !== null && ($viewer->role->isManager() || $viewer->monitorsSubtree());
    }

    /**
     * Changing a role is an Owner-only action.
     */
    public function changeRole(User $user, WorkspaceMember $member): bool
    {
        return (bool) $this->tenancy->member()?->role->isTop()
            && $member->workspace_id === $this->tenancy->id()
            && ! $this->isLastTopRole($member);
    }

    public function update(User $user, WorkspaceMember $member): bool
    {
        return $this->manage($user) && $member->workspace_id === $this->tenancy->id();
    }

    public function delete(User $user, WorkspaceMember $member): bool
    {
        return $this->update($user, $member) && ! $this->isLastTopRole($member);
    }

    /**
     * View another member's cross-project workload (MON-2, MON-6).
     *
     * Everyone can always open their own page; managers see the whole
     * workspace, and a member with a subtree scope sees the people placed in
     * that subtree.
     */
    public function viewMember(User $user, WorkspaceMember $target): bool
    {
        $viewer = $this->tenancy->member();

        if ($viewer === null || $target->workspace_id !== $this->tenancy->id()) {
            return false;
        }

        return $viewer->user_id === $target->user_id
            || $viewer->role->isManager()
            || $viewer->scopeCoversUnit($target->org_unit_id);
    }

    /**
     * A workspace must always keep at least one BOD-1.
     */
    public function isLastTopRole(WorkspaceMember $member): bool
    {
        if (! $member->role->isTop()) {
            return false;
        }

        return WorkspaceMember::query()
            ->where('role', WorkspaceRole::Bod1)
            ->where('id', '!=', $member->id)
            ->doesntExist();
    }
}
