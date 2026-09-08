import { Link } from '@inertiajs/react';
import { ChartGantt, ListChecks } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { me, person } from '@/routes/monitoring';

type Tab = 'agenda' | 'timeline';

/**
 * The two ways to read your own work: the agenda you land on, and the gantt
 * next to it.
 *
 * Built like {@link ProjectHeader}'s row, because it does the same job. A lone
 * button pointing at the other view sat apart from everything around it and
 * gave no hint that a view was being left; a tab row says which of the two you
 * are in and keeps the way across in the same place on both (nav-consistency).
 *
 * Only for your own pages. Someone else's timeline has no agenda beside it.
 */
export function MyWorkTabs({
    memberId,
    active,
}: {
    memberId: number;
    active: Tab;
}) {
    const tabs: { key: Tab; label: string; href: string; icon: LucideIcon }[] =
        [
            {
                key: 'agenda',
                label: 'Agenda',
                href: me().url,
                icon: ListChecks,
            },
            {
                key: 'timeline',
                label: 'Timeline',
                href: person(memberId).url,
                icon: ChartGantt,
            },
        ];

    return (
        <nav
            className="-mx-4 flex gap-1 border-b px-4 sm:mx-0 sm:px-0"
            aria-label="Tampilan task saya"
        >
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
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
    );
}
