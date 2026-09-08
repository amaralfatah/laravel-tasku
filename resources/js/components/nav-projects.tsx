import { Link } from '@inertiajs/react';
import { MoreHorizontal, Plus } from 'lucide-react';
import {
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toUrl } from '@/lib/utils';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import type { SidebarProject } from '@/types';

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('');
}

/** The `+` opens the create dialog the index page already owns. */
const createProject = projectsIndex({ query: { create: 1 } });

export function NavProjects({ projects }: { projects: SidebarProject[] }) {
    const { currentUrl } = useCurrentUrl();

    // A project owns its board, list, timeline and settings pages, so the
    // entry stays lit on all of them — but `/projects/1` must not match
    // `/projects/12`.
    const isCurrentProject = (id: number): boolean => {
        const base = toUrl(projectShow(id));

        return currentUrl === base || currentUrl.startsWith(`${base}/`);
    };

    return (
        <SidebarGroup className="px-2 py-0">
            {/* A section heading like "Menu", not a menu row of its own: the
                projects below are top-level destinations, so they survive the
                icon rail — a sub list would not. */}
            <SidebarGroupLabel>Project</SidebarGroupLabel>

            {/* `top-1.5` re-centres it on the label, which sits higher here
                than in a default group because the group drops its padding;
                the wider pseudo-element brings the touch target up to 44px. */}
            <SidebarGroupAction
                asChild
                title="Buat project"
                className="top-1.5 after:-inset-3"
            >
                <Link href={createProject} prefetch aria-label="Buat project">
                    <Plus />
                </Link>
            </SidebarGroupAction>

            <SidebarMenu>
                {projects.map((project) => {
                    const isActive = isCurrentProject(project.id);

                    return (
                        <SidebarMenuItem key={project.id}>
                            <SidebarMenuButton
                                asChild
                                isActive={isActive}
                                tooltip={{ children: project.name }}
                                className="group/project"
                            >
                                <Link
                                    href={projectShow(project.id)}
                                    prefetch
                                    aria-current={isActive ? 'page' : undefined}
                                >
                                    <span
                                        aria-hidden
                                        // Tinted from the row's own text
                                        // colour, so the tile turns blue with
                                        // the label on the active row instead
                                        // of staying a grey chip on it.
                                        className="flex size-5 shrink-0 items-center justify-center rounded-sm bg-sidebar-foreground/15 text-[10px] font-semibold text-sidebar-foreground group-data-[active=true]/project:bg-sidebar-selected-foreground/20 group-data-[active=true]/project:text-sidebar-selected-foreground group-data-[collapsible=icon]:size-4 group-data-[collapsible=icon]:text-[8px]"
                                    >
                                        {initials(project.name)}
                                    </span>
                                    <span>{project.name}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}

                <SidebarMenuItem>
                    <SidebarMenuButton
                        asChild
                        isActive={currentUrl === toUrl(projectsIndex())}
                        tooltip={{
                            children:
                                projects.length > 0
                                    ? 'Semua project'
                                    : 'Buat project pertama',
                        }}
                        className="text-sidebar-foreground/70"
                    >
                        {/* With nothing to list, the row has to offer the way
                            out instead of restating that the list is empty. */}
                        <Link
                            href={
                                projects.length > 0
                                    ? projectsIndex()
                                    : createProject
                            }
                            prefetch
                        >
                            {projects.length > 0 ? (
                                <MoreHorizontal />
                            ) : (
                                <Plus />
                            )}
                            <span>
                                {projects.length > 0
                                    ? 'Semua project'
                                    : 'Buat project pertama'}
                            </span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroup>
    );
}
