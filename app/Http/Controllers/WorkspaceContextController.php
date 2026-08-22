<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureWorkspaceAccess;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceContextController extends Controller
{
    /**
     * Switch the active workspace, provided the user is still a member.
     */
    public function change(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $request->user();

        // A super admin belongs to no workspace but may open any active one.
        abort_unless(
            $workspace->is_active
            && ($user->is_super_admin || $user->membershipIn($workspace) !== null),
            403,
        );

        $request->session()->put(EnsureWorkspaceAccess::SESSION_KEY, $workspace->id);

        // Straight into the workspace that was just picked, rather than back
        // through the landing page, which would bounce a super admin out to
        // the workspace roster they came from.
        return to_route('monitoring.me');
    }

    /**
     * Shown when the user belongs to no active workspace yet.
     */
    public function none(Request $request): Response
    {
        return Inertia::render('workspace/none', [
            'isSuperAdmin' => (bool) $request->user()?->is_super_admin,
        ]);
    }
}
