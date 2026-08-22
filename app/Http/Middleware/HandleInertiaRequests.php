<?php

namespace App\Http\Middleware;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * How many projects the sidebar lists before pointing at the full index.
     */
    protected const SIDEBAR_PROJECT_LIMIT = 6;

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'tenancy' => fn (): array => $this->tenancyProps($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Active workspace, membership and the list used by the workspace switcher.
     *
     * @return array{workspace: array<string, mixed>|null, membership: array<string, mixed>|null, workspaces: array<int, array<string, mixed>>, projects: array<int, array<string, mixed>>}
     */
    protected function tenancyProps(Request $request): array
    {
        $tenancy = app(Tenancy::class);
        $workspace = $tenancy->workspace();
        $member = $tenancy->member();

        return [
            'workspace' => $workspace === null ? null : [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'membership' => $member === null ? null : [
                'role' => $member->role->value,
                'role_label' => $member->role->label(),
                'role_code' => $member->role->code(),
                'can_manage' => $member->managesTeam(),
                // Someone who leads nobody has no use for the roster or the
                // monitoring pages; "Task saya" already is that page for them.
                'can_monitor' => $member->leadsAnyone(),
            ],
            'workspaces' => $this->switchableWorkspaces($request),
            'projects' => $workspace === null ? [] : $this->sidebarProjects($request),
        ];
    }

    /**
     * The projects the sidebar lists, in a fixed order.
     *
     * Sorted by name rather than by recency: a navigation that reshuffles as
     * projects are touched costs the reader the position they had memorised.
     * The project being viewed is appended when the limit left it out, so the
     * sidebar never goes blank on a page that clearly belongs to a project.
     *
     * @return array<int, array{id: int, name: string}>
     */
    protected function sidebarProjects(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        $projects = Project::query()
            ->visibleTo($user)
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->orderBy('name')
            ->limit(self::SIDEBAR_PROJECT_LIMIT)
            ->get(['id', 'name']);

        $currentId = $this->currentProjectId($request);

        if ($currentId !== null && ! $projects->contains('id', $currentId)) {
            $current = Project::query()
                ->visibleTo($user)
                ->whereKey($currentId)
                ->first(['id', 'name']);

            if ($current !== null) {
                $projects = $projects->push($current)->sortBy('name')->values();
            }
        }

        return $projects
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
            ])
            ->all();
    }

    /**
     * The project the current route points at, if any.
     *
     * Inertia shares props before the router substitutes bindings, so the
     * route parameter is still the raw key from the URL here.
     */
    protected function currentProjectId(Request $request): ?int
    {
        $parameter = $request->route('project');

        if ($parameter instanceof Project) {
            return $parameter->id;
        }

        return is_numeric($parameter) ? (int) $parameter : null;
    }

    /**
     * Workspaces the switcher may offer: the user's own memberships. A super
     * admin belongs to none and manages them from the roster instead (SA-4).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function switchableWorkspaces(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return $user->workspaces()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug'])
            ->map(fn ($item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
            ])
            ->all();
    }
}
