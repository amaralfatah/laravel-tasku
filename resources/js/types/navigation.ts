import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    /**
     * Left out for a crumb that only says where something sits — an org unit,
     * whose own pages belong to the platform operator, not the customer.
     */
    href?: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

/** A menu entry that only groups children; the row itself just expands. */
export type NavGroupItem = {
    title: string;
    icon?: LucideIcon | null;
    items: NavItem[];
};

export type NavEntry = NavItem | NavGroupItem;
