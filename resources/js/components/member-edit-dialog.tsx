import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { OrgUnitPicker } from '@/components/org-unit-picker';
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
    OrgUnitPickerProps,
} from '@/types/members';

/**
 * Edits everything about a membership except the person: their role and the
 * unit they sit in. The unit is what decides how much of the org tree a
 * leader reaches, so it is the only scope control there is.
 */
export function MemberEditDialog({
    member,
    unitPicker,
    roles,
    onClose,
}: {
    member: MemberRow | null;
    unitPicker: OrgUnitPickerProps;
    roles: Option[];
    onClose: () => void;
}) {
    const form = useForm({
        role: member?.role ?? 'bod_4',
        org_unit_id: member?.org_unit?.id ?? null,
    });

    // The form only carries the id; the picker needs a name to show.
    const [unit, setUnit] = useState<NamedRef | null>(member?.org_unit ?? null);

    useEffect(() => {
        if (member) {
            form.setDefaults({
                role: member.role,
                org_unit_id: member.org_unit?.id ?? null,
            });
            form.reset();
            form.clearErrors();
            setUnit(member.org_unit ?? null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [member?.id]);

    if (!member) {
        return null;
    }

    const roleLocked = !member.can_change_role;

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
                                form.setData('role', value as MemberRow['role'])
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
                                        <span className="flex items-center gap-2">
                                            <span className="font-mono text-[10px] text-muted-foreground tabular-nums">
                                                {role.code}
                                            </span>
                                            {role.label}
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            {member.is_last_top_role
                                ? 'Kepala Divisi terakhir tidak bisa diturunkan rolenya.'
                                : roleLocked
                                  ? 'Anda tidak bisa mengubah role orang yang setara atau di atas Anda.'
                                  : 'Role menentukan posisi di jenjang BOD; cakupannya mengikuti unit penempatan.'}
                        </p>
                        <InputError message={form.errors.role} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Unit penempatan</Label>
                        <OrgUnitPicker
                            value={unit}
                            canChoose={unitPicker.can_choose}
                            emptyLabel="Belum ditempatkan"
                            clearLabel="Kosongkan"
                            onChange={(picked) => {
                                setUnit(picked);
                                form.setData('org_unit_id', picked?.id ?? null);
                            }}
                        />
                        <p className="text-xs text-muted-foreground">
                            Seorang pemimpin memantau dan mengelola unit ini
                            beserta seluruh turunannya.
                        </p>
                        <InputError message={form.errors.org_unit_id} />
                    </div>

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
