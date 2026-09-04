import { ListFilter, Search, TriangleAlert, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/members';
import type {
    TaskAssignee,
    TaskFilterState,
    TaskPriority,
    TaskStatus,
} from '@/types/tasks';

const ALL = 'all';

/** Jira shows five faces beside the search box and rolls the rest into a menu. */
const VISIBLE_FACES = 5;

/**
 * Filter bar shared by the task views (FLT-1..FLT-4).
 *
 * Shaped like Jira's board toolbar rather than a row of labelled selects: a
 * narrow search, the people on the project as a stack of faces that filter by
 * assignee on click, and everything else behind one "Filter" button that
 * carries a count while something is picked. Three wide dropdowns spanned half
 * the page and read as a form; this reads as a toolbar.
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
    const getInitials = useInitials();
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
        filters.overdue ||
        Boolean(filters.search);

    /** What the "Filter" button counts: the two fields the menu holds. */
    const pickedInMenu =
        (filters.status === null ? 0 : 1) + (filters.priority === null ? 0 : 1);

    /**
     * The picked person leads the stack, so a filter set from the overflow menu
     * is still visible without opening it again.
     */
    const picked = assignees.find(
        (assignee) => assignee.id === filters.assignee_id,
    );
    const ordered = picked
        ? [picked, ...assignees.filter((one) => one.id !== picked.id)]
        : assignees;
    const faces = ordered.slice(0, VISIBLE_FACES);
    const rest = ordered.slice(VISIBLE_FACES);

    const toggleAssignee = (id: number) =>
        onChange({ assignee_id: filters.assignee_id === id ? null : id });

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="relative w-full sm:w-56">
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

            {assignees.length > 0 && (
                <div className="flex items-center pl-1">
                    {faces.map((assignee) => {
                        const active = filters.assignee_id === assignee.id;

                        return (
                            <button
                                key={assignee.id}
                                type="button"
                                aria-pressed={active}
                                title={`Filter task ${assignee.name}`}
                                onClick={() => toggleAssignee(assignee.id)}
                                className={cn(
                                    // Overlapped the way Jira stacks them; the
                                    // ring is the card colour so the faces read
                                    // as one group rather than five buttons.
                                    '-ml-1 rounded-full ring-2 ring-background transition-transform hover:z-10 hover:-translate-y-0.5 focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                                    active && 'z-10 ring-primary',
                                )}
                            >
                                <Avatar className="size-7">
                                    <AvatarImage
                                        src={assignee.avatar ?? undefined}
                                        alt=""
                                    />
                                    <AvatarFallback className="bg-primary text-[10px] font-medium text-primary-foreground">
                                        {getInitials(assignee.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <span className="sr-only">{assignee.name}</span>
                            </button>
                        );
                    })}

                    {rest.length > 0 && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    title="Anggota lainnya"
                                    className="-ml-1 flex size-7 items-center justify-center rounded-full bg-muted text-[10px] font-medium text-muted-foreground ring-2 ring-background hover:z-10 hover:bg-accent focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                >
                                    +{rest.length}
                                    <span className="sr-only">
                                        Anggota lainnya
                                    </span>
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start">
                                <DropdownMenuRadioGroup
                                    value={
                                        filters.assignee_id === null
                                            ? ALL
                                            : String(filters.assignee_id)
                                    }
                                    onValueChange={(value) =>
                                        onChange({
                                            assignee_id:
                                                value === ALL
                                                    ? null
                                                    : Number(value),
                                        })
                                    }
                                >
                                    <DropdownMenuRadioItem value={ALL}>
                                        Semua penanggung jawab
                                    </DropdownMenuRadioItem>
                                    {rest.map((assignee) => (
                                        <DropdownMenuRadioItem
                                            key={assignee.id}
                                            value={String(assignee.id)}
                                        >
                                            {assignee.name}
                                        </DropdownMenuRadioItem>
                                    ))}
                                </DropdownMenuRadioGroup>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            )}

            {/* A leader watching a branch wants the tasks in trouble, not a
                healthy board, so this one filter sits in the open rather than
                behind the menu. */}
            <Button
                variant={filters.overdue ? 'default' : 'outline'}
                aria-pressed={filters.overdue}
                onClick={() => onChange({ overdue: !filters.overdue })}
            >
                <TriangleAlert className="size-4" aria-hidden="true" />
                Terlambat
            </Button>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline" aria-label="Filter task">
                        <ListFilter className="size-4" aria-hidden="true" />
                        Filter
                        {pickedInMenu > 0 && (
                            <span className="rounded-full bg-primary px-1.5 text-xs font-medium text-primary-foreground tabular-nums">
                                {pickedInMenu}
                            </span>
                        )}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-52">
                    <DropdownMenuLabel>Status</DropdownMenuLabel>
                    <DropdownMenuRadioGroup
                        value={filters.status ?? ALL}
                        onValueChange={(value) =>
                            onChange({
                                status:
                                    value === ALL
                                        ? null
                                        : (value as TaskStatus),
                            })
                        }
                    >
                        <DropdownMenuRadioItem value={ALL}>
                            Semua status
                        </DropdownMenuRadioItem>
                        {statuses.map((status) => (
                            <DropdownMenuRadioItem
                                key={status.value}
                                value={status.value}
                            >
                                {status.label}
                            </DropdownMenuRadioItem>
                        ))}
                    </DropdownMenuRadioGroup>

                    <DropdownMenuSeparator />

                    <DropdownMenuLabel>Prioritas</DropdownMenuLabel>
                    <DropdownMenuRadioGroup
                        value={filters.priority ?? ALL}
                        onValueChange={(value) =>
                            onChange({
                                priority:
                                    value === ALL
                                        ? null
                                        : (value as TaskPriority),
                            })
                        }
                    >
                        <DropdownMenuRadioItem value={ALL}>
                            Semua prioritas
                        </DropdownMenuRadioItem>
                        {priorities.map((priority) => (
                            <DropdownMenuRadioItem
                                key={priority.value}
                                value={priority.value}
                            >
                                {priority.label}
                            </DropdownMenuRadioItem>
                        ))}
                    </DropdownMenuRadioGroup>

                    {showSort && (
                        <>
                            <DropdownMenuSeparator />

                            <DropdownMenuLabel>Urutkan</DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                value={filters.sort}
                                onValueChange={(value) =>
                                    onChange({
                                        sort: value as TaskFilterState['sort'],
                                    })
                                }
                            >
                                <DropdownMenuRadioItem value="wbs">
                                    Urutan hierarki
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="due_date">
                                    Tanggal selesai
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="priority">
                                    Prioritas
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="created_at">
                                    Terbaru dibuat
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

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
                            overdue: false,
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
