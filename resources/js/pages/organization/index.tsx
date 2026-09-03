import { Head, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { OrgUnitSearch } from '@/components/org-unit-search';
import { OrgUnitTree } from '@/components/org-unit-tree';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    destroy as destroyUnit,
    masterSearch,
    search as scopedSearch,
    store as storeUnit,
    update as updateUnit,
} from '@/routes/org-units';
import { index as organizationIndex } from '@/routes/organization';
import { ORG_UNIT_TYPE_LABELS } from '@/types/organization';
import type { OrgUnitHit, OrgUnitNode } from '@/types/organization';

type UnitDialogMode = 'create' | 'rename' | 'move' | null;

export default function Organization({
    units,
    maxDepth,
    can,
    workspace,
}: {
    units: OrgUnitNode[];
    maxDepth: number;
    can: { manage: boolean; manage_roots: boolean };
    workspace: { id: number; name: string } | null;
}) {
    /*
     * The operator searches the untrimmed master tree; everyone else searches
     * their own branch. Both endpoints run the same query and return the same
     * shape — only the scope differs.
     */
    const searchEndpoint = can.manage_roots ? masterSearch : scopedSearch;
    const [unitDialog, setUnitDialog] = useState<UnitDialogMode>(null);
    const [target, setTarget] = useState<OrgUnitNode | null>(null);
    const [revealPath, setRevealPath] = useState<string | null>(null);
    const [moveTo, setMoveTo] = useState<OrgUnitHit | null>(null);

    // Bumped after every saved change, so the tree drops the branches it had
    // already fetched and loads them again.
    const [resetKey, setResetKey] = useState(0);

    const unitForm = useForm({
        name: '',
        type: 'division',
        parent_id: null as number | null,
    });

    const openCreate = (parent: OrgUnitNode | null) => {
        setTarget(parent);
        unitForm.setDefaults({
            name: '',
            type: parent === null ? 'division' : 'sub_division',
            parent_id: parent?.id ?? null,
        });
        unitForm.reset();
        unitForm.clearErrors();
        setUnitDialog('create');
    };

    const openRename = (unit: OrgUnitNode) => {
        setTarget(unit);
        unitForm.setDefaults({
            name: unit.name,
            type: unit.type,
            parent_id: unit.parent_id,
        });
        unitForm.reset();
        unitForm.clearErrors();
        setUnitDialog('rename');
    };

    const openMove = (unit: OrgUnitNode) => {
        setTarget(unit);
        setMoveTo(null);
        unitForm.setDefaults({
            name: unit.name,
            type: unit.type,
            parent_id: unit.parent_id,
        });
        unitForm.reset();
        unitForm.clearErrors();
        setUnitDialog('move');
    };

    const afterSave = () => {
        setUnitDialog(null);
        setTarget(null);
        setMoveTo(null);
        setResetKey((current) => current + 1);
    };

    const submitUnit = () => {
        if (unitDialog === 'create') {
            unitForm.post(storeUnit().url, {
                preserveScroll: true,
                onSuccess: afterSave,
            });

            return;
        }

        if (target) {
            unitForm.patch(updateUnit(target.id).url, {
                preserveScroll: true,
                onSuccess: afterSave,
            });
        }
    };

    const deleteUnit = (unit: OrgUnitNode) => {
        if (
            !confirm(
                `Hapus unit "${unit.name}"? Unit hanya bisa dihapus jika tidak punya sub unit atau project.`,
            )
        ) {
            return;
        }

        router.delete(destroyUnit(unit.id).url, {
            preserveScroll: true,
            onSuccess: afterSave,
        });
    };

    return (
        <>
            <Head title="Organisasi" />

            <div className="space-y-6">
                <PageHeader
                    title="Organisasi"
                    description={
                        can.manage_roots
                            ? 'Data master struktur organisasi, dicerminkan dari SAP dan dipakai seluruh workspace.'
                            : `Struktur ${workspace?.name ?? 'workspace ini'}. Tambahkan divisi, subdivisi, dan unit sesuai kebutuhan Anda sendiri.`
                    }
                />

                <section className="space-y-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="font-medium">Struktur unit</h2>

                        {/*
                         * A root is an operating entity, which the operator
                         * hands out. A customer grows their own branch from the
                         * node they were given, using the row actions.
                         */}
                        {can.manage && can.manage_roots && (
                            <Button size="sm" onClick={() => openCreate(null)}>
                                <Plus className="size-4" aria-hidden="true" />
                                Unit baru
                            </Button>
                        )}
                    </div>

                    <OrgUnitSearch
                        endpoint={searchEndpoint}
                        onSelect={(hit) => setRevealPath(hit.path)}
                        placeholder={
                            can.manage_roots
                                ? 'Cari unit di seluruh struktur…'
                                : 'Cari unit di struktur Anda…'
                        }
                        emptyHint="Ketik minimal 2 huruf untuk mencari unit tanpa membuka satu per satu."
                    />

                    <div className="rounded-lg border">
                        <OrgUnitTree
                            units={units}
                            canManage={can.manage}
                            maxDepth={maxDepth}
                            revealPath={revealPath}
                            resetKey={resetKey}
                            onAddChild={openCreate}
                            onRename={openRename}
                            onMove={openMove}
                            onDelete={deleteUnit}
                        />
                    </div>
                </section>
            </div>

            <Dialog
                open={unitDialog !== null}
                onOpenChange={(open) => !open && setUnitDialog(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {unitDialog === 'create' && 'Tambah unit'}
                            {unitDialog === 'rename' && 'Ubah unit'}
                            {unitDialog === 'move' && 'Pindahkan unit'}
                        </DialogTitle>
                        <DialogDescription>
                            {unitDialog === 'create' && target
                                ? `Unit baru akan berada di bawah ${target.name}.`
                                : unitDialog === 'move' && target
                                  ? `Cari unit induk baru untuk ${target.name}.`
                                  : 'Maksimal ' +
                                    (maxDepth + 1) +
                                    ' tingkat kedalaman.'}
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            submitUnit();
                        }}
                    >
                        {unitDialog !== 'move' && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="unit-name">Nama unit</Label>
                                    <Input
                                        id="unit-name"
                                        value={unitForm.data.name}
                                        onChange={(event) =>
                                            unitForm.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        autoFocus
                                        placeholder="Divisi Engineering"
                                    />
                                    <InputError
                                        message={unitForm.errors.name}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="unit-type">Jenis</Label>
                                    <Select
                                        value={unitForm.data.type}
                                        onValueChange={(value) =>
                                            unitForm.setData('type', value)
                                        }
                                    >
                                        <SelectTrigger id="unit-type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(
                                                ORG_UNIT_TYPE_LABELS,
                                            ).map(([value, label]) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={unitForm.errors.type}
                                    />
                                </div>
                            </>
                        )}

                        {unitDialog === 'move' && (
                            <div className="grid gap-2">
                                <Label>Unit induk baru</Label>

                                <div className="flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm">
                                    <span className="truncate">
                                        {moveTo
                                            ? moveTo.name
                                            : unitForm.data.parent_id === null
                                              ? 'Tanpa induk (unit teratas)'
                                              : 'Belum dipilih'}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            setMoveTo(null);
                                            unitForm.setData('parent_id', null);
                                        }}
                                    >
                                        Jadikan unit teratas
                                    </Button>
                                </div>

                                {/* A unit cannot be moved into itself or its own subtree. */}
                                <OrgUnitSearch
                                    autoFocus
                                    endpoint={searchEndpoint}
                                    excludeSubtreeOf={target?.path ?? null}
                                    placeholder="Cari unit induk…"
                                    onSelect={(hit) => {
                                        setMoveTo(hit);
                                        unitForm.setData('parent_id', hit.id);
                                    }}
                                />

                                <InputError
                                    message={unitForm.errors.parent_id}
                                />
                            </div>
                        )}

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setUnitDialog(null)}
                            >
                                Batal
                            </Button>
                            <Button
                                type="submit"
                                disabled={unitForm.processing}
                            >
                                {unitForm.processing ? 'Menyimpan…' : 'Simpan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

Organization.layout = {
    breadcrumbs: [{ title: 'Organisasi', href: organizationIndex() }],
};
