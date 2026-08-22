import { Breadcrumbs } from '@/components/breadcrumbs';
import { NotificationBell } from '@/components/notification-bell';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    // Sticks to the top edge of the inset panel rather than the viewport, so
    // it lines up with the panel's rounded corners on desktop.
    return (
        <header className="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-2 rounded-t-xl border-b border-border bg-background/85 px-4 backdrop-blur-sm transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 sm:px-6 md:top-2 lg:px-8">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1.5" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            <NotificationBell />
        </header>
    );
}
