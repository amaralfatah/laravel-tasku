import { router } from '@inertiajs/react';
import { useCallback, useEffect } from 'react';
import {
    getSavedProjectFilter,
    hasTaskFilters,
    saveProjectFilter,
    toFilterQueryString,
} from '@/lib/project-filters';
import type { TaskFilterState } from '@/types/tasks';

/**
 * Keeps task filters in the URL query string (FLT-4) so a filtered view can be
 * shared and survives a back navigation, and persists state in localStorage so
 * filters are not lost when navigating between pages.
 */
export function useTaskFilters(
    current: TaskFilterState,
    baseUrl: string,
    projectId?: number,
) {
    useEffect(() => {
        if (!projectId || typeof window === 'undefined') {
            return;
        }

        const currentSearch = window.location.search;

        if (hasTaskFilters(currentSearch)) {
            saveProjectFilter(projectId, currentSearch);
        } else {
            const saved = getSavedProjectFilter(projectId);

            if (saved && hasTaskFilters(saved)) {
                const merged = new URLSearchParams(
                    saved.startsWith('?') ? saved.slice(1) : saved,
                );
                const currentParams = new URLSearchParams(
                    window.location.search,
                );
                currentParams.forEach((val, key) => {
                    if (!merged.has(key)) {
                        merged.set(key, val);
                    }
                });

                const finalQuery = merged.toString()
                    ? `?${merged.toString()}`
                    : '';

                if (finalQuery && finalQuery !== window.location.search) {
                    router.get(
                        `${baseUrl}${finalQuery}`,
                        {},
                        {
                            preserveState: true,
                            preserveScroll: true,
                            replace: true,
                        },
                    );
                }
            }
        }
    }, [projectId, baseUrl]);

    return useCallback(
        (patch: Partial<TaskFilterState>) => {
            const next = { ...current, ...patch };

            if (projectId) {
                const queryStr = toFilterQueryString(next);
                saveProjectFilter(projectId, queryStr);
            }

            router.get(
                baseUrl,
                {
                    assignee_id: next.assignee_id ?? undefined,
                    status: next.status ?? undefined,
                    priority: next.priority ?? undefined,
                    search: next.search || undefined,
                    sort: next.sort === 'wbs' ? undefined : next.sort,
                    overdue: next.overdue ? 1 : undefined,
                },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [current, baseUrl, projectId],
    );
}
