import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { NavEntry, NavGroupItem, NavItem } from '@/types';

/**
 * Rail on the left that marks the current page in addition to the tint, so the
 * active item is not signalled by colour alone.
 */
export const ACTIVE_RAIL =
    'relative data-[active=true]:before:absolute data-[active=true]:before:top-1.5 data-[active=true]:before:bottom-1.5 data-[active=true]:before:-left-2 data-[active=true]:before:w-0.5 data-[active=true]:before:rounded-full data-[active=true]:before:bg-sidebar-primary';

/** Sub rows carry the same height and text as the top-level ones. */
export const SUB_ROW = 'h-8 gap-2 text-sm';

/** The sub list drops its rail and indent so the rows stay aligned. */
export const SUB_LIST = 'mx-0 translate-x-0 border-none px-0';

function NavLeaf({ item }: { item: NavItem }) {
    const { isCurrentUrl } = useCurrentUrl();
    const isActive = isCurrentUrl(item.href);

    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                asChild
                isActive={isActive}
                tooltip={{ children: item.title }}
                className={ACTIVE_RAIL}
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
}

function NavBranch({ item }: { item: NavGroupItem }) {
    const { isCurrentUrl } = useCurrentUrl();
    const children = item.items;

    return (
        <Collapsible defaultOpen className="group/collapsible">
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton tooltip={{ children: item.title }}>
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                        <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90 motion-reduce:transition-none" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>

                <CollapsibleContent>
                    <SidebarMenuSub className={SUB_LIST}>
                        {children.map((child) => {
                            const isActive = isCurrentUrl(child.href);

                            return (
                                <SidebarMenuSubItem key={child.title}>
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
                                            href={child.href}
                                            prefetch
                                            aria-current={
                                                isActive ? 'page' : undefined
                                            }
                                        >
                                            {child.icon && <child.icon />}
                                            <span>{child.title}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            );
                        })}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

export function NavMain({ items = [] }: { items: NavEntry[] }) {
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Menu</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) =>
                    'items' in item ? (
                        <NavBranch key={item.title} item={item} />
                    ) : (
                        <NavLeaf key={item.title} item={item} />
                    ),
                )}
            </SidebarMenu>
        </SidebarGroup>
    );
}
