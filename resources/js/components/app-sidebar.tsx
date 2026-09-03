import { usePage } from '@inertiajs/react';
import {
    Building2,
    Gauge,
    ListChecks,
    Network,
    ShieldCheck,
    Users,
    UserSearch,
} from 'lucide-react';
import { NavMain } from '@/components/nav-main';
import { NavProjects } from '@/components/nav-projects';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { WorkspaceSwitcher } from '@/components/workspace-switcher';
import { index as membersIndex } from '@/routes/members';
import { divisions, me, people } from '@/routes/monitoring';
import { index as organizationIndex } from '@/routes/organization';
import { index as workspacesIndex } from '@/routes/workspaces';
import type { NavEntry, NavItem } from '@/types';

export function AppSidebar() {
    const { auth, tenancy } = usePage().props;

    // Read from the user, not the membership: a super admin belongs to no
    // workspace at all, so `tenancy.membership` is always null for them.
    const isSuperAdmin = Boolean(auth.user?.is_super_admin);
    const membership = tenancy?.membership ?? null;
    const inWorkspace = Boolean(tenancy?.workspace);

    const mainNavItems: NavEntry[] = [];

    if (inWorkspace) {
        mainNavItems.push({
            title: 'Task saya',
            href: me(),
            icon: ListChecks,
        });

        // BOD-1 through BOD-3 lead a slice of the org tree and get exactly the
        // same menu; only how far that slice reaches differs. An ODS leads
        // nobody, so none of this is theirs.
        if (membership?.can_monitor) {
            // Both views watch the same workload from a different angle, so
            // they sit under one heading instead of repeating "Monitoring".
            const monitoringItems: NavItem[] = [
                { title: 'Per anggota', href: people(), icon: UserSearch },
                { title: 'Per divisi', href: divisions(), icon: Network },
            ];

            mainNavItems.push(
                { title: 'Anggota', href: membersIndex(), icon: Users },
                { title: 'Monitoring', icon: Gauge, items: monitoringItems },
            );
        }
    }

    // The org structure is platform master data mirrored from SAP, so it is
    // the operator's page, not a workspace one.
    if (isSuperAdmin) {
        mainNavItems.push(
            {
                title: 'Kelola workspace',
                href: workspacesIndex(),
                icon: ShieldCheck,
            },
            {
                title: 'Struktur organisasi',
                href: organizationIndex(),
                icon: Building2,
            },
        );
    }

    /*
     * The "sidebar" variant, not "inset": inset floats the content as a rounded
     * card inset by 8px on every side, which only reads when the canvas behind
     * it is a different colour. Everything is one surface now, so the divide
     * has to be the variant's own `border-r`.
     */
    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <WorkspaceSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />

                {inWorkspace && (
                    <NavProjects projects={tenancy?.projects ?? []} />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
