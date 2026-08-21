<?php

namespace App\Policies;

use App\Models\OrgUnit;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Org units and positions are structural: only Owner and Admin may change
 * them, while every member of the workspace can read the tree (7.1).
 */
class OrgUnitPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->tenancy->member() !== null;
    }

    public function view(User $user, OrgUnit $orgUnit): bool
    {
        return $this->belongsToActiveWorkspace($orgUnit) && $this->tenancy->member() !== null;
    }

    public function create(User $user): bool
    {
        return (bool) $this->tenancy->member()?->role->isManager();
    }

    public function update(User $user, OrgUnit $orgUnit): bool
    {
        return $this->belongsToActiveWorkspace($orgUnit) && $this->create($user);
    }

    public function delete(User $user, OrgUnit $orgUnit): bool
    {
        return $this->update($user, $orgUnit);
    }

    /**
     * Guards against reaching another tenant's row by changing the id in the URL (7.2 rule 5).
     */
    protected function belongsToActiveWorkspace(OrgUnit $orgUnit): bool
    {
        return $orgUnit->workspace_id === $this->tenancy->id();
    }
}
