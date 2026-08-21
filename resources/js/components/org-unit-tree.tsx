import { ChevronRight, FolderTree, MoreHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { ORG_UNIT_TYPE_LABELS } from '@/types/organization';
import type { OrgUnitNode } from '@/types/organization';

type TreeNode = OrgUnitNode & { children: TreeNode[] };

/**
 * Builds the nested tree from the flat, path-ordered list the server sends.
 */
function buildTree(units: OrgUnitNode[]): TreeNode[] {
    const byId = new Map<number, TreeNode>();
    const roots: TreeNode[] = [];

    for (const unit of units) {
        byId.set(unit.id, { ...unit, children: [] });
    }

    for (const unit of units) {
        const node = byId.get(unit.id)!;
        const parent = unit.parent_id ? byId.get(unit.parent_id) : undefined;

        if (parent) {
            parent.children.push(node);
        } else {
            roots.push(node);
        }
    }

    return roots;
}

export function OrgUnitTree({
    units,
    canManage,
    maxDepth,
    onAddChild,
    onRename,
    onMove,
    onDelete,
}: {
    units: OrgUnitNode[];
    canManage: boolean;
    maxDepth: number;
    onAddChild: (unit: OrgUnitNode) => void;
    onRename: (unit: OrgUnitNode) => void;
    onMove: (unit: OrgUnitNode) => void;
    onDelete: (unit: OrgUnitNode) => void;
}) {
    const tree = useMemo(() => buildTree(units), [units]);
    const [collapsed, setCollapsed] = useState<Set<number>>(new Set());

    const toggle = (id: number) => {
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
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

    const renderNode = (node: TreeNode) => {
        const hasChildren = node.children.length > 0;
        const isCollapsed = collapsed.has(node.id);
        const canNest = node.depth < maxDepth;

        return (
            <li key={node.id}>
                <div
                    className="group flex min-h-11 items-center gap-2 rounded-md px-2 hover:bg-muted/60"
                    style={{
                        // Indentation shrinks as it gets deeper so 6 levels
                        // still fit on a narrow screen (R-7).
                        paddingLeft: `${node.depth * Math.max(10, 22 - node.depth * 2)}px`,
                    }}
                >
                    {hasChildren ? (
                        <button
                            type="button"
                            onClick={() => toggle(node.id)}
                            aria-expanded={!isCollapsed}
                            aria-label={
                                isCollapsed
                                    ? `Buka ${node.name}`
                                    : `Tutup ${node.name}`
                            }
                            className="flex size-6 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-muted"
                        >
                            <ChevronRight
                                className={cn(
                                    'size-4 transition-transform duration-150',
                                    !isCollapsed && 'rotate-90',
                                )}
                            />
                        </button>
                    ) : (
                        <span className="size-6 shrink-0" aria-hidden="true" />
                    )}

                    <span className="truncate font-medium">{node.name}</span>

                    <Badge variant="secondary" className="shrink-0 font-normal">
                        {ORG_UNIT_TYPE_LABELS[node.type] ?? node.type}
                    </Badge>

                    <span className="ml-auto hidden shrink-0 gap-4 text-xs text-muted-foreground tabular-nums sm:flex">
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

                {hasChildren && !isCollapsed && (
                    <ul>{node.children.map(renderNode)}</ul>
                )}
            </li>
        );
    };

    return (
        <ul className="p-2" role="tree" aria-label="Struktur organisasi">
            {tree.map(renderNode)}
        </ul>
    );
}
