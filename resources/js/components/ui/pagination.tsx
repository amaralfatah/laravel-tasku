import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
    current_page: number;
    last_page: number;
};

/**
 * Renders Laravel's paginator links.
 *
 * Laravel emits the previous and next labels as HTML entities, so those two
 * are replaced with icons and the rest render as page numbers.
 */
export function Pagination({
    meta,
    label = 'Navigasi halaman',
    className,
}: {
    meta: Pick<
        Paginated<unknown>,
        'links' | 'from' | 'to' | 'total' | 'last_page'
    >;
    label?: string;
    className?: string;
}) {
    if (meta.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label={label}
            className={cn(
                'flex flex-wrap items-center justify-between gap-3',
                className,
            )}
        >
            <p className="text-xs text-muted-foreground tabular-nums">
                Menampilkan {meta.from ?? 0}–{meta.to ?? 0} dari {meta.total}
            </p>

            <ul className="flex flex-wrap items-center gap-1">
                {meta.links.map((link, index) => {
                    const isPrevious = index === 0;
                    const isNext = index === meta.links.length - 1;

                    const content = isPrevious ? (
                        <ChevronLeft className="size-4" aria-hidden="true" />
                    ) : isNext ? (
                        <ChevronRight className="size-4" aria-hidden="true" />
                    ) : (
                        link.label
                    );

                    const accessibleLabel = isPrevious
                        ? 'Halaman sebelumnya'
                        : isNext
                          ? 'Halaman berikutnya'
                          : `Halaman ${link.label}`;

                    if (link.url === null) {
                        return (
                            <li key={index}>
                                <span
                                    aria-disabled="true"
                                    className="flex h-9 min-w-9 items-center justify-center rounded-md px-2 text-sm text-muted-foreground/50"
                                >
                                    {content}
                                    <span className="sr-only">
                                        {accessibleLabel}
                                    </span>
                                </span>
                            </li>
                        );
                    }

                    return (
                        <li key={index}>
                            <Link
                                href={link.url}
                                preserveScroll
                                aria-label={accessibleLabel}
                                aria-current={link.active ? 'page' : undefined}
                                className={cn(
                                    'flex h-9 min-w-9 items-center justify-center rounded-md border px-2 text-sm transition-colors',
                                    link.active
                                        ? 'border-foreground bg-foreground font-medium text-background'
                                        : 'border-transparent hover:bg-muted',
                                )}
                            >
                                {content}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
