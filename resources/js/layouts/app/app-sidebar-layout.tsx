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
    fitViewport = false,
}: AppLayoutProps) {
    return (
        <AppShell
            variant="sidebar"
            className={cn(fitViewport && 'h-svh max-h-svh overflow-hidden')}
        >
            <AppSidebar />
            <AppContent
                variant="sidebar"
                className={cn(
                    'min-w-0',
                    fitViewport && 'h-svh max-h-svh overflow-hidden',
                )}
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />

                {/*
                 * The single owner of page gutters and max width. Pages render
                 * their own vertical rhythm and never re-declare padding, so
                 * every screen lines up on the same grid.
                 *
                 * A `wide` page drops the reading-width cap: a gantt is read
                 * across, not down, and every pixel taken from the chart is a
                 * week the reader has to scroll for.
                 *
                 * A `fitViewport` page (such as the Kanban board) fits the
                 * viewport height exactly so that individual columns scroll
                 * vertically instead of the whole page.
                 */}
                <div
                    className={cn(
                        'mx-auto w-full min-w-0 flex-1',
                        !wide && 'max-w-7xl',
                        fitViewport
                            ? 'flex min-h-0 flex-col overflow-hidden px-4 pt-4 pb-3 sm:px-6 sm:pt-4 sm:pb-3 lg:px-8'
                            : 'px-4 pt-6 pb-24 sm:px-6 sm:py-6 lg:px-8',
                    )}
                >
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
