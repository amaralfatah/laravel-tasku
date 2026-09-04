<?php

namespace App\Policies;

use App\Models\Requester;
use App\Models\User;
use App\Support\Tenancy;

/**
 * The requester list is workspace master data, and it is read far more widely
 * than it is written.
 *
 * Reading is everyone's: the picker on the task form needs the list, and a
 * Viewer reading a report needs the names on it. Writing is a leader's, the
 * same bar `OrgUnitPolicy` puts on the structure — a Manager already shapes
 * org units, starts projects and places people, so adding a requester is not
 * the thing to send them to the Owner for.
 *
 * Unlike an org unit, a requester has no place in the tree, so there is no
 * `covers()` check here: the list is flat and belongs to the whole workspace.
 * If a workspace ever needs one division's requesters kept out of another's
 * picker, the row needs an `org_unit_id` first — do not fake it with a role.
 */
class RequesterPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    /**
     * Anyone inside the workspace reads the list.
     */
    public function viewAny(User $user): bool
    {
        return $this->tenancy->member() !== null;
    }

    /**
     * Open the management page and everything on it. `managesTeam()` is Owner
     * and Manager; it already excludes a Viewer, who writes nothing anywhere.
     */
    public function manage(User $user): bool
    {
        return (bool) $this->tenancy->member()?->managesTeam();
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Requester $requester): bool
    {
        return $requester->workspace_id === $this->tenancy->id()
            && $this->manage($user);
    }

    public function delete(User $user, Requester $requester): bool
    {
        return $this->update($user, $requester);
    }
}
