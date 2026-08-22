import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
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
    useSidebar,
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

/**
 * Sub rows keep the height of the top-level ones but sit a step quieter, so a
 * child never reads as a sibling of the item it hangs under.
 */
export const SUB_ROW = 'h-8 gap-2 text-sm text-sidebar-foreground/70';

/**
 * The sub list drops the shadcn rail but keeps an indent — the indent is what
 * carries the hierarchy once the rail is gone.
 */
export const SUB_LIST = 'mx-0 translate-x-0 border-none pr-0 pl-4';

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
    const { state, isMobile } = useSidebar();
    const children = item.items;
    const [isOpen, setIsOpen] = useState(true);

    const hasActiveChild = children.some((child) => isCurrentUrl(child.href));

    // The heading stands in for the open child whenever that child is out of
    // sight — folded away, or hidden because the icon rail drops sub lists.
    // While the list is visible the child carries the marker on its own, so
    // lighting both would say "you are in two places".
    const childIsHidden = !isOpen || (state === 'collapsed' && !isMobile);

    return (
        <Collapsible
            open={isOpen}
            onOpenChange={setIsOpen}
            className="group/collapsible"
        >
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        isActive={hasActiveChild && childIsHidden}
                        tooltip={{ children: item.title }}
                        className={ACTIVE_RAIL}
                    >
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
