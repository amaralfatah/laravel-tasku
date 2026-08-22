import type { ProjectStatus } from '@/types/projects';

export type WorkspaceRole = 'bod_1' | 'bod_2' | 'bod_3' | 'bod_4';

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
    /** True for BOD-1 through BOD-3, who lead a slice of the org tree. */
    can_manage: boolean;
    /** False for someone whose scope covers only themselves. */
    can_monitor: boolean;
};

/** Project entry listed in the sidebar. */
export type SidebarProject = {
    id: number;
    name: string;
    status: ProjectStatus;
};

export type Tenancy = {
    workspace: WorkspaceSummary | null;
    membership: Membership | null;
    workspaces: WorkspaceSummary[];
    projects: SidebarProject[];
};
