<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the platform operator area (SA-1..SA-4).
 *
 * These routes never resolve a workspace, so a super admin cannot read
 * project or task data through them.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_super_admin, 403);

        return $next($request);
    }
}
