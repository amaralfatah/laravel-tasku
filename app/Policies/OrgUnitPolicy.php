<?php

namespace App\Policies;

use App\Models\OrgUnit;
use App\Models\User;
use App\Support\Tenancy;

/**
 * The org tree is platform master data imported from SAP: only the super admin
 * shapes it. A workspace leader reads the slice their workspace runs, which is
 * what the unit picker on the member and project pages searches through.
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
     * Shape the structure. Master data, so the operator alone writes it —
     * a leader placing someone in a unit is `WorkspaceMemberPolicy`, not this.
     */
    public function create(User $user): bool
    {
        return $user->is_super_admin;
    }

    public function createUnder(User $user, ?OrgUnit $parent): bool
    {
        return $this->create($user);
    }

    public function update(User $user, OrgUnit $orgUnit): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, OrgUnit $orgUnit): bool
    {
        return $this->create($user);
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
