<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureWorkspaceAccess;
use App\Models\Workspace;
use App\Queries\GroupSummaryQuery;
use App\Support\Tenancy;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The consolidated view a holding gets over its operating companies.
 *
 * Read only by construction: it reports and links, and every figure on it is
 * an aggregate. Nothing here reaches into a company's tasks — following a link
 * switches the active workspace, and the ordinary policies take over there.
 */
class GroupController extends Controller
{
    public function __construct(
        protected Tenancy $tenancy,
        protected WorkspaceAccess $access,
    ) {}

    public function index(Request $request, GroupSummaryQuery $query): Response
    {
        $holding = $this->tenancy->workspace();
        $member = $this->tenancy->member();

        // Only a group-level role sees across entities, and only from the
        // holding itself: standing inside a subsidiary shows that subsidiary.
        abort_unless(
            $holding !== null
                && $member !== null
                && $holding->isHolding()
                && $member->readsEverything(),
            403,
        );

        $companies = $this->access->subsidiaries($holding);
        $rows = $query->forCompanies($companies);

        return Inertia::render('group/index', [
            'holding' => [
                'id' => $holding->id,
                'name' => $holding->name,
                'slug' => $holding->slug,
            ],
            'companies' => $rows,
            'totals' => $query->totals($rows),
            'can' => [
                // Everyone who reaches this page may step into a company; a
                // Viewer arrives there read-only, which is what the label says.
                'write' => $member->canWrite(),
            ],
        ]);
    }

    /**
     * Switch into one of the group's companies, then land on its projects.
     */
    public function enter(Request $request, Workspace $company): RedirectResponse
    {
        abort_unless(
            $company->is_active && $this->access->memberships($request->user())->has($company->id),
            403,
        );

        $request->session()->put(EnsureWorkspaceAccess::SESSION_KEY, $company->id);

        return to_route('projects.index');
    }
}
