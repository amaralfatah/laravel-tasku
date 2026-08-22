export type OrgUnitType = 'company' | 'division' | 'sub_division' | 'team';

export type OrgUnitNode = {
    id: number;
    parent_id: number | null;
    name: string;
    type: string;
    depth: number;
    path: string;
    children_count: number;
    projects_count: number;
    members_count: number;
};

/**
 * A search result. `trail` holds the names of the unit's ancestors, top down,
 * so a hit eleven levels deep still says where it sits.
 */
export type OrgUnitHit = {
    id: number;
    parent_id: number | null;
    name: string;
    type: string;
    depth: number;
    path: string;
    trail: string[];
};

export const ORG_UNIT_TYPE_LABELS: Record<string, string> = {
    company: 'Perusahaan',
    division: 'Divisi',
    sub_division: 'Sub Divisi',
    team: 'Tim',
};

/**
 * Ids of every unit on a materialized path, e.g. `/1/5/12/` -> [1, 5, 12].
 */
export function unitIdsOnPath(path: string): number[] {
    return path.split('/').filter(Boolean).map(Number);
}
