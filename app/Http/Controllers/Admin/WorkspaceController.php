<?php

namespace App\Http\Controllers\Admin;

use App\Actions\InviteToWorkspace;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WorkspaceStoreRequest;
use App\Http\Requests\Admin\WorkspaceUpdateRequest;
use App\Models\Invitation;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform operator area (SA-1..SA-4).
 *
 * These routes never resolve an active workspace, so the operator can create
 * and suspend companies without being able to read their projects or tasks.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $workspaces = Workspace::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'ilike', "%{$search}%"))
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
                'pending_owner_invite' => $this->pendingOwnerInvite($workspace),
            ]);

        return Inertia::render('admin/workspaces/index', [
            'workspaces' => $workspaces,
            'filters' => ['search' => $search],
        ]);
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
            WorkspaceRole::Owner,
            $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Workspace {$workspace->name} dibuat. Undangan Owner dikirim.",
        ]);

        return to_route('admin.workspaces.index');
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

        $inviter->handle($workspace, $pending['email'], WorkspaceRole::Owner, $request->user());

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
            ->where('role', WorkspaceRole::Owner)
            ->whereNull('accepted_at')
            ->latest('id')
            ->first();

        return $invitation === null ? null : [
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at->toDateString(),
        ];
    }
}
