import type { WorkspaceRole } from '@/types/tenancy';

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
    title: string;
    org_unit: NamedRef | null;
    manager_id: number | null;
    is_last_top_role: boolean;
    is_self: boolean;
    can_edit: boolean;
    can_change_role: boolean;
    can_remove: boolean;
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

/**
 * What a page needs to render an org unit picker: the natural default and
 * whether the viewer may search for something else. The full list of units is
 * never sent — after the SAP import there are tens of thousands of them.
 */
export type OrgUnitPickerProps = {
    default: NamedRef | null;
    can_choose: boolean;
};

export type Option = {
    value: string;
    label: string;
    code?: string;
    description?: string;
};
