import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Menu</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => {
                    const isActive = isCurrentUrl(item.href);

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={isActive}
                                tooltip={{ children: item.title }}
                                // A rail on the left marks the current page in
                                // addition to the tint, so the active item is
                                // not signalled by colour alone.
                                className="relative data-[active=true]:before:absolute data-[active=true]:before:top-1.5 data-[active=true]:before:bottom-1.5 data-[active=true]:before:-left-2 data-[active=true]:before:w-0.5 data-[active=true]:before:rounded-full data-[active=true]:before:bg-sidebar-primary"
                            >
                                <Link
                                    href={item.href}
                                    prefetch
                                    aria-current={isActive ? 'page' : undefined}
                                >
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
