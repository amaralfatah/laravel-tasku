import { Link } from '@inertiajs/react';
import { ChevronRight, FolderKanban, MoreHorizontal, Plus } from 'lucide-react';
import { ACTIVE_RAIL, SUB_LIST, SUB_ROW } from '@/components/nav-main';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
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
            <SidebarMenu>
                <Collapsible defaultOpen className="group/collapsible">
                    <SidebarMenuItem>
                        <CollapsibleTrigger asChild>
                            <SidebarMenuButton
                                tooltip={{ children: 'Project' }}
                            >
                                <FolderKanban />
                                <span>Project</span>
                                <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90 motion-reduce:transition-none" />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>

                        <SidebarMenuAction asChild showOnHover>
                            <Link
                                href={projectsIndex()}
                                prefetch
                                title="Buat project"
                                aria-label="Buat project"
                            >
                                <Plus />
                            </Link>
                        </SidebarMenuAction>

                        <CollapsibleContent>
                            {/* No rail or indent: the projects read as menu
                                rows of their own, aligned with the items
                                above them. */}
                            <SidebarMenuSub className={SUB_LIST}>
                                {projects.map((project) => {
                                    const isActive = isCurrentProject(
                                        project.id,
                                    );

                                    return (
                                        <SidebarMenuSubItem key={project.id}>
                                            <SidebarMenuSubButton
                                                asChild
                                                isActive={isActive}
                                                className={cn(
                                                    SUB_ROW,
                                                    'translate-x-0',
                                                    ACTIVE_RAIL,
                                                )}
                                            >
                                                <Link
                                                    href={projectShow(
                                                        project.id,
                                                    )}
                                                    prefetch
                                                    aria-current={
                                                        isActive
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                    title={project.name}
                                                >
                                                    <span
                                                        aria-hidden
                                                        // Tinted from the
                                                        // foreground rather
                                                        // than the accent, so
                                                        // the tile stays
                                                        // visible on the
                                                        // active row too.
                                                        className="flex size-5 shrink-0 items-center justify-center rounded-sm bg-sidebar-foreground/10 text-[10px] font-semibold text-sidebar-foreground/80"
                                                    >
                                                        {initials(project.name)}
                                                    </span>
                                                    <span>{project.name}</span>
                                                </Link>
                                            </SidebarMenuSubButton>
                                        </SidebarMenuSubItem>
                                    );
                                })}

                                <SidebarMenuSubItem>
                                    <SidebarMenuSubButton
                                        asChild
                                        isActive={
                                            currentUrl ===
                                            toUrl(projectsIndex())
                                        }
                                        className={cn(
                                            SUB_ROW,
                                            'translate-x-0 text-sidebar-foreground/80',
                                            ACTIVE_RAIL,
                                        )}
                                    >
                                        <Link href={projectsIndex()} prefetch>
                                            <span
                                                aria-hidden
                                                className="flex size-5 shrink-0 items-center justify-center"
                                            >
                                                <MoreHorizontal className="size-4" />
                                            </span>
                                            <span>
                                                {projects.length > 0
                                                    ? 'Semua project'
                                                    : 'Belum ada project'}
                                            </span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>
            </SidebarMenu>
        </SidebarGroup>
    );
}
