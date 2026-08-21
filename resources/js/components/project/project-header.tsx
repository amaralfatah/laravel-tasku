import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { settings, show } from '@/routes/projects';
import { PROJECT_STATUS_VARIANT } from '@/types/projects';
import type { ProjectSummary } from '@/types/tasks';

type Tab = 'list' | 'settings';

/**
 * Shared chrome for every view of a project: identity plus the view switcher.
 * Navigation stays in the same place across views (nav-consistency).
 */
export function ProjectHeader({
    project,
    active,
}: {
    project: ProjectSummary;
    active: Tab;
}) {
    const tabs: { key: Tab; label: string; href: string }[] = [
        { key: 'list', label: 'Daftar task', href: show(project.id).url },
        { key: 'settings', label: 'Pengaturan', href: settings(project.id).url },
    ];

    return (
        <div className="space-y-4">
            <div className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-xl font-semibold">{project.name}</h1>
                    <Badge variant={PROJECT_STATUS_VARIANT[project.status]}>
                        {project.status_label}
                    </Badge>
                </div>
                <p className="text-sm text-muted-foreground">
                    {project.org_unit.name}
                </p>
            </div>

            <nav
                className="flex gap-1 border-b"
                aria-label="Tampilan project"
            >
                {tabs.map((tab) => (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        aria-current={tab.key === active ? 'page' : undefined}
                        className={cn(
                            'relative -mb-px flex min-h-11 items-center border-b-2 px-3 text-sm transition-colors',
                            tab.key === active
                                ? 'border-foreground font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {tab.label}
                    </Link>
                ))}
            </nav>
        </div>
    );
}
