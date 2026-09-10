import type { TaskFilterState } from '@/types/tasks';

const STORAGE_PREFIX = 'tasku:project-filters:';

/**
 * Filter keys that represent active task filtering in query parameters.
 */
export const TASK_FILTER_KEYS = [
    'assignee_id',
    'status',
    'priority',
    'search',
    'sort',
    'overdue',
] as const;

/**
 * Check if the given query string has any active task filter.
 */
export function hasTaskFilters(searchString: string): boolean {
    if (!searchString) {
        return false;
    }

    const clean = searchString.startsWith('?')
        ? searchString.slice(1)
        : searchString;

    if (!clean) {
        return false;
    }

    const params = new URLSearchParams(clean);

    return TASK_FILTER_KEYS.some((key) => {
        const val = params.get(key);

        if (val === null || val === '') {
            return false;
        }

        if (key === 'sort' && val === 'wbs') {
            return false;
        }

        return true;
    });
}

/**
 * Get saved project filter query string from localStorage.
 */
export function getSavedProjectFilter(projectId: number): string {
    if (typeof window === 'undefined') {
        return '';
    }

    try {
        return (
            window.localStorage.getItem(`${STORAGE_PREFIX}${projectId}`) || ''
        );
    } catch {
        return '';
    }
}

/**
 * Save or clear project filter query string in localStorage.
 */
export function saveProjectFilter(projectId: number, query: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        const key = `${STORAGE_PREFIX}${projectId}`;
        const clean = query.trim();

        if (clean && clean !== '?' && hasTaskFilters(clean)) {
            window.localStorage.setItem(
                key,
                clean.startsWith('?') ? clean : `?${clean}`,
            );
        } else {
            window.localStorage.removeItem(key);
        }
    } catch {
        // Ignore storage errors in restricted contexts.
    }
}

/**
 * Convert TaskFilterState patch/object to query string.
 */
export function toFilterQueryString(filters: Partial<TaskFilterState>): string {
    const params = new URLSearchParams();

    if (filters.assignee_id != null) {
        params.set('assignee_id', String(filters.assignee_id));
    }

    if (filters.status) {
        params.set('status', filters.status);
    }

    if (filters.priority) {
        params.set('priority', filters.priority);
    }

    if (filters.search) {
        params.set('search', filters.search);
    }

    if (filters.sort && filters.sort !== 'wbs') {
        params.set('sort', filters.sort);
    }

    if (filters.overdue) {
        params.set('overdue', '1');
    }

    const str = params.toString();

    return str ? `?${str}` : '';
}
