import { ChevronRight, FolderTree, Loader2, MoreHorizontal } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
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
    const [cache, setCache] = useState<ChildCache>({});
    const [expanded, setExpanded] = useState<Set<number>>(new Set());
    const [loading, setLoading] = useState<Set<number>>(new Set());
    const [failed, setFailed] = useState<Set<number>>(new Set());

    const highlighted = revealPath ? unitIdsOnPath(revealPath).at(-1) : undefined;
    const highlightRef = useRef<HTMLDivElement | null>(null);

    const load = useCallback((id: number) => {
        setLoading((current) => new Set(current).add(id));

        fetchChildren(id)
            .then((rows) => {
                setCache((current) => ({ ...current, [id]: rows }));
                setFailed((current) => {
                    const next = new Set(current);
                    next.delete(id);

                    return next;
                });
            })
            .catch(() => {
                setFailed((current) => new Set(current).add(id));
            })
            .finally(() => {
                setLoading((current) => {
                    const next = new Set(current);
                    next.delete(id);

                    return next;
                });
            });
    }, []);

    // A saved change can move or rename anything below, so every cached branch
    // is dropped and the still-open ones refetch through the effect below.
    useEffect(() => {
        if (resetKey > 0) {
            setCache({});
            setFailed(new Set());
        }
    }, [resetKey]);

    // Fetch whatever is open but not loaded yet. Covers both a plain click and
    // the cascade of ancestors opened by `revealPath`.
    useEffect(() => {
        for (const id of expanded) {
            if (!(id in cache) && !loading.has(id) && !failed.has(id)) {
                load(id);
            }
        }
    }, [expanded, cache, loading, failed, load]);

    useEffect(() => {
        if (!revealPath) {
            return;
        }

        const ancestors = unitIdsOnPath(revealPath).slice(0, -1);

        setExpanded((current) => {
            const next = new Set(current);
            ancestors.forEach((id) => next.add(id));

            return next;
        });
    }, [revealPath]);

    useEffect(() => {
        highlightRef.current?.scrollIntoView({ block: 'center' });
    }, [highlighted, cache]);

    const toggle = (id: number) => {
        setExpanded((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
                setFailed((stale) => {
                    const cleaned = new Set(stale);
                    cleaned.delete(id);

                    return cleaned;
                });
            }

            return next;
        });
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
        const isLoading = loading.has(node.id);
        const didFail = failed.has(node.id);
        const rows = cache[node.id];
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
                                isOpen ? `Tutup ${node.name}` : `Buka ${node.name}`
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
                        {hasChildren && <span>{node.children_count} sub unit</span>}
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
                                <DropdownMenuItem onSelect={() => onRename(node)}>
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
                        style={{ paddingLeft: `${(node.depth + 1) * 14 + 8}px` }}
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
