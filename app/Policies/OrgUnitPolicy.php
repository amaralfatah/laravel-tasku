<?php

namespace App\Policies;

use App\Models\OrgUnit;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Org units are structural: a leader shapes the branch they run, and every
 * member of the workspace can read the tree (7.1).
 */
class OrgUnitPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    /**
     * Open the organisation page. Someone who leads nobody has no branch to
     * look at.
     */
    public function viewAny(User $user): bool
    {
        return (bool) $this->tenancy->member()?->leadsAnyone();
    }

    public function view(User $user, OrgUnit $orgUnit): bool
    {
        return $this->belongsToActiveWorkspace($orgUnit) && $this->tenancy->member() !== null;
    }

    /**
     * Opens the organisation page. Where a new unit may actually be hung is
     * decided per parent by `createUnder`.
     */
    public function create(User $user): bool
    {
        return (bool) $this->tenancy->member()?->managesTeam();
    }

    /**
     * Add a unit under this parent. A null parent means a new root, which only
     * BOD-1 covers.
     */
    public function createUnder(User $user, ?OrgUnit $parent): bool
    {
        if ($parent !== null && ! $this->belongsToActiveWorkspace($parent)) {
            return false;
        }

        return $this->create($user)
            && (bool) $this->tenancy->member()?->covers($parent?->id);
    }

    public function update(User $user, OrgUnit $orgUnit): bool
    {
        return $this->belongsToActiveWorkspace($orgUnit)
            && $this->create($user)
            && (bool) $this->tenancy->member()?->covers($orgUnit->id);
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
        return (bool) $this->tenancy->member()?->leadsAnyone();
    }

    /**
     * Drill into one org unit's summary.
     */
    public function monitorUnit(User $user, OrgUnit $orgUnit): bool
    {
        return $this->belongsToActiveWorkspace($orgUnit)
            && (bool) $this->tenancy->member()?->covers($orgUnit->id);
    }

    /**
     * Guards against reaching another tenant's row by changing the id in the URL (7.2 rule 5).
     */
    protected function belongsToActiveWorkspace(OrgUnit $orgUnit): bool
    {
        return $orgUnit->workspace_id === $this->tenancy->id();
    }
}
