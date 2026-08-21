import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { update } from '@/routes/members';
import type {
    MemberRow,
    NamedRef,
    Option,
    OrgUnitOption,
} from '@/types/members';

const NONE = 'none';

/**
 * Edits everything about a membership except the person: role (Owner only),
 * position, unit assignment and monitoring scope.
 */
export function MemberEditDialog({
    member,
    orgUnits,
    positions,
    roles,
    scopeTypes,
    canChangeRole,
    onClose,
}: {
    member: MemberRow | null;
    orgUnits: OrgUnitOption[];
    positions: NamedRef[];
    roles: Option[];
    scopeTypes: Option[];
    canChangeRole: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        role: member?.role ?? 'member',
        position_id: member?.position?.id ?? null,
        org_unit_id: member?.org_unit?.id ?? null,
        scope_type: member?.scope_type ?? 'project_only',
        scope_org_unit_id: member?.scope_org_unit?.id ?? null,
    });

    useEffect(() => {
        if (member) {
            form.setDefaults({
                role: member.role,
                position_id: member.position?.id ?? null,
                org_unit_id: member.org_unit?.id ?? null,
                scope_type: member.scope_type,
                scope_org_unit_id: member.scope_org_unit?.id ?? null,
            });
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [member?.id]);

    if (!member) {
        return null;
    }

    const isSubtree = form.data.scope_type === 'unit_subtree';
    const roleLocked = !canChangeRole || member.is_last_owner;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{member.user.name}</DialogTitle>
                    <DialogDescription>{member.user.email}</DialogDescription>
                </DialogHeader>

                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(update(member.id).url, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="member-role">Role</Label>
                        <Select
                            value={form.data.role}
                            onValueChange={(value) =>
                                form.setData(
                                    'role',
                                    value as MemberRow['role'],
                                )
                            }
                            disabled={roleLocked}
                        >
                            <SelectTrigger id="member-role">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {roles.map((role) => (
                                    <SelectItem
                                        key={role.value}
                                        value={role.value}
                                    >
                                        {role.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            {member.is_last_owner
                                ? 'Owner terakhir tidak bisa diturunkan rolenya.'
                                : canChangeRole
                                  ? 'Role menentukan hak akses sistem.'
                                  : 'Hanya Owner yang dapat mengubah role.'}
                        </p>
                        <InputError message={form.errors.role} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="member-position">Jabatan</Label>
                        <Select
                            value={String(form.data.position_id ?? NONE)}
                            onValueChange={(value) =>
                                form.setData(
                                    'position_id',
                                    value === NONE ? null : Number(value),
                                )
                            }
                        >
                            <SelectTrigger id="member-position">
                                <SelectValue placeholder="Tanpa jabatan" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>
                                    Tanpa jabatan
                                </SelectItem>
                                {positions.map((position) => (
                                    <SelectItem
                                        key={position.id}
                                        value={String(position.id)}
                                    >
                                        {position.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.position_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="member-unit">Unit penempatan</Label>
                        <Select
                            value={String(form.data.org_unit_id ?? NONE)}
                            onValueChange={(value) =>
                                form.setData(
                                    'org_unit_id',
                                    value === NONE ? null : Number(value),
                                )
                            }
                        >
                            <SelectTrigger id="member-unit">
                                <SelectValue placeholder="Belum ditempatkan" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>
                                    Belum ditempatkan
                                </SelectItem>
                                {orgUnits.map((unit) => (
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
                        <InputError message={form.errors.org_unit_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="member-scope">
                            Cakupan pemantauan
                        </Label>
                        <Select
                            value={form.data.scope_type}
                            onValueChange={(value) =>
                                form.setData(
                                    'scope_type',
                                    value as MemberRow['scope_type'],
                                )
                            }
                        >
                            <SelectTrigger id="member-scope">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {scopeTypes.map((scope) => (
                                    <SelectItem
                                        key={scope.value}
                                        value={scope.value}
                                    >
                                        {scope.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            Cakupan subtree memberi akses baca ke seluruh
                            project unit tersebut dan turunannya. Untuk hak
                            edit, anggota tetap harus didaftarkan di project.
                        </p>
                        <InputError message={form.errors.scope_type} />
                    </div>

                    {isSubtree && (
                        <div className="grid gap-2">
                            <Label htmlFor="member-scope-unit">
                                Akar cakupan
                            </Label>
                            <Select
                                value={String(
                                    form.data.scope_org_unit_id ?? NONE,
                                )}
                                onValueChange={(value) =>
                                    form.setData(
                                        'scope_org_unit_id',
                                        value === NONE ? null : Number(value),
                                    )
                                }
                            >
                                <SelectTrigger id="member-scope-unit">
                                    <SelectValue placeholder="Pilih unit" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        Pilih unit
                                    </SelectItem>
                                    {orgUnits.map((unit) => (
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
                                message={form.errors.scope_org_unit_id}
                            />
                        </div>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
