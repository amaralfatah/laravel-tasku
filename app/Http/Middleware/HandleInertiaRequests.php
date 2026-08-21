<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
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
     * @return array{workspace: array<string, mixed>|null, membership: array<string, mixed>|null, workspaces: array<int, array<string, mixed>>}
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
                'role_label' => $tenancy->actingAsSuperAdmin()
                    ? 'Super Admin'
                    : $member->role->label(),
                'scope_type' => $member->scope_type->value,
                'can_manage' => $member->role->isManager(),
                'can_monitor_division' => $member->role->isManager() || $member->monitorsSubtree(),
                'is_super_admin' => $tenancy->actingAsSuperAdmin(),
            ],
            'workspaces' => $this->switchableWorkspaces($request),
        ];
    }

    /**
     * Workspaces the switcher may offer.
     *
     * A super admin belongs to none of them but may open any, so they get the
     * full list instead of their own memberships.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function switchableWorkspaces(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        $query = $user->is_super_admin
            ? Workspace::query()
            : $user->workspaces();

        return $query
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
