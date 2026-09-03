import {
    ChevronRight,
    FolderTree,
    Loader2,
    MoreHorizontal,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { children as childrenRoute } from '@/routes/org-units';
import { ORG_UNIT_TYPE_LABELS, unitIdsOnPath } from '@/types/organization';
import type { OrgUnitNode } from '@/types/organization';

/** Children already fetched, keyed by parent id. */
type ChildCache = Record<number, OrgUnitNode[]>;

/**
 * Everything fetched so far, stamped with the `resetKey` it was fetched under.
 * A bumped key retires the whole set in the same render that carries the bump,
 * which is why the drop needs no effect.
 */
type Branches = { stamp: number; cache: ChildCache; failed: Set<number> };

const EMPTY_CACHE: ChildCache = {};

const EMPTY_FAILED: Set<number> = new Set();

function without(ids: Set<number>, id: number): Set<number> {
    const next = new Set(ids);

    next.delete(id);

    return next;
}

async function fetchChildren(id: number): Promise<OrgUnitNode[]> {
    const response = await fetch(childrenRoute(id).url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Gagal memuat sub unit (${response.status}).`);
    }

    const payload: { units: OrgUnitNode[] } = await response.json();

    return payload.units;
}

/**
 * The org tree, loaded one level at a time.
 *
 * The SAP import fills a workspace with tens of thousands of units, so the
 * page ships only the top level and each branch is fetched the first time it
 * is opened. `revealPath` opens every ancestor of a searched unit, which works
 * because opening a branch is what triggers its fetch.
 */
export function OrgUnitTree({
    units,
    canManage,
    maxDepth,
    revealPath,
    resetKey = 0,
    onAddChild,
    onRename,
    onMove,
    onDelete,
}: {
    units: OrgUnitNode[];
    canManage: boolean;
    maxDepth: number;
    /** Materialized path of a unit to expand to and highlight. */
    revealPath?: string | null;
    /** Bump to drop every cached branch after a change was saved. */
    resetKey?: number;
    onAddChild: (unit: OrgUnitNode) => void;
    onRename: (unit: OrgUnitNode) => void;
    onMove: (unit: OrgUnitNode) => void;
    onDelete: (unit: OrgUnitNode) => void;
}) {
    const [branches, setBranches] = useState<Branches>({
        stamp: resetKey,
        cache: {},
        failed: new Set(),
    });

    // Branches the viewer opened and closed by hand. `revealPath` adds its own
    // ancestors on top, and closing one of those has to survive, so the two
    // sets are kept apart instead of merged into one `expanded`.
    const [opened, setOpened] = useState<Set<number>>(new Set());
    const [closed, setClosed] = useState<Set<number>>(new Set());

    // A saved change can move or rename anything below, so a bumped `resetKey`
    // retires every cached branch; the still-open ones refetch through the
    // effect further down.
    const current = branches.stamp === resetKey ? branches : null;
    const cache = current?.cache ?? EMPTY_CACHE;
    const failed = current?.failed ?? EMPTY_FAILED;

    const highlighted = revealPath
        ? unitIdsOnPath(revealPath).at(-1)
        : undefined;
    const highlightRef = useRef<HTMLDivElement | null>(null);

    // In-flight ids live in a ref, not state: a fetch that has only been
    // started is not something the tree renders, and marking it in state would
    // mean writing state from the effect that starts it.
    const inFlight = useRef<Set<string>>(new Set());

    const writeBranches = useCallback(
        (
            update: (held: Omit<Branches, 'stamp'>) => Omit<Branches, 'stamp'>,
        ) => {
            setBranches((held) => ({
                stamp: resetKey,
                ...update(
                    held.stamp === resetKey
                        ? held
                        : { cache: EMPTY_CACHE, failed: EMPTY_FAILED },
                ),
            }));
        },
        [resetKey],
    );

    const load = useCallback(
        (id: number) => {
            // Stamped, so a branch retired by a bump is fetched again rather
            // than skipped as already in flight.
            const token = `${resetKey}:${id}`;

            if (inFlight.current.has(token)) {
                return;
            }

            inFlight.current.add(token);

            fetchChildren(id)
                .then((rows) => {
                    writeBranches((held) => ({
                        cache: { ...held.cache, [id]: rows },
                        failed: without(held.failed, id),
                    }));
                })
                .catch(() => {
                    writeBranches((held) => ({
                        cache: held.cache,
                        failed: new Set(held.failed).add(id),
                    }));
                })
                .finally(() => {
                    inFlight.current.delete(token);
                });
        },
        [resetKey, writeBranches],
    );

    const revealed = useMemo(
        () => (revealPath ? unitIdsOnPath(revealPath).slice(0, -1) : []),
        [revealPath],
    );

    const expanded = useMemo(() => {
        const next = new Set(opened);

        for (const id of revealed) {
            next.add(id);
        }

        for (const id of closed) {
            next.delete(id);
        }

        return next;
    }, [opened, revealed, closed]);

    // Fetch whatever is open but not loaded yet. Covers a plain click, the
    // cascade of ancestors opened by `revealPath`, and a bumped `resetKey`.
    useEffect(() => {
        for (const id of expanded) {
            if (!(id in cache) && !failed.has(id)) {
                load(id);
            }
        }
    }, [expanded, cache, failed, load]);

    useEffect(() => {
        highlightRef.current?.scrollIntoView({ block: 'center' });
    }, [highlighted, cache]);

    const toggle = (id: number) => {
        const isOpen = expanded.has(id);

        setOpened((held) =>
            isOpen ? without(held, id) : new Set(held).add(id),
        );
        setClosed((held) =>
            isOpen ? new Set(held).add(id) : without(held, id),
        );

        if (!isOpen) {
            // Opening again after a failure is how the viewer retries.
            writeBranches((held) => ({
                cache: held.cache,
                failed: without(held.failed, id),
            }));
        }
    };

    if (units.length === 0) {
        return (
            <div className="p-10 text-center">
                <FolderTree
                    className="mx-auto mb-3 size-8 text-muted-foreground"
                    aria-hidden="true"
                />
                <p className="font-medium">Struktur organisasi masih kosong</p>
                <p className="text-sm text-muted-foreground">
                    Mulai dari unit teratas, misalnya divisi utama perusahaan.
                </p>
            </div>
        );
    }

    const renderNode = (node: OrgUnitNode) => {
        const hasChildren = node.children_count > 0;
        const isOpen = expanded.has(node.id);
        const didFail = failed.has(node.id);
        const rows = cache[node.id];
        const isLoading = isOpen && rows === undefined && !didFail;
        const canNest = node.depth < maxDepth;
        const isHighlighted = node.id === highlighted;

        return (
            <li key={node.id}>
                <div
                    ref={isHighlighted ? highlightRef : undefined}
                    className={cn(
                        'group flex min-h-11 items-center gap-2 rounded-md px-2 hover:bg-muted/60',
                        isHighlighted && 'bg-primary/10 ring-1 ring-primary/40',
                    )}
                    style={{
                        // Indentation shrinks as it gets deeper so twelve
                        // levels still fit on a narrow screen (R-7).
                        paddingLeft: `${node.depth * Math.max(8, 22 - node.depth * 2)}px`,
                    }}
                >
                    {hasChildren ? (
                        <button
                            type="button"
                            onClick={() => toggle(node.id)}
                            aria-expanded={isOpen}
                            aria-label={
                                isOpen
                                    ? `Tutup ${node.name}`
                                    : `Buka ${node.name}`
                            }
                            className="flex size-6 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-muted"
                        >
                            {isLoading ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <ChevronRight
                                    className={cn(
                                        'size-4 transition-transform duration-150',
                                        isOpen && 'rotate-90',
                                    )}
                                />
                            )}
                        </button>
                    ) : (
                        <span className="size-6 shrink-0" aria-hidden="true" />
                    )}

                    <span className="truncate font-medium">{node.name}</span>

                    <Badge variant="secondary" className="shrink-0 font-normal">
                        {ORG_UNIT_TYPE_LABELS[node.type] ?? node.type}
                    </Badge>

                    <span className="ml-auto hidden shrink-0 gap-4 text-xs text-muted-foreground tabular-nums sm:flex">
                        {hasChildren && (
                            <span>{node.children_count} sub unit</span>
                        )}
                        <span>{node.members_count} anggota</span>
                        <span>{node.projects_count} project</span>
                    </span>

                    {canManage && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8 shrink-0"
                                    aria-label={`Aksi untuk ${node.name}`}
                                >
                                    <MoreHorizontal className="size-4" />
                                </Button>
                            </DropdownMenuTrigger>

                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    disabled={!canNest}
                                    onSelect={() => onAddChild(node)}
                                >
                                    Tambah sub unit
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() => onRename(node)}
                                >
                                    Ubah nama
                                </DropdownMenuItem>
                                <DropdownMenuItem onSelect={() => onMove(node)}>
                                    Pindahkan
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => onDelete(node)}
                                >
                                    Hapus
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>

                {isOpen && didFail && (
                    <p
                        className="py-1 text-sm text-destructive"
                        style={{
                            paddingLeft: `${(node.depth + 1) * 14 + 8}px`,
                        }}
                    >
                        Sub unit gagal dimuat.{' '}
                        <button
                            type="button"
                            className="underline underline-offset-2"
                            onClick={() => load(node.id)}
                        >
                            Coba lagi
                        </button>
                    </p>
                )}

                {isOpen && rows && <ul>{rows.map(renderNode)}</ul>}
            </li>
        );
    };

    return (
        <ul className="p-2" role="tree" aria-label="Struktur organisasi">
            {units.map(renderNode)}
        </ul>
    );
}
