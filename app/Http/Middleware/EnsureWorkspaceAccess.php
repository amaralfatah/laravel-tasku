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
 */
class EnsureWorkspaceAccess
{
    public const SESSION_KEY = 'workspace_id';

    public function __construct(protected Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        $member = $this->resolveMembership($request);

        if ($member === null) {
            return redirect()->route('workspace.none');
        }

        $workspace = $member->workspace;

        if (! $workspace->is_active) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('workspace.none');
        }

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
            ->filter(fn ($member) => $member->workspace?->is_active);

        $sessionId = $request->session()->get(self::SESSION_KEY);

        return $memberships->firstWhere('workspace_id', $sessionId)
            ?? $memberships->first();
    }
}
