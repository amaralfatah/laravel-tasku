export type WorkspaceRole = 'owner' | 'manager' | 'member' | 'viewer';

export type WorkspaceScale = 'solo' | 'team' | 'company' | 'holding';

export type WorkspaceSummary = {
    id: number;
    name: string;
    slug: string;
};

/** The active workspace, with the group context it sits in. */
export type ActiveWorkspace = WorkspaceSummary & {
    /** True when this workspace runs other workspaces. */
    is_holding: boolean;
    /** The holding above it, when it is itself an operating company. */
    parent: WorkspaceSummary | null;
    /** How much organisation exists here; drives progressive disclosure. */
    scale: WorkspaceScale;
};

/** A row in the workspace switcher. */
export type SwitchableWorkspace = WorkspaceSummary & {
    parent_id: number | null;
    /** True when other rows in the list sit under this one. */
    is_group_parent: boolean;
    /** True when the user only reaches it through the group above it. */
    via_group: boolean;
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
    /** True when this membership came from the holding above the workspace. */
    via_group: boolean;
    /** True when the consolidated group page applies to this membership. */
    can_view_group: boolean;
};

/** Project entry listed in the sidebar. */
export type SidebarProject = {
    id: number;
    name: string;
};

export type Tenancy = {
    workspace: ActiveWorkspace | null;
    membership: Membership | null;
    workspaces: SwitchableWorkspace[];
    projects: SidebarProject[];
};
