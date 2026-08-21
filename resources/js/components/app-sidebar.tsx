import { usePage } from '@inertiajs/react';
import {
    FolderKanban,
    ListChecks,
    Network,
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
import { me, people } from '@/routes/monitoring';
import { index as organizationIndex } from '@/routes/organization';
import { index as projectsIndex } from '@/routes/projects';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { tenancy } = usePage().props;

    const mainNavItems: NavItem[] = [
        {
            title: 'Task saya',
            href: me(),
            icon: ListChecks,
        },
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

    if (tenancy?.membership?.can_manage) {
        mainNavItems.push({
            title: 'Organisasi',
            href: organizationIndex(),
            icon: Network,
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
