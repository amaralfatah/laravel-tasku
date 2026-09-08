<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\WorkspaceMember;
use Illuminate\Validation\ValidationException;

/**
 * Moves a membership to another role, keeping the one invariant a workspace
 * cannot survive without: it must always have at least one Owner (7.2 rule 6).
 *
 * Two callers with two different gatekeepers share this. Inside a workspace,
 * `MemberController` runs `WorkspaceMemberPolicy` first, so the actor's rank
 * and subtree are already checked. From the operator console there is no
 * membership to check against at all — a super admin belongs to no workspace
 * (SA-4) — so the rule here is the only thing standing between them and a
 * workspace nobody can administer.
 *
 * Every query is unscoped and filtered on `workspace_id` by hand, because the
 * operator runs without tenant context and `WorkspaceScope` would return
 * nothing for them.
 */
class ChangeMemberRole
{
    /**
     * @throws ValidationException when this would leave the workspace ownerless
     */
    public function handle(WorkspaceMember $member, WorkspaceRole $role): WorkspaceMember
    {
        if ($member->role === $role) {
            return $member;
        }

        if (static::isLastTopRole($member)) {
            throw ValidationException::withMessages([
                'role' => 'Workspace harus punya minimal satu Pemilik. Angkat penggantinya lebih dulu.',
            ]);
        }

        $member->update(['role' => $role]);

        return $member;
    }

    /**
     * Whether this membership is the only Owner its workspace has left, and so
     * may be neither demoted nor removed.
     *
     * Static because the roster reads it per row to mark what is locked, well
     * before anybody submits a change.
     */
    public static function isLastTopRole(WorkspaceMember $member): bool
    {
        if (! $member->role->isTop()) {
            return false;
        }

        return WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $member->workspace_id)
            ->where('role', WorkspaceRole::Owner)
            ->whereKeyNot($member->getKey())
            ->doesntExist();
    }
}
