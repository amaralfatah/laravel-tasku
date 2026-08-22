<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where a signed-in user lands.
 *
 * A super admin manages companies rather than working inside one, so sending
 * them into an arbitrary workspace would show data they never asked for. They
 * land on the workspace roster and pick a workspace deliberately; everyone
 * else lands on their own task list (MON-7).
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return $request->user()->is_super_admin
            ? to_route('workspaces.index')
            : to_route('monitoring.me');
    }
}
