<?php

namespace App\Http\Controllers;

use App\Actions\StartWorkspace;
use App\Http\Middleware\EnsureWorkspaceAccess;
use App\Http\Requests\Workspace\WorkspaceStartRequest;
use App\Models\Workspace;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceContextController extends Controller
{
    /**
     * Switch the active workspace, provided the user is still a member.
     */
    public function change(Request $request, Workspace $workspace, WorkspaceAccess $access): RedirectResponse
    {
        // Membership is the only way in, super admin included: they operate the
        // platform and never a workspace (SA-4). A group director's membership
        // in the holding counts here, projected into the company they pick.
        abort_unless(
            $workspace->is_active && $access->memberships($request->user())->has($workspace->id),
            403,
        );

        $request->session()->put(EnsureWorkspaceAccess::SESSION_KEY, $workspace->id);

        return to_route('monitoring.me');
    }

    /**
     * Shown when the user belongs to no active workspace yet.
     */
    public function none(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('workspace/none', [
            'isSuperAdmin' => (bool) $user?->is_super_admin,
            // Someone who has never been invited anywhere may start their own
            // workspace; someone whose only workspace was switched off may not
            // — that is an administrative state, not a missing account.
            'canStart' => $user !== null
                && ! $user->is_super_admin
                && $user->workspaceMembers()->withoutGlobalScopes()->doesntExist(),
        ]);
    }

    /**
     * Start a workspace for someone who belongs to none (self-serve signup).
     */
    public function start(WorkspaceStartRequest $request, StartWorkspace $action): RedirectResponse
    {
        $workspace = $action->handle($request->user(), $request->validated('name'));

        $request->session()->put(EnsureWorkspaceAccess::SESSION_KEY, $workspace->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Workspace {$workspace->name} siap dipakai.",
        ]);

        return to_route('projects.index');
    }
}
