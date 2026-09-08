import { Form, Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    CircleSlash,
    KeyRound,
    Plus,
    ShieldCheck,
    ShieldOff,
} from 'lucide-react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import UserMembershipController from '@/actions/App/Http/Controllers/UserMembershipController';
import InputError from '@/components/input-error';
import { OrgUnitSearch } from '@/components/org-unit-search';
import { PageHeader, SectionHeader } from '@/components/page-header';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { masterSearch } from '@/routes/org-units';
import { index as usersIndex } from '@/routes/users';

type NamedRef = { id: number; name: string };

type AccountUser = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    is_super_admin: boolean;
    is_active: boolean;
    is_self: boolean;
    has_two_factor: boolean;
    created_at: string | null;
};

type MembershipRow = {
    id: number;
    workspace: { id: number; name: string; is_active: boolean };
    role: string;
    role_label: string;
    role_code: string;
    title: string;
    org_unit: NamedRef | null;
    joined_at: string | null;
    /** The workspace's only remaining Owner: locked until a successor exists. */
    is_last_top_role: boolean;
};

type RoleOption = {
    value: string;
    label: string;
    code: string;
    description: string;
};

type WorkspaceOption = { id: number; name: string; is_active: boolean };

export default function UserShow({
    user,
    memberships,
    workspaceOptions,
    roles,
    passwordRules,
}: {
    user: AccountUser;
    memberships: MembershipRow[];
    workspaceOptions: WorkspaceOption[];
    roles: RoleOption[];
    passwordRules: string;
}) {
    const [editing, setEditing] = useState<MembershipRow | null>(null);
    const [adding, setAdding] = useState(false);
    const [resettingPassword, setResettingPassword] = useState(false);

    const setActive = (isActive: boolean) => {
        if (
            !isActive &&
            !confirm(
                `Nonaktifkan akun ${user.name}? Akun langsung keluar dari sesinya dan tidak bisa masuk lagi.`,
            )
        ) {
            return;
        }

        router.patch(
            UserController.update.url(user.id),
            { is_active: isActive },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={user.name} />

            <div className="space-y-6">
                <div className="space-y-3">
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="-ml-2 text-muted-foreground"
                    >
                        <Link href={usersIndex()}>
                            <ArrowLeft className="size-4" aria-hidden="true" />
                            Semua akun
                        </Link>
                    </Button>

                    <PageHeader
                        title={user.name}
                        description={user.email}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                {!user.is_active && (
                                    <Badge
                                        variant="secondary"
                                        className="gap-1 font-normal"
                                    >
                                        <CircleSlash
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                        Nonaktif
                                    </Badge>
                                )}

                                {user.is_super_admin && (
                                    <Badge
                                        variant="outline"
                                        className="gap-1 font-normal"
                                    >
                                        <ShieldCheck
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                        Super admin
                                    </Badge>
                                )}
                            </div>
                        }
                    />
                </div>

                <section className="space-y-4 rounded-lg border bg-background p-5">
                    <SectionHeader
                        title="Profil"
                        description="Nama dan email yang dipakai akun ini untuk masuk."
                    />

                    <Form
                        {...UserController.update.form(user.id)}
                        options={{ preserveScroll: true }}
                        className="grid gap-4 sm:max-w-lg"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Nama</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={user.name}
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue={user.email}
                                        required
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div>
                                    <Button disabled={processing}>
                                        {processing
                                            ? 'Menyimpan…'
                                            : 'Simpan profil'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                <section className="space-y-4 rounded-lg border bg-background p-5">
                    <SectionHeader
                        title="Akses"
                        description="Untuk memulihkan akun yang terkunci, dan untuk mencabut akses tanpa menghapus jejaknya di task."
                    />

                    <div className="grid gap-3 sm:grid-cols-2">
                        <ActionTile
                            title="Setel ulang kata sandi"
                            description="Sesi yang diingat lewat “ingat saya” ikut dicabut."
                            action={
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setResettingPassword(true)}
                                >
                                    <KeyRound
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Setel ulang
                                </Button>
                            }
                        />

                        <ActionTile
                            title="Autentikasi dua faktor"
                            description={
                                user.has_two_factor
                                    ? 'Aktif. Lepas jika perangkat pemiliknya hilang.'
                                    : 'Tidak aktif pada akun ini.'
                            }
                            action={
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!user.has_two_factor}
                                    onClick={() => {
                                        if (
                                            !confirm(
                                                `Lepas 2FA dari akun ${user.name}?`,
                                            )
                                        ) {
                                            return;
                                        }

                                        router.delete(
                                            UserController.destroyTwoFactor.url(
                                                user.id,
                                            ),
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    <ShieldOff
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Lepas 2FA
                                </Button>
                            }
                        />

                        <ActionTile
                            className="sm:col-span-2"
                            title={
                                user.is_active
                                    ? 'Nonaktifkan akun'
                                    : 'Aktifkan kembali akun'
                            }
                            description={
                                user.is_self
                                    ? 'Anda tidak bisa menonaktifkan akun Anda sendiri — tidak ada jalan kembali untuk membuka kuncinya.'
                                    : user.is_active
                                      ? 'Akun langsung keluar dari sesinya dan tidak bisa masuk lagi. Semua task, komentar dan riwayatnya tetap utuh.'
                                      : 'Akun bisa masuk lagi dengan kata sandi yang sama.'
                            }
                            action={
                                <Button
                                    variant={
                                        user.is_active
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    size="sm"
                                    disabled={user.is_self}
                                    onClick={() => setActive(!user.is_active)}
                                >
                                    {user.is_active
                                        ? 'Nonaktifkan'
                                        : 'Aktifkan'}
                                </Button>
                            }
                        />
                    </div>
                </section>

                <section className="space-y-4 rounded-lg border bg-background p-5">
                    <SectionHeader
                        title="Keanggotaan workspace"
                        description={
                            user.is_super_admin
                                ? 'Super admin mengoperasikan platform dan tidak menjadi anggota workspace mana pun.'
                                : 'Role menentukan hak akses; cakupannya mengikuti unit penempatan.'
                        }
                        actions={
                            !user.is_super_admin &&
                            workspaceOptions.length > 0 && (
                                <Button
                                    size="sm"
                                    onClick={() => setAdding(true)}
                                >
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Tambah ke workspace
                                </Button>
                            )
                        }
                    />

                    {memberships.length === 0 ? (
                        <div className="rounded-md border border-dashed py-12 text-center">
                            <Building2
                                className="mx-auto mb-3 size-8 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p className="font-medium">
                                {user.is_super_admin
                                    ? 'Tidak berlaku untuk super admin'
                                    : 'Belum tergabung di workspace mana pun'}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {user.is_super_admin
                                    ? 'Hak operator platform berlaku di luar workspace.'
                                    : 'Akun ini belum bisa melihat project atau task apa pun.'}
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-hidden rounded-md border">
                            <Table className="min-w-[44rem]">
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>Workspace</TableHead>
                                        <TableHead>Role</TableHead>
                                        <TableHead>Unit penempatan</TableHead>
                                        <TableHead className="w-32">
                                            <span className="sr-only">
                                                Aksi
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>

                                <TableBody>
                                    {memberships.map((membership) => (
                                        <TableRow
                                            key={membership.id}
                                            className={cn(
                                                !membership.workspace
                                                    .is_active && 'opacity-60',
                                            )}
                                        >
                                            <TableCell>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">
                                                        {
                                                            membership.workspace
                                                                .name
                                                        }
                                                    </span>
                                                    {!membership.workspace
                                                        .is_active && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="font-normal"
                                                        >
                                                            Workspace nonaktif
                                                        </Badge>
                                                    )}
                                                </div>
                                                <span className="text-xs text-muted-foreground">
                                                    {membership.title}
                                                    {membership.joined_at &&
                                                        ` · sejak ${membership.joined_at}`}
                                                </span>
                                            </TableCell>

                                            <TableCell>
                                                <span className="flex items-center gap-2 text-sm">
                                                    <span className="font-mono text-[10px] text-muted-foreground tabular-nums">
                                                        {membership.role_code}
                                                    </span>
                                                    {membership.role_label}
                                                </span>
                                                {membership.is_last_top_role && (
                                                    <span className="text-xs text-muted-foreground">
                                                        Pemilik terakhir
                                                    </span>
                                                )}
                                            </TableCell>

                                            <TableCell className="text-sm">
                                                {membership.org_unit?.name ?? (
                                                    <span className="text-muted-foreground">
                                                        Belum ditempatkan
                                                    </span>
                                                )}
                                            </TableCell>

                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setEditing(membership)
                                                    }
                                                >
                                                    Ubah
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </section>
            </div>

            <ResetPasswordDialog
                open={resettingPassword}
                user={user}
                passwordRules={passwordRules}
                onClose={() => setResettingPassword(false)}
            />

            <AddMembershipDialog
                open={adding}
                user={user}
                workspaceOptions={workspaceOptions}
                roles={roles}
                onClose={() => setAdding(false)}
            />

            <EditMembershipDialog
                key={editing?.id}
                membership={editing}
                user={user}
                roles={roles}
                onClose={() => setEditing(null)}
            />
        </>
    );
}

function ActionTile({
    title,
    description,
    action,
    className,
}: {
    title: string;
    description: string;
    action: React.ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-3 rounded-md border p-4',
                className,
            )}
        >
            <div className="min-w-0 space-y-1">
                <p className="text-sm font-medium">{title}</p>
                <p className="max-w-prose text-xs text-muted-foreground">
                    {description}
                </p>
            </div>
            <div className="shrink-0">{action}</div>
        </div>
    );
}

function ResetPasswordDialog({
    open,
    user,
    passwordRules,
    onClose,
}: {
    open: boolean;
    user: AccountUser;
    passwordRules: string;
    onClose: () => void;
}) {
    if (!open) {
        return null;
    }

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Setel ulang kata sandi</DialogTitle>
                    <DialogDescription>
                        Kata sandi baru untuk {user.email}. Sampaikan lewat
                        jalur yang aman, dan minta pemiliknya menggantinya.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...UserController.password.form(user.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="new-password">
                                    Kata sandi baru
                                </Label>
                                <Input
                                    id="new-password"
                                    name="password"
                                    type="password"
                                    required
                                    autoFocus
                                    autoComplete="new-password"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {passwordRules}
                                </p>
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="new-password-confirmation">
                                    Ulangi kata sandi
                                </Label>
                                <Input
                                    id="new-password-confirmation"
                                    name="password_confirmation"
                                    type="password"
                                    required
                                    autoComplete="new-password"
                                />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                >
                                    Batal
                                </Button>
                                <Button disabled={processing}>
                                    {processing ? 'Menyimpan…' : 'Setel ulang'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Places the account in a workspace it is not in yet.
 *
 * The unit picker is narrowed to the chosen workspace's own branch, so the
 * operator is never offered a unit the form would then refuse.
 */
function AddMembershipDialog({
    open,
    user,
    workspaceOptions,
    roles,
    onClose,
}: {
    open: boolean;
    user: AccountUser;
    workspaceOptions: WorkspaceOption[];
    roles: RoleOption[];
    onClose: () => void;
}) {
    const form = useForm<{
        workspace_id: string;
        role: string;
        title: string;
        org_unit_id: number | null;
    }>({
        workspace_id: '',
        role: 'member',
        title: '',
        org_unit_id: null,
    });

    const [unit, setUnit] = useState<NamedRef | null>(null);

    if (!open) {
        return null;
    }

    const workspaceChosen = form.data.workspace_id !== '';

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Tambah ke workspace</DialogTitle>
                    <DialogDescription>
                        {user.name} langsung menjadi anggota, tanpa undangan.
                    </DialogDescription>
                </DialogHeader>

                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(UserMembershipController.store.url(user.id), {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="membership-workspace">Workspace</Label>
                        <Select
                            value={form.data.workspace_id}
                            onValueChange={(value) => {
                                form.setData('workspace_id', value);
                                // The old unit belongs to the old workspace's
                                // tree, so it cannot survive the switch.
                                setUnit(null);
                                form.setData('org_unit_id', null);
                            }}
                        >
                            <SelectTrigger id="membership-workspace">
                                <SelectValue placeholder="Pilih workspace" />
                            </SelectTrigger>
                            <SelectContent>
                                {workspaceOptions.map((workspace) => (
                                    <SelectItem
                                        key={workspace.id}
                                        value={String(workspace.id)}
                                    >
                                        {workspace.name}
                                        {!workspace.is_active && ' · nonaktif'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.workspace_id} />
                    </div>

                    <RoleField
                        id="membership-role"
                        roles={roles}
                        value={form.data.role}
                        onChange={(value) => form.setData('role', value)}
                        error={form.errors.role}
                    />

                    <div className="grid gap-2">
                        <Label htmlFor="membership-title">Jabatan</Label>
                        <Input
                            id="membership-title"
                            value={form.data.title}
                            maxLength={100}
                            placeholder="Kepala Divisi, Team Lead, Staf…"
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                        />
                        <InputError message={form.errors.title} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Unit penempatan</Label>
                        {workspaceChosen ? (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {unit
                                        ? unit.name
                                        : 'Belum dipilih. Tanpa unit, seorang pemimpin tidak memimpin siapa pun.'}
                                </p>
                                <OrgUnitSearch
                                    endpoint={masterSearch}
                                    query={{
                                        workspace: form.data.workspace_id,
                                    }}
                                    placeholder="Cari unit di workspace ini…"
                                    onSelect={(hit) => {
                                        setUnit({
                                            id: hit.id,
                                            name: hit.name,
                                        });
                                        form.setData('org_unit_id', hit.id);
                                    }}
                                />
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Pilih workspace dulu — unit yang tersedia
                                mengikuti struktur yang dijalankannya.
                            </p>
                        )}
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
                        <Button
                            type="submit"
                            disabled={form.processing || !workspaceChosen}
                        >
                            {form.processing ? 'Menambahkan…' : 'Tambahkan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Role, position and placement of one existing membership, plus the way out of
 * a workspace.
 *
 * Seeded from `membership` on mount only, so the call site keys this on the
 * membership id — opening a second row remounts it rather than leaving the
 * previous workspace's role in the fields.
 */
function EditMembershipDialog({
    membership,
    user,
    roles,
    onClose,
}: {
    membership: MembershipRow | null;
    user: AccountUser;
    roles: RoleOption[];
    onClose: () => void;
}) {
    const form = useForm<{
        role: string;
        title: string;
        org_unit_id: number | null;
    }>({
        role: membership?.role ?? 'member',
        title: membership?.title ?? '',
        org_unit_id: membership?.org_unit?.id ?? null,
    });

    const [unit, setUnit] = useState<NamedRef | null>(
        membership?.org_unit ?? null,
    );

    if (!membership) {
        return null;
    }

    const locked = membership.is_last_top_role;

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{membership.workspace.name}</DialogTitle>
                    <DialogDescription>
                        Keanggotaan {user.name} di workspace ini.
                    </DialogDescription>
                </DialogHeader>

                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(
                            UserMembershipController.update.url({
                                user: user.id,
                                member: membership.id,
                            }),
                            { preserveScroll: true, onSuccess: onClose },
                        );
                    }}
                >
                    <RoleField
                        id="edit-membership-role"
                        roles={roles}
                        value={form.data.role}
                        onChange={(value) => form.setData('role', value)}
                        error={form.errors.role}
                        hint={
                            locked
                                ? 'Pemilik terakhir di workspace ini. Angkat pemilik lain lebih dulu sebelum menurunkannya.'
                                : undefined
                        }
                    />

                    <div className="grid gap-2">
                        <Label htmlFor="edit-membership-title">Jabatan</Label>
                        <Input
                            id="edit-membership-title"
                            value={form.data.title}
                            maxLength={100}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                        />
                        <InputError message={form.errors.title} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Unit penempatan</Label>
                        <p className="text-sm text-muted-foreground">
                            {unit ? unit.name : 'Belum ditempatkan'}
                        </p>
                        <OrgUnitSearch
                            endpoint={masterSearch}
                            query={{ workspace: membership.workspace.id }}
                            placeholder="Cari unit di workspace ini…"
                            onSelect={(hit) => {
                                setUnit({ id: hit.id, name: hit.name });
                                form.setData('org_unit_id', hit.id);
                            }}
                        />
                        <InputError message={form.errors.org_unit_id} />
                    </div>

                    <DialogFooter className="sm:justify-between">
                        <Button
                            type="button"
                            variant="ghost"
                            className="text-destructive hover:text-destructive"
                            disabled={locked}
                            onClick={() => {
                                if (
                                    !confirm(
                                        `Keluarkan ${user.name} dari ${membership.workspace.name}?`,
                                    )
                                ) {
                                    return;
                                }

                                router.delete(
                                    UserMembershipController.destroy.url({
                                        user: user.id,
                                        member: membership.id,
                                    }),
                                    {
                                        preserveScroll: true,
                                        onSuccess: onClose,
                                    },
                                );
                            }}
                        >
                            Keluarkan dari workspace
                        </Button>

                        <div className="flex items-center gap-2">
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
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RoleField({
    id,
    roles,
    value,
    onChange,
    error,
    hint,
}: {
    id: string;
    roles: RoleOption[];
    value: string;
    onChange: (value: string) => void;
    error?: string;
    hint?: string;
}) {
    const description = roles.find((role) => role.value === value)?.description;

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Role</Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger id={id}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {roles.map((role) => (
                        <SelectItem key={role.value} value={role.value}>
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
                {hint ?? description}
            </p>
            <InputError message={error} />
        </div>
    );
}

UserShow.layout = {
    breadcrumbs: [{ title: 'Kelola user', href: usersIndex() }],
};
