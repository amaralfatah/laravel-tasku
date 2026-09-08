import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    wide = false,
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    wide?: boolean;
    children: React.ReactNode;
}) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs} wide={wide}>
            {children}
        </AppLayoutTemplate>
    );
}
