import { Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Option } from '@/types/members';
import type {
    TaskAssignee,
    TaskFilterState,
    TaskPriority,
    TaskStatus,
} from '@/types/tasks';

const ALL = 'all';

/**
 * Filter bar shared by the task views (FLT-1..FLT-4).
 */
export function TaskFilterBar({
    filters,
    assignees,
    statuses,
    priorities,
    showSort = false,
    onChange,
}: {
    filters: TaskFilterState;
    assignees: TaskAssignee[];
    statuses: Option[];
    priorities: Option[];
    showSort?: boolean;
    onChange: (patch: Partial<TaskFilterState>) => void;
}) {
    const [search, setSearch] = useState(filters.search ?? '');

    // Debounce typing so each keystroke does not trigger a request.
    useEffect(() => {
        if ((filters.search ?? '') === search) {
            return;
        }

        const timer = setTimeout(() => onChange({ search }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters =
        filters.assignee_id !== null ||
        filters.status !== null ||
        filters.priority !== null ||
        Boolean(filters.search);

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-52 flex-1 sm:max-w-64">
                <Search
                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Cari judul task"
                    aria-label="Cari judul task"
                    className="pl-9"
                />
            </div>

            <Select
                value={
                    filters.assignee_id === null
                        ? ALL
                        : String(filters.assignee_id)
                }
                onValueChange={(value) =>
                    onChange({
                        assignee_id: value === ALL ? null : Number(value),
                    })
                }
            >
                <SelectTrigger
                    className="w-44"
                    aria-label="Filter penanggung jawab"
                >
                    <SelectValue placeholder="Semua penanggung jawab" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>Semua penanggung jawab</SelectItem>
                    {assignees.map((assignee) => (
                        <SelectItem
                            key={assignee.id}
                            value={String(assignee.id)}
                        >
                            {assignee.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Select
                value={filters.status ?? ALL}
                onValueChange={(value) =>
                    onChange({
                        status: value === ALL ? null : (value as TaskStatus),
                    })
                }
            >
                <SelectTrigger className="w-36" aria-label="Filter status">
                    <SelectValue placeholder="Semua status" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>Semua status</SelectItem>
                    {statuses.map((status) => (
                        <SelectItem key={status.value} value={status.value}>
                            {status.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Select
                value={filters.priority ?? ALL}
                onValueChange={(value) =>
                    onChange({
                        priority:
                            value === ALL ? null : (value as TaskPriority),
                    })
                }
            >
                <SelectTrigger className="w-36" aria-label="Filter prioritas">
                    <SelectValue placeholder="Semua prioritas" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>Semua prioritas</SelectItem>
                    {priorities.map((priority) => (
                        <SelectItem key={priority.value} value={priority.value}>
                            {priority.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {showSort && (
                <Select
                    value={filters.sort}
                    onValueChange={(value) =>
                        onChange({ sort: value as TaskFilterState['sort'] })
                    }
                >
                    <SelectTrigger className="w-40" aria-label="Urutkan">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="wbs">Urutan hierarki</SelectItem>
                        <SelectItem value="due_date">
                            Tanggal selesai
                        </SelectItem>
                        <SelectItem value="priority">Prioritas</SelectItem>
                        <SelectItem value="created_at">
                            Terbaru dibuat
                        </SelectItem>
                    </SelectContent>
                </Select>
            )}

            {hasFilters && (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setSearch('');
                        onChange({
                            assignee_id: null,
                            status: null,
                            priority: null,
                            search: null,
                        });
                    }}
                >
                    <X className="size-4" aria-hidden="true" />
                    Reset
                </Button>
            )}
        </div>
    );
}
