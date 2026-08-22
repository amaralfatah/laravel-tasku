<?php

namespace App\Http\Middleware;

use App\Models\WorkspaceMember;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active workspace for the request and verifies membership.
 *
 * The workspace id lives in the session so it survives navigation. Every
 * request re-checks the membership row, so a revoked member loses access
 * immediately instead of at the next login.
 *
 * A super admin operates the platform and never a workspace (SA-4): they hold
 * no membership, real or virtual, and are sent back to the workspace roster
 * whenever they reach for a workspace page.
 *
 * The `optional` mode resolves the same context but never redirects. It is
 * used by pages that must stay reachable without a workspace — the settings
 * screens, which everyone including a super admin may open.
 */
class EnsureWorkspaceAccess
{
    public const SESSION_KEY = 'workspace_id';

    public const OPTIONAL = 'optional';

    public function __construct(protected Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $optional = $mode === self::OPTIONAL;
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if ($user->is_super_admin) {
            return $optional ? $next($request) : redirect()->route('workspaces.index');
        }

        $member = $this->resolveMembership($request);

        if ($member === null || ! $member->workspace->is_active) {
            $request->session()->forget(self::SESSION_KEY);

            return $optional ? $next($request) : redirect()->route('workspace.none');
        }

        $workspace = $member->workspace;

        $request->session()->put(self::SESSION_KEY, $workspace->id);
        $this->tenancy->set($workspace, $member);

        return $next($request);
    }

    /**
     * Membership for the session workspace, falling back to the first active one.
     */
    protected function resolveMembership(Request $request): ?WorkspaceMember
    {
        $memberships = $request->user()
            ->workspaceMembers()
            ->with('workspace')
            ->get()
            ->filter(fn (WorkspaceMember $member): bool => (bool) $member->workspace?->is_active);

        $sessionId = $request->session()->get(self::SESSION_KEY);

        return $memberships->firstWhere('workspace_id', $sessionId)
            ?? $memberships->first();
    }
}
