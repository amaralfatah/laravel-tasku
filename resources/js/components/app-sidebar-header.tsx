import { Breadcrumbs } from '@/components/breadcrumbs';
import { NotificationBell } from '@/components/notification-bell';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    /*
     * The sub-nav grammar: a thin frosted strip that floats over the scrolling
     * canvas, separated by a hairline rather than a shadow.
     */
    return (
        <header className="surface-frosted sticky top-0 z-30 flex h-13 shrink-0 items-center gap-2 border-b border-border px-6 transition-[width,height] ease-linear md:px-4">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            <NotificationBell />
        </header>
    );
}
