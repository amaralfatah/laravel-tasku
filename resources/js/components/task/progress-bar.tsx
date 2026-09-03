import { cn } from '@/lib/utils';

/**
 * Progress bar with an optional rollup marker (TSK-17).
 *
 * The rollup tick shows the children's average next to the manually entered
 * value, so a parent that is more optimistic than its subtasks is visible.
 */
export function ProgressBar({
    value,
    rollup = null,
    className,
    showLabel = false,
}: {
    value: number;
    rollup?: number | null;
    className?: string;
    showLabel?: boolean;
}) {
    const clamped = Math.max(0, Math.min(100, value));
    const gap = rollup !== null ? clamped - rollup : 0;

    return (
        <div className={cn('flex items-center gap-2', className)}>
            <div
                className="relative h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-valuenow={clamped}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={`Progress ${clamped} persen`}
            >
                <div
                    className={cn(
                        'h-full rounded-full transition-[width] duration-200',
                        clamped === 100 ? 'bg-success' : 'bg-foreground/70',
                    )}
                    style={{ width: `${clamped}%` }}
                />

                {rollup !== null && (
                    <span
                        className="absolute top-0 h-full w-0.5 bg-primary"
                        style={{ left: `calc(${rollup}% - 1px)` }}
                        title={`Rata-rata sub task: ${rollup}%`}
                        aria-hidden="true"
                    />
                )}
            </div>

            {showLabel && (
                <span
                    className={cn(
                        'w-10 shrink-0 text-right text-xs tabular-nums',
                        Math.abs(gap) >= 20
                            ? 'font-medium text-warning'
                            : 'text-muted-foreground',
                    )}
                    title={
                        rollup !== null
                            ? `Manual ${clamped}%, rata-rata sub task ${rollup}%`
                            : undefined
                    }
                >
                    {clamped}%
                </span>
            )}
        </div>
    );
}
