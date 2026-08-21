import { Head, router, useForm } from '@inertiajs/react';
import { BriefcaseBusiness, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { OrgUnitTree } from '@/components/org-unit-tree';
import { Badge } from '@/components/ui/badge';
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
    store as storeUnit,
    update as updateUnit,
} from '@/routes/org-units';
import { index as organizationIndex } from '@/routes/organization';
import {
    destroy as destroyPosition,
    store as storePosition,
    update as updatePosition,
} from '@/routes/positions';
import { ORG_UNIT_TYPE_LABELS } from '@/types/organization';
import type { OrgUnitNode, PositionRow } from '@/types/organization';

type UnitDialogMode = 'create' | 'rename' | 'move' | null;

export default function Organization({
    units,
    positions,
    maxDepth,
    can,
}: {
    units: OrgUnitNode[];
    positions: PositionRow[];
    maxDepth: number;
    can: { manage: boolean };
}) {
    const [unitDialog, setUnitDialog] = useState<UnitDialogMode>(null);
    const [target, setTarget] = useState<OrgUnitNode | null>(null);
    const [positionDialog, setPositionDialog] = useState(false);
    const [editingPosition, setEditingPosition] = useState<PositionRow | null>(
        null,
    );

    const unitForm = useForm({
        name: '',
        type: 'division',
        parent_id: null as number | null,
    });

    const positionForm = useForm({ name: '', level: 1 });

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
        unitForm.setDefaults({
            name: unit.name,
            type: unit.type,
            parent_id: unit.parent_id,
        });
        unitForm.reset();
        unitForm.clearErrors();
        setUnitDialog('move');
    };

    const submitUnit = () => {
        const onSuccess = () => {
            setUnitDialog(null);
            setTarget(null);
        };

        if (unitDialog === 'create') {
            unitForm.post(storeUnit().url, {
                preserveScroll: true,
                onSuccess,
            });

            return;
        }

        if (target) {
            unitForm.patch(updateUnit(target.id).url, {
                preserveScroll: true,
                onSuccess,
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

        router.delete(destroyUnit(unit.id).url, { preserveScroll: true });
    };

    const openPosition = (position: PositionRow | null) => {
        setEditingPosition(position);
        positionForm.setDefaults({
            name: position?.name ?? '',
            level: position?.level ?? 1,
        });
        positionForm.reset();
        positionForm.clearErrors();
        setPositionDialog(true);
    };

    const submitPosition = () => {
        const onSuccess = () => {
            setPositionDialog(false);
            setEditingPosition(null);
        };

        if (editingPosition) {
            positionForm.patch(updatePosition(editingPosition.id).url, {
                preserveScroll: true,
                onSuccess,
            });

            return;
        }

        positionForm.post(storePosition().url, {
            preserveScroll: true,
            onSuccess,
        });
    };

    // A unit cannot be moved into itself or its own subtree.
    const moveTargets = units.filter(
        (unit) =>
            target === null ||
            (unit.id !== target.id && !unit.path.startsWith(target.path)),
    );

    return (
        <>
            <Head title="Organisasi" />

            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Organisasi</h1>
                    <p className="text-sm text-muted-foreground">
                        Struktur unit dan daftar jabatan di workspace ini.
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="space-y-3 lg:col-span-2">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="font-medium">Struktur unit</h2>

                            {can.manage && (
                                <Button
                                    size="sm"
                                    onClick={() => openCreate(null)}
                                >
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Unit baru
                                </Button>
                            )}
                        </div>

                        <div className="rounded-lg border">
                            <OrgUnitTree
                                units={units}
                                canManage={can.manage}
                                maxDepth={maxDepth}
                                onAddChild={openCreate}
                                onRename={openRename}
                                onMove={openMove}
                                onDelete={deleteUnit}
                            />
                        </div>
                    </section>

                    <section className="space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="font-medium">Jabatan</h2>

                            {can.manage && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => openPosition(null)}
                                >
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Jabatan
                                </Button>
                            )}
                        </div>

                        <div className="rounded-lg border">
                            {positions.length === 0 ? (
                                <div className="p-8 text-center">
                                    <BriefcaseBusiness
                                        className="mx-auto mb-3 size-8 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <p className="font-medium">
                                        Belum ada jabatan
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Tambahkan jabatan seperti Kepala Divisi
                                        atau Programmer.
                                    </p>
                                </div>
                            ) : (
                                <ul className="divide-y">
                                    {positions.map((position) => (
                                        <li
                                            key={position.id}
                                            className="flex min-h-14 items-center gap-2 px-3"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate font-medium">
                                                    {position.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {position.members_count}{' '}
                                                    anggota
                                                </p>
                                            </div>

                                            <Badge
                                                variant="secondary"
                                                className="shrink-0 font-normal tabular-nums"
                                            >
                                                Tingkat {position.level}
                                            </Badge>

                                            {can.manage && (
                                                <div className="flex shrink-0">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        aria-label={`Ubah ${position.name}`}
                                                        onClick={() =>
                                                            openPosition(
                                                                position,
                                                            )
                                                        }
                                                    >
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8 text-destructive hover:text-destructive"
                                                        aria-label={`Hapus ${position.name}`}
                                                        onClick={() => {
                                                            if (
                                                                confirm(
                                                                    `Hapus jabatan "${position.name}"?`,
                                                                )
                                                            ) {
                                                                router.delete(
                                                                    destroyPosition(
                                                                        position.id,
                                                                    ).url,
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </section>
                </div>
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
                                <Label htmlFor="unit-parent">Unit induk</Label>
                                <Select
                                    value={String(
                                        unitForm.data.parent_id ?? 'root',
                                    )}
                                    onValueChange={(value) =>
                                        unitForm.setData(
                                            'parent_id',
                                            value === 'root'
                                                ? null
                                                : Number(value),
                                        )
                                    }
                                >
                                    <SelectTrigger id="unit-parent">
                                        <SelectValue placeholder="Pilih unit induk" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="root">
                                            Tanpa induk (unit teratas)
                                        </SelectItem>
                                        {moveTargets.map((unit) => (
                                            <SelectItem
                                                key={unit.id}
                                                value={String(unit.id)}
                                            >
                                                {'— '.repeat(unit.depth)}
                                                {unit.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
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

            <Dialog open={positionDialog} onOpenChange={setPositionDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {editingPosition
                                ? 'Ubah jabatan'
                                : 'Tambah jabatan'}
                        </DialogTitle>
                        <DialogDescription>
                            Tingkat 1 adalah jabatan tertinggi. Tingkat hanya
                            memengaruhi urutan tampilan.
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            submitPosition();
                        }}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="position-name">Nama jabatan</Label>
                            <Input
                                id="position-name"
                                value={positionForm.data.name}
                                onChange={(event) =>
                                    positionForm.setData(
                                        'name',
                                        event.target.value,
                                    )
                                }
                                required
                                autoFocus
                                placeholder="Kepala Divisi"
                            />
                            <InputError message={positionForm.errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="position-level">Tingkat</Label>
                            <Input
                                id="position-level"
                                type="number"
                                min={1}
                                max={20}
                                value={positionForm.data.level}
                                onChange={(event) =>
                                    positionForm.setData(
                                        'level',
                                        Number(event.target.value),
                                    )
                                }
                                required
                            />
                            <InputError message={positionForm.errors.level} />
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setPositionDialog(false)}
                            >
                                Batal
                            </Button>
                            <Button
                                type="submit"
                                disabled={positionForm.processing}
                            >
                                {positionForm.processing
                                    ? 'Menyimpan…'
                                    : 'Simpan'}
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
