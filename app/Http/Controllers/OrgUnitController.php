<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrgUnit\OrgUnitStoreRequest;
use App\Http\Requests\OrgUnit\OrgUnitUpdateRequest;
use App\Models\OrgUnit;
use App\Services\OrgUnitTree;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OrgUnitController extends Controller
{
    public function __construct(protected OrgUnitTree $tree) {}

    /**
     * Structure page: the org unit tree.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', OrgUnit::class);

        return Inertia::render('organization/index', [
            'units' => OrgUnit::query()
                ->withCount(['children', 'projects', 'assignedMembers'])
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
        $this->authorize('create', OrgUnit::class);

        $this->tree->create(
            $request->safe()->only(['name', 'type']),
            $request->parent(),
        );

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
            $this->tree->move($orgUnit, $request->parent());
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
