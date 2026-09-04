import { Link } from '@inertiajs/react';
import { ChartGantt, Columns3, List, Settings } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { list, settings, show, timeline } from '@/routes/projects';
import { PROJECT_STATUS_VARIANT } from '@/types/projects';
import type { ProjectSummary } from '@/types/tasks';

type Tab = 'board' | 'list' | 'timeline' | 'settings';

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
    // Jira names a view with an icon as well as a word, which is what makes the
    // row readable at a glance once there are more than three of them.
    const tabs: { key: Tab; label: string; href: string; icon: LucideIcon }[] =
        [
            {
                key: 'board',
                label: 'Papan',
                href: show(project.id).url,
                icon: Columns3,
            },
            {
                key: 'list',
                label: 'Daftar',
                href: list(project.id).url,
                icon: List,
            },
            {
                key: 'timeline',
                label: 'Timeline',
                href: timeline(project.id).url,
                icon: ChartGantt,
            },
            {
                key: 'settings',
                label: 'Pengaturan',
                href: settings(project.id).url,
                icon: Settings,
            },
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

            <nav className="flex gap-1 border-b" aria-label="Tampilan project">
                {tabs.map((tab) => (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        aria-current={tab.key === active ? 'page' : undefined}
                        className={cn(
                            'relative -mb-px flex min-h-11 items-center gap-2 border-b-2 px-3 text-sm transition-colors',
                            tab.key === active
                                ? 'border-primary font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <tab.icon className="size-4" aria-hidden="true" />
                        {tab.label}
                    </Link>
                ))}
            </nav>
        </div>
    );
}
