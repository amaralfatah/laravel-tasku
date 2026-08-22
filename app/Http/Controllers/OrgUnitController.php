<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrgUnit\OrgUnitStoreRequest;
use App\Http\Requests\OrgUnit\OrgUnitUpdateRequest;
use App\Models\OrgUnit;
use App\Services\OrgUnitTree;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OrgUnitController extends Controller
{
    public function __construct(
        protected OrgUnitTree $tree,
        protected Tenancy $tenancy,
    ) {}

    /**
     * Structure page: the slice of the org unit tree the viewer runs.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', OrgUnit::class);

        $viewer = $this->tenancy->member();

        // `viewAny` already guarantees the viewer leads someone, so a viewer
        // without full scope always has a unit to start from.
        $scopePath = $viewer?->hasFullScope() ? null : $viewer?->scopePath();

        return Inertia::render('organization/index', [
            'units' => OrgUnit::query()
                ->withCount(['children', 'projects', 'assignedMembers'])
                ->when($scopePath !== null, fn ($query) => $query->where('path', 'like', $scopePath.'%'))
                ->orderBy('path')
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
                ->all(),
            'maxDepth' => OrgUnit::MAX_DEPTH,
            'can' => [
                'manage' => request()->user()->can('create', OrgUnit::class),
            ],
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
}
