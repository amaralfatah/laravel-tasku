<?php

namespace App\Http\Controllers;

use App\Actions\AddUserToWorkspace;
use App\Actions\ChangeMemberRole;
use App\Enums\WorkspaceRole;
use App\Http\Requests\User\MembershipStoreRequest;
use App\Http\Requests\User\MembershipUpdateRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A person's place in each workspace, edited by the platform operator (SA-6).
 *
 * This is the answer to a workspace whose Owner has gone: the operator hands
 * the role to somebody else, then demotes or removes the one who left. The
 * order matters and is enforced — {@see ChangeMemberRole} refuses to take the
 * last Owner away, because a workspace that has none can no longer administer
 * itself, and only this console could dig it back out.
 *
 * No tenant context and no `WorkspaceMemberPolicy`: a super admin holds no
 * membership to check a rank or a subtree against (SA-4). The middleware is
 * the whole gate.
 */
class UserMembershipController extends Controller
{
    /**
     * Put an account into a workspace (SA-6).
     */
    public function store(
        MembershipStoreRequest $request,
        User $user,
        AddUserToWorkspace $addUser,
    ): RedirectResponse {
        $workspace = Workspace::findOrFail($request->integer('workspace_id'));

        $addUser->handle(
            $workspace,
            $user,
            WorkspaceRole::from($request->validated('role')),
            $request->input('org_unit_id') === null ? null : $request->integer('org_unit_id'),
            $request->validated('title'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$user->name} ditambahkan ke {$workspace->name}.",
        ]);

        return back();
    }

    /**
     * Change the role, position or placement of one membership.
     */
    public function update(
        MembershipUpdateRequest $request,
        User $user,
        WorkspaceMember $member,
        ChangeMemberRole $changeRole,
    ): RedirectResponse {
        $this->guardOwnership($user, $member);

        $data = $request->validated();
        $role = array_key_exists('role', $data) ? WorkspaceRole::from($data['role']) : null;

        unset($data['role']);

        $member->update($data);

        if ($role !== null) {
            $changeRole->handle($member, $role);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Keanggotaan diperbarui.']);

        return back();
    }

    /**
     * Take an account out of a workspace.
     *
     * The last Owner stays put for the same reason they cannot be demoted, so
     * the operator has to appoint a successor first.
     */
    public function destroy(User $user, WorkspaceMember $member): RedirectResponse
    {
        $this->guardOwnership($user, $member);

        if (ChangeMemberRole::isLastTopRole($member)) {
            throw ValidationException::withMessages([
                'membership' => 'Workspace harus punya minimal satu Pemilik. Angkat penggantinya lebih dulu.',
            ]);
        }

        $workspaceName = $member->workspace->name;

        $member->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$user->name} dikeluarkan dari {$workspaceName}.",
        ]);

        return back();
    }

    /**
     * Both ids come off the URL, so the membership has to be the one this
     * account actually holds — otherwise editing `/users/1/memberships/9`
     * would reach somebody else's row.
     */
    protected function guardOwnership(User $user, WorkspaceMember $member): void
    {
        abort_unless($member->user_id === $user->id, 404);
    }
}
