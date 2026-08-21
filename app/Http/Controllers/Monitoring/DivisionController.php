<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\OrgUnit;
use App\Queries\DivisionSummaryQuery;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Division monitoring with drill-down (6.11).
 */
class DivisionController extends Controller
{
    public function __construct(
        protected Tenancy $tenancy,
        protected DivisionSummaryQuery $summary,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('monitor', OrgUnit::class);

        // A scoped member always starts inside their own subtree, so the page
        // never shows anything above the scope they were granted.
        $unit = $this->resolveUnit($request) ?? $this->defaultRoot();

        if ($unit !== null) {
            $this->authorize('monitorUnit', $unit);
        }

        return Inertia::render('monitoring/divisions', [
            'units' => $this->summary->forChildrenOf($unit),
            'current' => $unit === null ? null : [
                'id' => $unit->id,
                'name' => $unit->name,
            ],
            'trail' => $this->summary->trail($unit),
        ]);
    }

    /**
     * The unit being drilled into, from `?unit=`.
     */
    protected function resolveUnit(Request $request): ?OrgUnit
    {
        $unitId = $request->integer('unit') ?: null;

        return $unitId === null ? null : OrgUnit::findOrFail($unitId);
    }

    /**
     * Where the tree starts for this viewer: the workspace roots for a
     * manager, the granted unit for a scoped member.
     */
    protected function defaultRoot(): ?OrgUnit
    {
        $member = $this->tenancy->member();

        if ($member === null || $member->role->isManager()) {
            return null;
        }

        return OrgUnit::query()->find($member->scope_org_unit_id);
    }
}
