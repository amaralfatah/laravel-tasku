import { usePage } from '@inertiajs/react';
import {
    Building2,
    ContactRound,
    Gauge,
    ListChecks,
    Network,
    ShieldCheck,
    Users,
    UserSearch,
} from 'lucide-react';
import { useEffect } from 'react';
import { NavMain } from '@/components/nav-main';
import { NavProjects } from '@/components/nav-projects';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    useSidebar,
} from '@/components/ui/sidebar';
import { WorkspaceSwitcher } from '@/components/workspace-switcher';
import { index as groupIndex } from '@/routes/group';
import { index as membersIndex } from '@/routes/members';
import { divisions, me, people } from '@/routes/monitoring';
import { index as organizationIndex } from '@/routes/organization';
import { index as requestersIndex } from '@/routes/requesters';
import { index as workspacesIndex } from '@/routes/workspaces';
import type { NavEntry, NavItem } from '@/types';

export function AppSidebar() {
    const page = usePage();
    const { auth, tenancy } = page.props;
    const { isMobile, setOpenMobile } = useSidebar();

    /*
     * On a phone the sidebar is a sheet drawn over the page, and Inertia swaps
     * the page underneath without unmounting it — so tapping a row used to
     * load the destination behind a drawer that stayed open on top of it.
     * Closing on every URL change covers every row, including the projects and
     * the workspace switcher, without wiring an onClick onto each link.
     */
    useEffect(() => {
        if (isMobile) {
            setOpenMobile(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page.url]);

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

        /*
         * Progressive disclosure: a solo workspace has nobody to place and
         * nobody to monitor, so it is never shown the roster or the reporting
         * pages even though its Owner would be allowed to open them. The scale
         * follows the data, so the menu grows as the organisation appears.
         */
        const scale = tenancy?.workspace?.scale ?? 'solo';
        const showsOrganisation = scale !== 'solo';

        // Only the holding itself gets the consolidated view, and only a
        // group-level role reaches across its entities.
        if (membership?.can_view_group) {
            mainNavItems.push({
                title: 'Konsolidasi grup',
                href: groupIndex(),
                icon: Building2,
            });
        }

        // An owner or manager leads a slice of the org tree and gets exactly the
        // same menu; only how far that slice reaches differs. An ODS leads
        // nobody, so none of this is theirs.
        //
        // The roster is not behind the scale gate, and must not be: it is where
        // people are invited, so hiding it while a workspace is solo left the
        // owner no way to add the second person that ends solo. A freelancer
        // hands a client a Viewer seat from here too.
        if (membership?.can_monitor) {
            mainNavItems.push({
                title: 'Anggota',
                href: membersIndex(),
                icon: Users,
            });
        }

        // Master data a leader keeps so everyone else can pick from it. Not
        // behind the scale gate, for the same reason the roster is not: a solo
        // freelancer's requesters are their clients, which is exactly who the
        // list is most useful for.
        if (membership?.can_manage) {
            mainNavItems.push({
                title: 'Pemohon',
                href: requestersIndex(),
                icon: ContactRound,
            });
        }

        if (showsOrganisation && membership?.can_monitor) {
            // Both views watch the same workload from a different angle, so
            // they sit under one heading instead of repeating "Monitoring".
            const monitoringItems: NavItem[] = [
                { title: 'Per anggota', href: people(), icon: UserSearch },
                { title: 'Per divisi', href: divisions(), icon: Network },
            ];

            mainNavItems.push({
                title: 'Monitoring',
                icon: Gauge,
                items: monitoringItems,
            });
        }

        // A leader shapes the branch they run, so the structure page is
        // theirs too — scoped to that branch, and never the roots.
        if (showsOrganisation && membership?.can_manage) {
            mainNavItems.push({
                title: 'Struktur organisasi',
                href: organizationIndex(),
                icon: Network,
            });
        }
    }

    // Without a workspace the same page opens on the whole master tree, which
    // is the operator's own view of it.
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
