<?php

namespace App\Http\Controllers;

use App\Actions\InviteToWorkspace;
use App\Enums\WorkspaceRole;
use App\Http\Requests\Workspace\WorkspaceStoreRequest;
use App\Http\Requests\Workspace\WorkspaceUpdateRequest;
use App\Models\Invitation;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace roster for the platform super admin (SA-1..SA-3).
 *
 * Lives inside the ordinary app shell rather than a separate panel: a super
 * admin also works inside workspaces, so a second chrome only split the
 * navigation in two. The routes resolve a workspace optionally, which keeps
 * this page reachable while no workspace exists yet.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->string('status')->toString();

        $workspaces = Workspace::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'ilike', "%{$search}%"))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->withCount('members')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'is_active' => $workspace->is_active,
                'members_count' => $workspace->members_count,
                'created_at' => $workspace->created_at->toDateString(),
                'owner' => $this->owner($workspace),
                'pending_owner_invite' => $this->pendingOwnerInvite($workspace),
            ]);

        return Inertia::render('workspaces/index', [
            'workspaces' => $workspaces,
            'filters' => ['search' => $search, 'status' => $status],
            'stats' => $this->stats(),
        ]);
    }

    /**
     * Headline counts for the operator, so the state of the platform is
     * readable without scanning the table.
     *
     * @return array{total: int, active: int, inactive: int, pending_owner: int}
     */
    protected function stats(): array
    {
        $active = Workspace::query()->where('is_active', true)->count();
        $inactive = Workspace::query()->where('is_active', false)->count();

        return [
            'total' => $active + $inactive,
            'active' => $active,
            'inactive' => $inactive,
            'pending_owner' => Invitation::withoutGlobalScopes()
                ->where('role', WorkspaceRole::Bod1)
                ->whereNull('accepted_at')
                ->distinct('workspace_id')
                ->count('workspace_id'),
        ];
    }

    /**
     * The workspace's Owner, once someone has accepted the invitation.
     *
     * @return array{name: string, email: string}|null
     */
    protected function owner(Workspace $workspace): ?array
    {
        $owner = WorkspaceMember::withoutGlobalScopes()
            ->with('user:id,name,email')
            ->where('workspace_id', $workspace->id)
            ->where('role', WorkspaceRole::Bod1)
            ->orderBy('id')
            ->first();

        return $owner?->user === null ? null : [
            'name' => $owner->user->name,
            'email' => $owner->user->email,
        ];
    }

    /**
     * Create a company and invite its first Owner (SA-2).
     */
    public function store(WorkspaceStoreRequest $request, InviteToWorkspace $inviter): RedirectResponse
    {
        $workspace = DB::transaction(fn (): Workspace => Workspace::create([
            'name' => $request->validated('name'),
        ]));

        $inviter->handle(
            $workspace,
            $request->validated('owner_email'),
            WorkspaceRole::Bod1,
            $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Workspace {$workspace->name} dibuat. Undangan Owner dikirim.",
        ]);

        return to_route('workspaces.index');
    }

    /**
     * Rename a company or toggle its active flag (SA-3).
     */
    public function update(WorkspaceUpdateRequest $request, Workspace $workspace): RedirectResponse
    {
        $workspace->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $workspace->is_active
                ? "Workspace {$workspace->name} diaktifkan."
                : "Workspace {$workspace->name} dinonaktifkan.",
        ]);

        return back();
    }

    /**
     * Send the Owner invitation again when the first one expired or got lost.
     */
    public function resendOwnerInvite(Request $request, Workspace $workspace, InviteToWorkspace $inviter): RedirectResponse
    {
        $pending = $this->pendingOwnerInvite($workspace);

        abort_if($pending === null, 404, 'Tidak ada undangan Owner yang tertunda.');

        $inviter->handle($workspace, $pending['email'], WorkspaceRole::Bod1, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Undangan Owner dikirim ulang.']);

        return back();
    }

    /**
     * The outstanding Owner invitation, if the workspace has no Owner yet.
     *
     * @return array{email: string, expires_at: string}|null
     */
    protected function pendingOwnerInvite(Workspace $workspace): ?array
    {
        $invitation = Invitation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('role', WorkspaceRole::Bod1)
            ->whereNull('accepted_at')
            ->latest('id')
            ->first();

        return $invitation === null ? null : [
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at->toDateString(),
        ];
    }
}
