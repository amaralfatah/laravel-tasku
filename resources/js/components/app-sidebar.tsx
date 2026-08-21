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
import { index as adminWorkspaces } from '@/routes/admin/workspaces';
import { index as membersIndex } from '@/routes/members';
import { divisions, me, people } from '@/routes/monitoring';
import { index as organizationIndex } from '@/routes/organization';
import { index as projectsIndex } from '@/routes/projects';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { tenancy } = usePage().props;

    const isSuperAdmin = Boolean(tenancy?.membership?.is_super_admin);

    const mainNavItems: NavItem[] = [
        // A super admin has no tasks of their own in this workspace.
        ...(isSuperAdmin
            ? []
            : [
                  {
                      title: 'Task saya',
                      href: me(),
                      icon: ListChecks,
                  },
              ]),
        {
            title: 'Project',
            href: projectsIndex(),
            icon: FolderKanban,
        },
        {
            title: 'Monitoring orang',
            href: people(),
            icon: UserSearch,
        },
        {
            title: 'Anggota',
            href: membersIndex(),
            icon: Users,
        },
    ];

    if (tenancy?.membership?.can_monitor_division) {
        mainNavItems.push({
            title: 'Monitoring divisi',
            href: divisions(),
            icon: Network,
        });
    }

    if (tenancy?.membership?.can_manage) {
        mainNavItems.push({
            title: 'Organisasi',
            href: organizationIndex(),
            icon: Building2,
        });
    }

    if (isSuperAdmin) {
        mainNavItems.push({
            title: 'Panel operator',
            href: adminWorkspaces(),
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
