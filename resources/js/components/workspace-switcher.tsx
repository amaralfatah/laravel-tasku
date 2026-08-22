import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import { change } from '@/routes/workspace';

export function WorkspaceSwitcher() {
    const { name, tenancy } = usePage().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    const active = tenancy?.workspace;

    // The workspace roster resolves no workspace while the platform has none,
    // so show the product itself rather than leaving the header empty.
    if (!active) {
        return (
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" className="cursor-default">
                        <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                            <Building2 className="size-4" />
                        </div>
                        <div className="grid flex-1 text-left text-sm leading-tight">
                            <span className="truncate font-semibold">
                                {name}
                            </span>
                            <span className="truncate text-xs text-muted-foreground">
                                Tanpa workspace aktif
                            </span>
                        </div>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        );
    }

    const others = tenancy.workspaces;

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild disabled={others.length < 2}>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent"
                            aria-label={`Workspace aktif: ${active.name}`}
                        >
                            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                                <Building2 className="size-4" />
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-semibold">
                                    {active.name}
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {tenancy.membership?.role_label ??
                                        'Workspace'}
                                </span>
                            </div>
                            {others.length > 1 && (
                                <ChevronsUpDown className="ml-auto size-4" />
                            )}
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="start"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'right'
                                  : 'bottom'
                        }
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Pindah workspace
                        </DropdownMenuLabel>
                        {others.map((workspace) => (
                            <DropdownMenuItem
                                key={workspace.id}
                                onSelect={() =>
                                    router.post(
                                        change({ workspace: workspace.slug })
                                            .url,
                                    )
                                }
                            >
                                <Building2 className="size-4 shrink-0 text-muted-foreground" />
                                <span className="truncate">
                                    {workspace.name}
                                </span>
                                {workspace.id === active.id && (
                                    <Check className="ml-auto size-4" />
                                )}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
