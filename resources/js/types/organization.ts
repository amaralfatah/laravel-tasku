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

export const ORG_UNIT_TYPE_LABELS: Record<string, string> = {
    company: 'Perusahaan',
    division: 'Divisi',
    sub_division: 'Sub Divisi',
    team: 'Tim',
};
