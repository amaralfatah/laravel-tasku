import { Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, MailPlus, MoreHorizontal, Users } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { MemberEditDialog } from '@/components/member-edit-dialog';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { UserInfo } from '@/components/user-info';
import { useClipboard } from '@/hooks/use-clipboard';
import {
    destroy as destroyInvitation,
    resend as resendInvitation,
    store as storeInvitation,
} from '@/routes/invitations';
import {
    destroy as destroyMember,
    index as membersIndex,
} from '@/routes/members';
import type { User } from '@/types';
import type {
    InvitationRow,
    MemberRow,
    Option,
    OrgUnitPickerProps,
} from '@/types/members';

export default function Members({
    members,
    invitations,
    unitPicker,
    roles,
    can,
}: {
    members: MemberRow[];
    invitations: InvitationRow[];
    unitPicker: OrgUnitPickerProps;
    roles: Option[];
    can: { manage: boolean };
}) {
    const [editing, setEditing] = useState<MemberRow | null>(null);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [copied, copy] = useClipboard();

    const inviteForm = useForm({ email: '', role: 'bod_4' });

    return (
        <>
            <Head title="Anggota" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <PageHeader
                        title="Anggota"
                        description={`${members.length} anggota aktif di workspace ini.`}
                    />

                    {can.manage && (
                        <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
                            <DialogTrigger asChild>
                                <Button>
                                    <MailPlus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Undang anggota
                                </Button>
                            </DialogTrigger>

                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Undang anggota</DialogTitle>
                                    <DialogDescription>
                                        Undangan berlaku 7 hari. Penerima
                                        menyiapkan kata sandinya sendiri.
                                    </DialogDescription>
                                </DialogHeader>

                                <form
                                    className="space-y-4"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        inviteForm.post(storeInvitation().url, {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                inviteForm.reset();
                                                setInviteOpen(false);
                                            },
                                        });
                                    }}
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="invite-email">
                                            Alamat email
                                        </Label>
                                        <Input
                                            id="invite-email"
                                            type="email"
                                            required
                                            autoFocus
                                            value={inviteForm.data.email}
                                            onChange={(event) =>
                                                inviteForm.setData(
                                                    'email',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="nama@perusahaan.com"
                                        />
                                        <InputError
                                            message={inviteForm.errors.email}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="invite-role">
                                            Role
                                        </Label>
                                        <Select
                                            value={inviteForm.data.role}
                                            onValueChange={(value) =>
                                                inviteForm.setData(
                                                    'role',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger id="invite-role">
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
                                        <InputError
                                            message={inviteForm.errors.role}
                                        />
                                    </div>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setInviteOpen(false)}
                                        >
                                            Batal
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={inviteForm.processing}
                                        >
                                            {inviteForm.processing
                                                ? 'Mengirim…'
                                                : 'Kirim undangan'}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    )}
                </div>

                {can.manage && invitations.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="font-medium">Undangan tertunda</h2>

                        <ul className="divide-y rounded-lg border">
                            {invitations.map((invitation) => (
                                <li
                                    key={invitation.id}
                                    className="flex flex-wrap items-center gap-3 p-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-medium">
                                            {invitation.email}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {invitation.role_label} ·{' '}
                                            {invitation.is_expired
                                                ? 'Kedaluwarsa'
                                                : `Berlaku sampai ${invitation.expires_at}`}
                                        </p>
                                    </div>

                                    {invitation.is_expired && (
                                        <Badge variant="destructive">
                                            Kedaluwarsa
                                        </Badge>
                                    )}

                                    <div className="flex gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                copy(invitation.accept_url)
                                            }
                                        >
                                            {copied ===
                                            invitation.accept_url ? (
                                                <Check
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            ) : (
                                                <Copy
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            Salin tautan
                                        </Button>

                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    resendInvitation(
                                                        invitation.id,
                                                    ).url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Kirim ulang
                                        </Button>

                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive hover:text-destructive"
                                            onClick={() =>
                                                router.delete(
                                                    destroyInvitation(
                                                        invitation.id,
                                                    ).url,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Batalkan
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Unit</TableHead>
                                {can.manage && (
                                    <TableHead className="text-right">
                                        Aksi
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {members.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={can.manage ? 4 : 3}
                                        className="py-12 text-center"
                                    >
                                        <Users
                                            className="mx-auto mb-3 size-8 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <p className="font-medium">
                                            Belum ada anggota
                                        </p>
                                    </TableCell>
                                </TableRow>
                            )}

                            {members.map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <UserInfo
                                                user={
                                                    member.user as unknown as User
                                                }
                                                showEmail
                                            />
                                        </div>
                                    </TableCell>

                                    <TableCell>
                                        <div className="flex items-center gap-1.5">
                                            <Badge
                                                variant="outline"
                                                className="font-mono text-[10px] tabular-nums"
                                            >
                                                {member.role_code}
                                            </Badge>
                                            <span className="text-sm">
                                                {member.role_label}
                                            </span>
                                        </div>
                                    </TableCell>

                                    <TableCell className="text-sm">
                                        {member.org_unit?.name ?? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TableCell>

                                    {can.manage && (
                                        <TableCell>
                                            <div className="flex justify-end">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                            aria-label={`Aksi untuk ${member.user.name}`}
                                                        >
                                                            <MoreHorizontal className="size-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>

                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem
                                                            disabled={
                                                                !member.can_edit
                                                            }
                                                            onSelect={() =>
                                                                setEditing(
                                                                    member,
                                                                )
                                                            }
                                                        >
                                                            Ubah role & unit
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            disabled={
                                                                !member.can_remove ||
                                                                member.is_self
                                                            }
                                                            onSelect={() => {
                                                                if (
                                                                    confirm(
                                                                        `Keluarkan ${member.user.name} dari workspace?`,
                                                                    )
                                                                ) {
                                                                    router.delete(
                                                                        destroyMember(
                                                                            member.id,
                                                                        ).url,
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            Keluarkan
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <MemberEditDialog
                member={editing}
                unitPicker={unitPicker}
                roles={roles}
                onClose={() => setEditing(null)}
            />
        </>
    );
}

Members.layout = {
    breadcrumbs: [{ title: 'Anggota', href: membersIndex() }],
};
