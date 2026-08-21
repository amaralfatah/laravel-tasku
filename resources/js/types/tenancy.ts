export type WorkspaceRole = 'owner' | 'admin' | 'member';

export type ScopeType = 'project_only' | 'unit_subtree';

export type WorkspaceSummary = {
    id: number;
    name: string;
    slug: string;
};

export type Membership = {
    role: WorkspaceRole;
    role_label: string;
    scope_type: ScopeType;
    can_manage: boolean;
    can_monitor_division: boolean;
};

export type Tenancy = {
    workspace: WorkspaceSummary | null;
    membership: Membership | null;
    workspaces: WorkspaceSummary[];
};
