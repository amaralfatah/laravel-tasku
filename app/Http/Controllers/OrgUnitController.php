<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrgUnit\OrgUnitStoreRequest;
use App\Http\Requests\OrgUnit\OrgUnitUpdateRequest;
use App\Models\OrgUnit;
use App\Models\WorkspaceMember;
use App\Services\OrgUnitTree;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tree is served one level at a time. The SAP import puts tens of
 * thousands of units in a workspace, so shipping the whole structure in the
 * page props would stall both the query and the browser; `children()` fills a
 * branch in when the viewer actually opens it, and `search()` is how anyone
 * reaches a unit buried eleven levels down.
 */
class OrgUnitController extends Controller
{
    /**
     * Units returned by one search call. Enough to pick from, small enough to
     * render in a dropdown.
     */
    protected const SEARCH_LIMIT = 30;

    public function __construct(
        protected OrgUnitTree $tree,
        protected Tenancy $tenancy,
    ) {}

    /**
     * Structure page, opened at the top of the slice the viewer runs.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', OrgUnit::class);

        return Inertia::render('organization/index', [
            'units' => $this->level(null),
            'maxDepth' => OrgUnit::MAX_DEPTH,
            'can' => [
                'manage' => request()->user()->can('create', OrgUnit::class),
            ],
        ]);
    }

    /**
     * Direct children of one unit, fetched when its branch is opened.
     */
    public function children(OrgUnit $orgUnit): JsonResponse
    {
        $this->authorize('view', $orgUnit);

        return response()->json(['units' => $this->level($orgUnit)]);
    }

    /**
     * Find a unit by name anywhere inside the viewer's scope.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OrgUnit::class);

        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['units' => []]);
        }

        $units = OrgUnit::query()
            ->tap(fn (Builder $query) => $this->withinScope($query, $this->tenancy->member()))
            // `like` is case sensitive on Postgres, so both sides are lowered.
            ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($term).'%'])
            ->orderBy('depth')
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get(['id', 'parent_id', 'name', 'type', 'depth', 'path']);

        $ancestors = $this->ancestorNames($units->pluck('path')->all());

        return response()->json([
            'units' => $units->map(fn (OrgUnit $unit): array => [
                'id' => $unit->id,
                'parent_id' => $unit->parent_id,
                'name' => $unit->name,
                'type' => $unit->type,
                'depth' => $unit->depth,
                'path' => $unit->path,
                'trail' => $this->trail($unit, $ancestors),
            ])->all(),
        ]);
    }

    public function store(OrgUnitStoreRequest $request): RedirectResponse
    {
        $parent = $request->parent();

        $this->authorize('createUnder', [OrgUnit::class, $parent]);

        $this->tree->create($request->safe()->only(['name', 'type']), $parent);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Unit ditambahkan.']);

        return back();
    }

    /**
     * Rename a unit and, when `parent_id` is present, move it (ORG-5).
     */
    public function update(OrgUnitUpdateRequest $request, OrgUnit $orgUnit): RedirectResponse
    {
        $this->authorize('update', $orgUnit);

        $orgUnit->update($request->safe()->only(['name', 'type']));

        if ($request->has('parent_id')) {
            $parent = $request->parent();

            // Moving a unit is also placing it: the destination has to sit
            // inside the mover's own scope.
            $this->authorize('createUnder', [OrgUnit::class, $parent]);

            $this->tree->move($orgUnit, $parent);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Unit diperbarui.']);

        return back();
    }

    public function destroy(OrgUnit $orgUnit): RedirectResponse
    {
        $this->authorize('delete', $orgUnit);

        $this->tree->guardDeletable($orgUnit);
        $orgUnit->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Unit dihapus.']);

        return back();
    }

    /**
     * One level of the tree: the children of `$parent`, or the entry point of
     * the viewer's slice when there is no parent.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function level(?OrgUnit $parent): array
    {
        $viewer = $this->tenancy->member();

        return OrgUnit::query()
            ->withCount(['children', 'projects', 'assignedMembers'])
            ->when(
                $parent !== null,
                fn (Builder $query) => $query->where('parent_id', $parent->id),
                // `viewAny` already guarantees the viewer leads someone, so a
                // viewer without full scope always has a unit to start from.
                fn (Builder $query) => $viewer === null || $viewer->hasFullScope()
                    ? $query->whereNull('parent_id')
                    : $query->whereKey($viewer->org_unit_id),
            )
            ->orderBy('name')
            ->get()
            ->map(fn (OrgUnit $unit): array => [
                'id' => $unit->id,
                'parent_id' => $unit->parent_id,
                'name' => $unit->name,
                'type' => $unit->type,
                'depth' => $unit->depth,
                'path' => $unit->path,
                'children_count' => $unit->children_count,
                'projects_count' => $unit->projects_count,
                'members_count' => $unit->assigned_members_count,
            ])
            ->all();
    }

    /**
     * Restrict a query to the branch the member runs. BOD-1 covers the whole
     * workspace and is left unfiltered.
     *
     * @param  Builder<OrgUnit>  $query
     */
    protected function withinScope(Builder $query, ?WorkspaceMember $member): void
    {
        $scopePath = $member?->hasFullScope() ? null : $member?->scopePath();

        if ($scopePath !== null) {
            $query->where('path', 'like', $scopePath.'%');
        }
    }

    /**
     * Names of every unit appearing in the given materialized paths, so a
     * search hit can show where it sits without a query per result.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    protected function ancestorNames(array $paths): array
    {
        $ids = [];

        foreach ($paths as $path) {
            foreach (array_filter(explode('/', $path), 'strlen') as $id) {
                $ids[(int) $id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return OrgUnit::query()
            ->whereKey(array_keys($ids))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The unit's ancestors, top down, excluding the unit itself.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    protected function trail(OrgUnit $unit, array $names): array
    {
        $ids = array_map('intval', array_filter(explode('/', $unit->path), 'strlen'));

        return array_values(array_filter(array_map(
            fn (int $id): ?string => $id === $unit->id ? null : ($names[$id] ?? null),
            $ids,
        )));
    }
}
