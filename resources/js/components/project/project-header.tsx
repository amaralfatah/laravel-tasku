import { Link } from '@inertiajs/react';
import { ChartGantt, Columns3, List, Settings } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';
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
    const activeRef = useRef<HTMLAnchorElement>(null);

    // The row scrolls sideways on a phone, so the tab that is open can start
    // out half past the right edge — Pengaturan always did.
    useEffect(() => {
        activeRef.current?.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
        });
    }, [active]);

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
                    <h1 className="min-w-0 truncate text-xl font-semibold">
                        {project.name}
                    </h1>
                    <Badge variant={PROJECT_STATUS_VARIANT[project.status]}>
                        {project.status_label}
                    </Badge>
                </div>
                <p className="truncate text-sm text-muted-foreground">
                    {project.org_unit.name}
                </p>
            </div>

            {/*
             * Four labelled tabs are wider than a phone, so the row scrolls
             * sideways rather than wrapping onto a second line that breaks the
             * underline the active tab hangs on.
             */}
            <nav
                className="-mx-4 flex [scrollbar-width:none] gap-1 overflow-x-auto border-b px-4 sm:mx-0 sm:px-0 [&::-webkit-scrollbar]:hidden"
                aria-label="Tampilan project"
            >
                {tabs.map((tab) => (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        ref={tab.key === active ? activeRef : undefined}
                        aria-current={tab.key === active ? 'page' : undefined}
                        className={cn(
                            'relative -mb-px flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-3 text-sm whitespace-nowrap transition-colors',
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
