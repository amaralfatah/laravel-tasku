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
        // Membership is the only way in, super admin included: they operate the
        // platform and never a workspace (SA-4).
        abort_unless(
            $workspace->is_active && $request->user()->membershipIn($workspace) !== null,
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
        return Inertia::render('workspace/none', [
            'isSuperAdmin' => (bool) $request->user()?->is_super_admin,
        ]);
    }
}
