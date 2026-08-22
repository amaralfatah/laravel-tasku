import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
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
                 */}
                <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
