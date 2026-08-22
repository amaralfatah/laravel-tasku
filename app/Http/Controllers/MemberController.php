<?php

namespace App\Http\Controllers;

use App\Concerns\PicksOrgUnits;
use App\Enums\WorkspaceRole;
use App\Http\Requests\Member\MemberUpdateRequest;
use App\Models\Invitation;
use App\Models\WorkspaceMember;
use App\Policies\WorkspaceMemberPolicy;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    use PicksOrgUnits;

    public function __construct(protected Tenancy $tenancy) {}

    /**
     * Members page: the roster plus outstanding invitations (ORG-6..ORG-12).
     *
     * Everything on this page is cut to the viewer's own subtree — the roster,
     * the units they may place someone in, and the roles they may hand out.
     */
    public function index(Request $request, WorkspaceMemberPolicy $policy): Response
    {
        $this->authorize('viewAny', WorkspaceMember::class);

        $viewer = $this->tenancy->member();
        $canManage = $request->user()->can('manage', WorkspaceMember::class);

        return Inertia::render('members/index', [
            'members' => $this->roster($viewer)
                ->map(fn (WorkspaceMember $member): array => [
                    'id' => $member->id,
                    'user' => [
                        'id' => $member->user->id,
                        'name' => $member->user->name,
                        'email' => $member->user->email,
                        'avatar' => $member->user->avatar,
                    ],
                    'role' => $member->role->value,
                    'role_label' => $member->role->label(),
                    'role_code' => $member->role->code(),
                    'org_unit' => $member->orgUnit?->only(['id', 'name']),
                    'manager_id' => $member->manager_id,
                    'is_last_top_role' => $policy->isLastTopRole($member),
                    'is_self' => $member->user_id === $request->user()->id,
                    'can_edit' => $request->user()->can('update', $member),
                    'can_change_role' => $request->user()->can('changeRole', $member),
                    'can_remove' => $request->user()->can('delete', $member),
                ])
                ->all(),
            'invitations' => $canManage ? $this->pendingInvitations() : [],
            'unitPicker' => $this->unitPicker($viewer),
            'roles' => array_map(
                fn (WorkspaceRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                    'code' => $role->code(),
                ],
                $viewer?->role->assignableRoles() ?? [],
            ),
            'can' => ['manage' => $canManage],
        ]);
    }

    /**
     * Update role and unit assignment (ORG-8, ORG-12).
     */
    public function update(MemberUpdateRequest $request, WorkspaceMember $member): RedirectResponse
    {
        $this->authorize('update', $member);

        $data = $request->validated();

        if (array_key_exists('role', $data) && $data['role'] !== $member->role->value) {
            $this->authorize('changeRole', $member);
        }

        // Moving someone out of the viewer's subtree would hand them away for
        // good, so the destination has to be covered as well.
        if (array_key_exists('org_unit_id', $data)) {
            abort_unless(
                (bool) $this->tenancy->member()?->covers($data['org_unit_id']),
                403,
            );
        }

        $member->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Anggota diperbarui.']);

        return back();
    }

    /**
     * Remove a member from the workspace (ORG-9).
     */
    public function destroy(Request $request, WorkspaceMember $member): RedirectResponse
    {
        $this->authorize('delete', $member);

        if ($member->user_id === $request->user()->id) {
            throw ValidationException::withMessages([
                'member' => 'Anda tidak bisa mengeluarkan diri sendiri dari workspace.',
            ]);
        }

        $member->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Anggota dikeluarkan dari workspace.']);

        return back();
    }

    /**
     * People the viewer leads, plus the viewer themselves.
     *
     * @return Collection<int, WorkspaceMember>
     */
    protected function roster(?WorkspaceMember $viewer): Collection
    {
        if ($viewer === null) {
            return collect();
        }

        $query = WorkspaceMember::query()
            ->with(['user:id,name,email,avatar_path', 'orgUnit:id,name'])
            ->join('users', 'users.id', '=', 'workspace_members.user_id')
            ->orderBy('users.name')
            ->select('workspace_members.*');

        $scopePath = $viewer->managesTeam() ? $viewer->scopePath() : null;

        if (! $viewer->hasFullScope()) {
            $query->where(function (Builder $inner) use ($viewer, $scopePath): void {
                $inner->where('workspace_members.user_id', $viewer->user_id);

                if ($scopePath !== null) {
                    $inner->orWhereHas(
                        'orgUnit',
                        fn (Builder $unit) => $unit->where('path', 'like', $scopePath.'%'),
                    );
                }
            });
        }

        return $query->get();
    }

    /**
     * Invitations that are still open, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function pendingInvitations(): array
    {
        return Invitation::query()
            ->with('inviter:id,name')
            ->whereNull('accepted_at')
            ->latest('id')
            ->get()
            ->map(fn (Invitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_label' => $invitation->role->label(),
                'token' => $invitation->token,
                'accept_url' => route('invitation.show', $invitation->token),
                'expires_at' => $invitation->expires_at->timezone('Asia/Jakarta')->format('d M Y'),
                'is_expired' => $invitation->isExpired(),
                'invited_by' => $invitation->inviter?->name,
            ])
            ->all();
    }
}
