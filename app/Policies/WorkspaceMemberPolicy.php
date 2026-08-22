<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\Tenancy;

/**
 * Membership management (7.1).
 *
 * Every leader — BOD-1 through BOD-3 — invites, removes and places people, but
 * only inside their own subtree, and only at a role below their own. The last
 * BOD-1 can neither be demoted nor removed (7.2 rule 6).
 */
class WorkspaceMemberPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    /**
     * Open the member roster. Someone who leads nobody would see a one row
     * list of their own name, which "Task saya" already covers.
     */
    public function viewAny(User $user): bool
    {
        return (bool) $this->tenancy->member()?->leadsAnyone();
    }

    public function manage(User $user): bool
    {
        return (bool) $this->tenancy->member()?->managesTeam();
    }

    /**
     * Open the people roster (MON-1).
     */
    public function monitorPeople(User $user): bool
    {
        return (bool) $this->tenancy->member()?->leadsAnyone();
    }

    /**
     * Change someone else's role, within scope and not above the actor's rank.
     */
    public function changeRole(User $user, WorkspaceMember $member): bool
    {
        return $this->update($user, $member)
            && (bool) $this->tenancy->member()?->role->mayAssign($member->role)
            && ! $this->isLastTopRole($member);
    }

    public function update(User $user, WorkspaceMember $member): bool
    {
        $viewer = $this->tenancy->member();

        return $viewer !== null
            && $viewer->managesTeam()
            && $member->workspace_id === $this->tenancy->id()
            && $viewer->covers($member->org_unit_id);
    }

    public function delete(User $user, WorkspaceMember $member): bool
    {
        return $this->update($user, $member)
            && (bool) $this->tenancy->member()?->role->mayAssign($member->role)
            && ! $this->isLastTopRole($member);
    }

    /**
     * View another member's cross-project workload (MON-2, MON-6).
     *
     * Everyone can always open their own page; a leader sees the people placed
     * in the subtree they run.
     */
    public function viewMember(User $user, WorkspaceMember $target): bool
    {
        $viewer = $this->tenancy->member();

        if ($viewer === null || $target->workspace_id !== $this->tenancy->id()) {
            return false;
        }

        return $viewer->coversMember($target);
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
