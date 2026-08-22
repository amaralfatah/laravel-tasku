export type ProjectStatus = 'active' | 'completed' | 'archived';

export type ProjectListItem = {
    id: number;
    name: string;
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
    description: string | null;
    status: ProjectStatus;
    status_label: string;
    org_unit: { id: number; name: string };
    created_by: string | null;
    members: ProjectMember[];
};

export const PROJECT_STATUS_VARIANT: Record<
    ProjectStatus,
    'info-subtle' | 'success-subtle' | 'outline'
> = {
    active: 'info-subtle',
    completed: 'success-subtle',
    archived: 'outline',
};
