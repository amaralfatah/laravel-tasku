<?php

namespace App\Policies;

use App\Models\OrgUnit;
use App\Models\User;
use App\Support\Tenancy;

/**
 * The org tree is one platform-wide structure, and two kinds of people write
 * to it.
 *
 * The operator owns the roots and everything mirrored from SAP: those rows
 * carry an `external_id`, a re-import overwrites them, and a customer editing
 * one would have their change silently reverted. Nobody but the super admin
 * touches them.
 *
 * Everything a customer draws themselves — a studio adding its first two
 * teams, a company adding a sub division — has a null `external_id` and is
 * theirs: an Owner or a Manager shapes it, inside the branch their own unit
 * gives them. That is what lets a workspace start with one node and grow its
 * own structure without an operator.
 */
class OrgUnitPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    /**
     * Read the tree: the whole of it for the operator, the branch they lead
     * for everyone else. Someone who leads nobody has nothing to look at.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_super_admin
            || (bool) $this->tenancy->member()?->leadsAnyone();
    }

    public function view(User $user, OrgUnit $orgUnit): bool
    {
        return $user->is_super_admin
            || ($this->belongsToActiveWorkspace($orgUnit) && $this->tenancy->member() !== null);
    }

    /**
     * Whether this person shapes the structure at all — the gate on the page's
     * own controls.
     */
    public function create(User $user): bool
    {
        return $user->is_super_admin || $this->leads();
    }

    /**
     * Hang a new unit under this parent.
     *
     * A root is an operating entity, which is the operator's to hand out: a
     * customer only ever grows the branch they were given.
     */
    public function createUnder(User $user, ?OrgUnit $parent): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $parent !== null
            && $this->leads()
            && $this->belongsToActiveWorkspace($parent)
            && (bool) $this->tenancy->member()?->covers($parent->id);
    }

    /**
     * Rename or retype a unit.
     *
     * The workspace's own root is excluded: it is the node the operator placed
     * the company on, and moving or renaming it would change what the whole
     * workspace is.
     */
    public function update(User $user, OrgUnit $orgUnit): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $this->isCustomerOwned($orgUnit)
            && $orgUnit->id !== $this->tenancy->workspace()?->root_org_unit_id;
    }

    public function delete(User $user, OrgUnit $orgUnit): bool
    {
        return $this->update($user, $orgUnit);
    }

    /**
     * Open the division monitoring page (DIV-6).
     */
    public function monitor(User $user): bool
    {
        return (bool) $this->tenancy->member()?->canObserve();
    }

    /**
     * Drill into one org unit's summary.
     */
    public function monitorUnit(User $user, OrgUnit $orgUnit): bool
    {
        return $this->belongsToActiveWorkspace($orgUnit)
            && (bool) $this->tenancy->member()?->readsUnit($orgUnit->id);
    }

    /**
     * A unit the customer drew and may therefore change: inside their scope,
     * and not a row SAP owns.
     */
    protected function isCustomerOwned(OrgUnit $orgUnit): bool
    {
        return $orgUnit->external_id === null
            && $this->leads()
            && $this->belongsToActiveWorkspace($orgUnit)
            && (bool) $this->tenancy->member()?->covers($orgUnit->id);
    }

    /**
     * Whether the active member leads a branch and may write at all.
     */
    protected function leads(): bool
    {
        $member = $this->tenancy->member();

        return $member !== null && $member->canWrite() && $member->leadsAnyone();
    }

    /**
     * Guards against reaching another company's branch by changing the id in
     * the URL (7.2 rule 5). Units are shared now, so the test is whether the
     * unit sits inside the workspace's own subtree.
     */
    protected function belongsToActiveWorkspace(OrgUnit $orgUnit): bool
    {
        $path = $this->tenancy->workspace()?->orgUnitRootPath();

        return $path !== null && str_starts_with($orgUnit->path, $path);
    }
}
