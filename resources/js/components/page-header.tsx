import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Standard heading block for a page body: title on the left, the page's single
 * primary action on the right. Every page uses this so the vertical rhythm and
 * type scale stay identical across the app.
 */
export function PageHeader({
    title,
    description,
    actions,
    className,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <header
            className={cn(
                'flex flex-wrap items-start justify-between gap-x-4 gap-y-3',
                className,
            )}
        >
            <div className="min-w-0 space-y-1">
                <h1 className="truncate text-xl font-semibold tracking-tight text-foreground">
                    {title}
                </h1>
                {description && (
                    <p className="max-w-prose text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>

            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </header>
    );
}

/**
 * Section heading one level below {@link PageHeader}, for grouping panels
 * within a page.
 */
export function SectionHeader({
    title,
    description,
    actions,
    className,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-x-4 gap-y-2',
                className,
            )}
        >
            <div className="min-w-0 space-y-0.5">
                <h2 className="text-sm font-semibold text-foreground">
                    {title}
                </h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>

            {actions && (
                <div className="flex shrink-0 items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
