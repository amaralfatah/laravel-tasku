<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Validation\ValidationException;

/**
 * Places an existing account in a workspace without going through an
 * invitation.
 *
 * The invitation flow proves the person holds the mailbox, which is what it is
 * for. The operator console needs no such proof — it is the platform operator
 * placing an account they administer — but it does need the same two refusals
 * the rest of the application makes.
 *
 * Runs without tenant context, so `workspace_id` is set by hand rather than
 * filled from `Tenancy` and every query drops the global scopes.
 */
class AddUserToWorkspace
{
    /**
     * @throws ValidationException when the account may not hold a membership
     */
    public function handle(
        Workspace $workspace,
        User $user,
        WorkspaceRole $role,
        ?int $orgUnitId = null,
        ?string $title = null,
    ): WorkspaceMember {
        // SA-4 closed from this end too: a super admin operates the platform
        // and belongs to no company, so handing them a membership would give
        // them a tenant role the policies then honour.
        if ($user->is_super_admin) {
            throw ValidationException::withMessages([
                'user_id' => 'Super admin tidak bisa menjadi anggota workspace.',
            ]);
        }

        $alreadyMember = WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'workspace_id' => "{$user->name} sudah menjadi anggota {$workspace->name}.",
            ]);
        }

        $member = new WorkspaceMember([
            'user_id' => $user->id,
            'role' => $role,
            'title' => $title,
            'org_unit_id' => $orgUnitId,
            'joined_at' => now(),
        ]);

        $member->workspace_id = $workspace->id;
        $member->save();

        return $member;
    }
}
