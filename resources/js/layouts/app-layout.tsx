import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    wide = false,
    fitViewport = false,
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    wide?: boolean;
    fitViewport?: boolean;
    children: React.ReactNode;
}) {
    return (
        <AppLayoutTemplate
            breadcrumbs={breadcrumbs}
            wide={wide}
            fitViewport={fitViewport}
        >
            {children}
        </AppLayoutTemplate>
    );
}
