import { usePage } from '@inertiajs/react';
import {
    Building2,
    FolderKanban,
    ListChecks,
    Network,
    ShieldCheck,
    Users,
    UserSearch,
} from 'lucide-react';
import { NavMain } from '@/components/nav-main';
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
import { index as projectsIndex } from '@/routes/projects';
import { index as workspacesIndex } from '@/routes/workspaces';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { auth, tenancy } = usePage().props;

    // Read from the user, not the membership: the workspace roster resolves no
    // workspace while the platform has none, so `tenancy.membership` is null.
    const isSuperAdmin = Boolean(auth.user?.is_super_admin);
    const membership = tenancy?.membership ?? null;
    const inWorkspace = Boolean(tenancy?.workspace);

    const mainNavItems: NavItem[] = [];

    if (inWorkspace) {
        // A super admin is a guest in every workspace and carries no tasks of
        // their own there.
        if (!isSuperAdmin) {
            mainNavItems.push({
                title: 'Task saya',
                href: me(),
                icon: ListChecks,
            });
        }

        mainNavItems.push(
            { title: 'Project', href: projectsIndex(), icon: FolderKanban },
            { title: 'Anggota', href: membersIndex(), icon: Users },
        );

        if (membership?.can_monitor_people) {
            mainNavItems.push({
                title: 'Monitoring orang',
                href: people(),
                icon: UserSearch,
            });
        }

        if (membership?.can_monitor_division) {
            mainNavItems.push({
                title: 'Monitoring divisi',
                href: divisions(),
                icon: Network,
            });
        }

        if (membership?.can_manage) {
            mainNavItems.push({
                title: 'Organisasi',
                href: organizationIndex(),
                icon: Building2,
            });
        }
    }

    if (isSuperAdmin) {
        mainNavItems.push({
            title: 'Kelola workspace',
            href: workspacesIndex(),
            icon: ShieldCheck,
        });
    }

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <WorkspaceSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
