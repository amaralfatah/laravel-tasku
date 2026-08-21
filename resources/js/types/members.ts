import type { ScopeType, WorkspaceRole } from '@/types/tenancy';

export type NamedRef = { id: number; name: string };

export type MemberRow = {
    id: number;
    user: {
        id: number;
        name: string;
        email: string;
        avatar: string | null;
    };
    role: WorkspaceRole;
    role_label: string;
    role_code: string;
    org_unit: NamedRef | null;
    scope_type: ScopeType;
    scope_org_unit: NamedRef | null;
    manager_id: number | null;
    is_last_top_role: boolean;
    is_self: boolean;
};

export type InvitationRow = {
    id: number;
    email: string;
    role_label: string;
    token: string;
    accept_url: string;
    expires_at: string;
    is_expired: boolean;
    invited_by: string | null;
};

export type OrgUnitOption = { id: number; name: string; depth: number };

export type Option = { value: string; label: string; code?: string };
