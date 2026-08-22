export type WorkspaceRole = 'bod_1' | 'bod_2' | 'bod_3' | 'bod_4';

export type ScopeType = 'project_only' | 'unit_subtree';

export type WorkspaceSummary = {
    id: number;
    name: string;
    slug: string;
};

export type Membership = {
    role: WorkspaceRole;
    role_label: string;
    /** Short ladder code, e.g. `BOD-1`. */
    role_code: string;
    scope_type: ScopeType;
    can_manage: boolean;
    /** False for someone whose monitoring scope covers only themselves. */
    can_monitor_people: boolean;
    can_monitor_division: boolean;
    /** True when a super admin is looking into a workspace they do not belong to. */
    is_super_admin: boolean;
};

export type Tenancy = {
    workspace: WorkspaceSummary | null;
    membership: Membership | null;
    workspaces: WorkspaceSummary[];
};
