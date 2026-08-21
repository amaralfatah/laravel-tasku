import { usePage } from '@inertiajs/react';
import { LayoutGrid, Network, Users } from 'lucide-react';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { WorkspaceSwitcher } from '@/components/workspace-switcher';
import { dashboard } from '@/routes';
import { index as membersIndex } from '@/routes/members';
import { index as organizationIndex } from '@/routes/organization';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { tenancy } = usePage().props;

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    mainNavItems.push({
        title: 'Anggota',
        href: membersIndex(),
        icon: Users,
    });

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
