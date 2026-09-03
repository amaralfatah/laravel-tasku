export type WorkspaceRole = 'owner' | 'manager' | 'member' | 'viewer';

export type WorkspaceSummary = {
    id: number;
    name: string;
    slug: string;
};

export type Membership = {
    role: WorkspaceRole;
    role_label: string;
    /** Short tier code, e.g. `OWNER`. */
    role_code: string;
    /** Formal position the customer typed, e.g. `Kepala Divisi`. */
    title: string;
    /** True for an owner or a manager, who lead a slice of the org tree. */
    can_manage: boolean;
    /** False for someone whose scope covers only themselves. */
    can_monitor: boolean;
    /** False for a viewer, who reads and changes nothing. */
    can_write: boolean;
};

/** Project entry listed in the sidebar. */
export type SidebarProject = {
    id: number;
    name: string;
};

export type Tenancy = {
    workspace: WorkspaceSummary | null;
    membership: Membership | null;
    workspaces: WorkspaceSummary[];
    projects: SidebarProject[];
};
