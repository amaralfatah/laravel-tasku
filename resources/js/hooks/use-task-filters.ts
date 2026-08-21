import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import type { TaskFilterState } from '@/types/tasks';

/**
 * Keeps task filters in the URL query string (FLT-4) so a filtered view can be
 * shared and survives a back navigation.
 */
export function useTaskFilters(current: TaskFilterState, baseUrl: string) {
    return useCallback(
        (patch: Partial<TaskFilterState>) => {
            const next = { ...current, ...patch };

            router.get(
                baseUrl,
                {
                    assignee_id: next.assignee_id ?? undefined,
                    status: next.status ?? undefined,
                    priority: next.priority ?? undefined,
                    search: next.search || undefined,
                    sort: next.sort === 'wbs' ? undefined : next.sort,
                },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [current, baseUrl],
    );
}
