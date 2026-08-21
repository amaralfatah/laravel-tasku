<?php

namespace App\Http\Controllers;

use App\Enums\ScopeType;
use App\Enums\WorkspaceRole;
use App\Http\Requests\Member\MemberUpdateRequest;
use App\Models\Invitation;
use App\Models\OrgUnit;
use App\Models\WorkspaceMember;
use App\Policies\WorkspaceMemberPolicy;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function __construct(protected Tenancy $tenancy) {}

    /**
     * Members page: the roster plus outstanding invitations (ORG-6..ORG-12).
     */
    public function index(Request $request, WorkspaceMemberPolicy $policy): Response
    {
        $this->authorize('viewAny', WorkspaceMember::class);

        $canManage = $request->user()->can('manage', WorkspaceMember::class);

        return Inertia::render('members/index', [
            'members' => WorkspaceMember::query()
                ->with(['user:id,name,email,avatar_path', 'orgUnit:id,name', 'scopeOrgUnit:id,name'])
                ->join('users', 'users.id', '=', 'workspace_members.user_id')
                ->orderBy('users.name')
                ->select('workspace_members.*')
                ->get()
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
                    'org_unit' => $member->orgUnit?->only(['id', 'name']),
                    'scope_type' => $member->scope_type->value,
                    'scope_org_unit' => $member->scopeOrgUnit?->only(['id', 'name']),
                    'manager_id' => $member->manager_id,
                    'is_last_owner' => $policy->isLastOwner($member),
                    'is_self' => $member->user_id === $request->user()->id,
                ])
                ->all(),
            'invitations' => $canManage ? $this->pendingInvitations() : [],
            'orgUnits' => OrgUnit::query()
                ->orderBy('path')
                ->get(['id', 'name', 'depth'])
                ->map(fn (OrgUnit $unit): array => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'depth' => $unit->depth,
                ])
                ->all(),
            'roles' => array_map(
                fn (WorkspaceRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                WorkspaceRole::cases(),
            ),
            'scopeTypes' => array_map(
                fn (ScopeType $scope): array => ['value' => $scope->value, 'label' => $scope->label()],
                ScopeType::cases(),
            ),
            'can' => [
                'manage' => $canManage,
                'change_role' => $this->tenancy->member()?->role === WorkspaceRole::Owner,
            ],
        ]);
    }

    /**
     * Update role, unit assignment and monitoring scope (ORG-8, ORG-12).
     */
    public function update(MemberUpdateRequest $request, WorkspaceMember $member): RedirectResponse
    {
        $this->authorize('update', $member);

        $data = $request->validated();

        if (array_key_exists('role', $data) && $data['role'] !== $member->role->value) {
            $this->authorize('changeRole', $member);
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
