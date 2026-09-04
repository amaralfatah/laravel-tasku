export type ProjectStatus = 'active' | 'completed' | 'archived';

/**
 * Where a project sits in the organisation: the unit itself plus its ancestors
 * top down, so a view can show `Divisi IT › Subdivisi Pengembangan › Unit`.
 * A trail cut short by the backend opens with an ellipsis entry.
 */
export type OrgUnitLocation = {
    id: number;
    name: string;
    trail: string[];
};

export type ProjectListItem = {
    id: number;
    name: string;
    key: string;
    description: string | null;
    status: ProjectStatus;
    status_label: string;
    org_unit: { id: number; name: string };
    members_count: number;
    can_edit: boolean;
};

export type ProjectMember = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
};

export type ProjectDetail = {
    id: number;
    name: string;
    key: string;
    description: string | null;
    status: ProjectStatus;
    status_label: string;
    org_unit: OrgUnitLocation;
    created_by: string | null;
    members: ProjectMember[];
};

export const PROJECT_STATUS_VARIANT: Record<
    ProjectStatus,
    'default' | 'secondary' | 'outline'
> = {
    active: 'default',
    completed: 'secondary',
    archived: 'outline',
};
