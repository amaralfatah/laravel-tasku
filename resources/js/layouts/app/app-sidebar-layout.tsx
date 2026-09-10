import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    wide = false,
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />

                {/*
                 * The single owner of page gutters and max width. Pages render
                 * their own vertical rhythm and never re-declare padding, so
                 * every screen lines up on the same grid.
                 *
                 * A `wide` page drops the reading-width cap: a gantt is read
                 * across, not down, and every pixel taken from the chart is a
                 * week the reader has to scroll for.
                 */}
                <div
                    className={cn(
                        'mx-auto w-full flex-1 px-4 pt-6 pb-24 sm:py-6 sm:px-6 lg:px-8',
                        !wide && 'max-w-7xl',
                    )}
                >
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
