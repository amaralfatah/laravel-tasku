<?php

namespace App\Concerns;

use App\Models\OrgUnit;
use App\Models\WorkspaceMember;

/**
 * Describes the org unit picker a page should render.
 *
 * A workspace fed by the SAP import holds tens of thousands of units, so no
 * page may ship the list of placements to choose from. It sends the natural
 * default — the viewer's own unit — and a flag saying whether the viewer may
 * search for another one; the search itself runs through
 * `OrgUnitController::search`, which applies the same scope.
 */
trait PicksOrgUnits
{
    /**
     * @return array{default: array{id: int, name: string}|null, can_choose: bool}
     */
    protected function unitPicker(?WorkspaceMember $viewer): array
    {
        $default = $viewer?->org_unit_id === null
            ? null
            : OrgUnit::query()->whereKey($viewer->org_unit_id)->first(['id', 'name']);

        return [
            'default' => $default?->only(['id', 'name']),
            // Mirrors the org unit search endpoint: someone who leads nobody
            // has nothing to search through and no placement to choose.
            'can_choose' => (bool) $viewer?->leadsAnyone(),
        ];
    }
}
