import { Form, Head, router } from '@inertiajs/react';
import {
    Building2,
    CircleSlash,
    LogIn,
    MailWarning,
    MoreHorizontal,
    Plus,
    Search,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import WorkspaceController from '@/actions/App/Http/Controllers/WorkspaceController';
import InputError from '@/components/input-error';
import { OrgUnitSearch } from '@/components/org-unit-search';
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
import { Pagination } from '@/components/ui/pagination';
import type { Paginated } from '@/components/ui/pagination';
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
import { change as changeWorkspace } from '@/routes/workspace';
import { index as workspacesIndex } from '@/routes/workspaces';

type WorkspaceRow = {
    id: number;
    name: string;
    slug: string;
    /** Node of the platform org tree this workspace runs. */
    root_org_unit: { id: number; name: string } | null;
    is_active: boolean;
    members_count: number;
    created_at: string;
    owner: { name: string; email: string } | null;
    pending_owner_invite: { email: string; expires_at: string } | null;
};

type Stats = {
    total: number;
    active: number;
    inactive: number;
    pending_owner: number;
};

type Filters = { search: string; status: string };

const ALL = 'all';

export default function Workspaces({
    workspaces,
    filters,
    stats,
}: {
    workspaces: Paginated<WorkspaceRow>;
    filters: Filters;
    stats: Stats;
}) {
    const [search, setSearch] = useState(filters.search);
    const [createOpen, setCreateOpen] = useState(false);
    const [renaming, setRenaming] = useState<WorkspaceRow | null>(null);

    const applyFilters = (patch: Partial<Filters>) =>
        router.get(
            WorkspaceController.index.url(),
            {
                search: (patch.search ?? filters.search) || undefined,
                status: (patch.status ?? filters.status) || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // Debounce typing so the list does not reload on every keystroke.
    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = setTimeout(() => applyFilters({ search }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const setStatus = (value: string) =>
        applyFilters({ status: value === ALL ? '' : value });

    return (
        <>
            <Head title="Workspace" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <PageHeader
                        title="Workspace"
                        description="Setiap workspace adalah satu perusahaan yang datanya terpisah penuh dari yang lain."
                    />

                    <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                        <DialogTrigger asChild>
                            <Button>
                                <Plus className="size-4" aria-hidden="true" />
                                Workspace baru
                            </Button>
                        </DialogTrigger>

                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Buat workspace</DialogTitle>
                                <DialogDescription>
                                    Undangan Owner dikirim ke email yang Anda
                                    isi. Owner menyiapkan struktur divisi dan
                                    mengundang timnya sendiri.
                                </DialogDescription>
                            </DialogHeader>

                            <Form
                                {...WorkspaceController.store.form()}
                                onSuccess={() => setCreateOpen(false)}
                                resetOnSuccess
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">
                                                Nama perusahaan
                                            </Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                required
                                                autoFocus
                                                placeholder="Perkebunan Nusantara"
                                            />
                                            <InputError message={errors.name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="owner_email">
                                                Email Owner
                                            </Label>
                                            <Input
                                                id="owner_email"
                                                name="owner_email"
                                                type="email"
                                                required
                                                placeholder="owner@perusahaan.test"
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Undangan berlaku 7 hari dan bisa
                                                dikirim ulang.
                                            </p>
                                            <InputError
                                                message={errors.owner_email}
                                            />
                                        </div>

                                        <DialogFooter>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    setCreateOpen(false)
                                                }
                                            >
                                                Batal
                                            </Button>
                                            <Button disabled={processing}>
                                                {processing
                                                    ? 'Membuat…'
                                                    : 'Buat & undang Owner'}
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile label="Total workspace" value={stats.total} />
                    <StatTile label="Aktif" value={stats.active} />
                    <StatTile
                        label="Nonaktif"
                        value={stats.inactive}
                        muted={stats.inactive === 0}
                    />
                    <StatTile
                        label="Menunggu Owner"
                        value={stats.pending_owner}
                        hint={
                            stats.pending_owner > 0
                                ? 'Undangan belum diterima'
                                : undefined
                        }
                    />
                </dl>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-56 flex-1 sm:max-w-sm">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Cari nama perusahaan"
                            aria-label="Cari nama perusahaan"
                            className="pl-9"
                        />
                        {search !== '' && (
                            <button
                                type="button"
                                onClick={() => setSearch('')}
                                aria-label="Bersihkan pencarian"
                                className="absolute top-1/2 right-2 flex size-6 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:bg-muted"
                            >
                                <X className="size-3.5" />
                            </button>
                        )}
                    </div>

                    <div
                        className="flex rounded-md border p-0.5"
                        role="group"
                        aria-label="Saring status"
                    >
                        {[
                            { value: ALL, label: 'Semua' },
                            { value: 'active', label: 'Aktif' },
                            { value: 'inactive', label: 'Nonaktif' },
                        ].map((option) => {
                            const current = filters.status || ALL;

                            return (
                                <Button
                                    key={option.value}
                                    size="sm"
                                    variant={
                                        current === option.value
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    aria-pressed={current === option.value}
                                    onClick={() => setStatus(option.value)}
                                >
                                    {option.label}
                                </Button>
                            );
                        })}
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border bg-background">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>Perusahaan</TableHead>
                                <TableHead>Owner</TableHead>
                                <TableHead className="text-right">
                                    Anggota
                                </TableHead>
                                <TableHead>Dibuat</TableHead>
                                <TableHead className="w-12">
                                    <span className="sr-only">Aksi</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {workspaces.data.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={5}
                                        className="py-16 text-center"
                                    >
                                        <Building2
                                            className="mx-auto mb-3 size-8 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <p className="font-medium">
                                            {filters.search || filters.status
                                                ? 'Tidak ada workspace yang cocok'
                                                : 'Belum ada workspace'}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {filters.search || filters.status
                                                ? 'Ubah kata kunci atau saringan status.'
                                                : 'Buat workspace pertama dan undang Owner-nya.'}
                                        </p>
                                    </TableCell>
                                </TableRow>
                            )}

                            {workspaces.data.map((workspace) => (
                                <TableRow
                                    key={workspace.id}
                                    className={cn(
                                        !workspace.is_active && 'opacity-60',
                                    )}
                                >
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">
                                                {workspace.name}
                                            </span>
                                            {!workspace.is_active && (
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
                                        </div>
                                        <span className="text-xs text-muted-foreground">
                                            /{workspace.slug}
                                        </span>
                                    </TableCell>

                                    <TableCell className="text-sm">
                                        {workspace.owner ? (
                                            <>
                                                <span className="block">
                                                    {workspace.owner.name}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {workspace.owner.email}
                                                </span>
                                            </>
                                        ) : workspace.pending_owner_invite ? (
                                            <>
                                                <span className="flex items-center gap-1.5 text-warning">
                                                    <MailWarning
                                                        className="size-3.5"
                                                        aria-hidden="true"
                                                    />
                                                    Menunggu penerimaan
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {
                                                        workspace
                                                            .pending_owner_invite
                                                            .email
                                                    }{' '}
                                                    · s/d{' '}
                                                    {
                                                        workspace
                                                            .pending_owner_invite
                                                            .expires_at
                                                    }
                                                </span>
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Belum ada Owner
                                            </span>
                                        )}
                                    </TableCell>

                                    <TableCell className="text-right tabular-nums">
                                        {workspace.members_count}
                                    </TableCell>

                                    <TableCell className="text-sm text-muted-foreground tabular-nums">
                                        {workspace.created_at}
                                    </TableCell>

                                    <TableCell>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    aria-label={`Aksi untuk ${workspace.name}`}
                                                >
                                                    <MoreHorizontal className="size-4" />
                                                </Button>
                                            </DropdownMenuTrigger>

                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    disabled={
                                                        !workspace.is_active
                                                    }
                                                    onSelect={() =>
                                                        router.post(
                                                            changeWorkspace(
                                                                workspace.slug,
                                                            ).url,
                                                        )
                                                    }
                                                >
                                                    <LogIn
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    Buka workspace
                                                </DropdownMenuItem>

                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setRenaming(workspace)
                                                    }
                                                >
                                                    Ubah nama
                                                </DropdownMenuItem>

                                                {workspace.pending_owner_invite && (
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            router.post(
                                                                WorkspaceController.resendOwnerInvite.url(
                                                                    workspace.slug,
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Kirim ulang undangan
                                                    </DropdownMenuItem>
                                                )}

                                                <DropdownMenuSeparator />

                                                <DropdownMenuItem
                                                    variant={
                                                        workspace.is_active
                                                            ? 'destructive'
                                                            : 'default'
                                                    }
                                                    onSelect={() => {
                                                        if (
                                                            workspace.is_active &&
                                                            !confirm(
                                                                `Nonaktifkan ${workspace.name}? Seluruh anggotanya langsung kehilangan akses sampai diaktifkan kembali.`,
                                                            )
                                                        ) {
                                                            return;
                                                        }

                                                        router.patch(
                                                            WorkspaceController.update.url(
                                                                workspace.slug,
                                                            ),
                                                            {
                                                                is_active:
                                                                    !workspace.is_active,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }}
                                                >
                                                    {workspace.is_active
                                                        ? 'Nonaktifkan'
                                                        : 'Aktifkan kembali'}
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination meta={workspaces} />
            </div>

            <RenameDialog
                key={renaming?.id ?? 'none'}
                workspace={renaming}
                onClose={() => setRenaming(null)}
            />
        </>
    );
}

function StatTile({
    label,
    value,
    hint,
    muted = false,
}: {
    label: string;
    value: number;
    hint?: string;
    muted?: boolean;
}) {
    return (
        <div className="rounded-lg border bg-background p-4">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    'mt-1 text-2xl font-semibold tabular-nums',
                    muted && 'text-muted-foreground',
                )}
            >
                {value}
            </dd>
            {hint && (
                <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}

function RenameDialog({
    workspace,
    onClose,
}: {
    workspace: WorkspaceRow | null;
    onClose: () => void;
}) {
    // Remounted per workspace via a key, so the initial value is always right.
    const [name, setName] = useState(workspace?.name ?? '');
    const [unit, setUnit] = useState(workspace?.root_org_unit ?? null);

    if (!workspace) {
        return null;
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Ubah workspace</DialogTitle>
                    <DialogDescription>
                        Alamat /{workspace.slug} tidak ikut berubah, jadi tautan
                        lama tetap berfungsi.
                    </DialogDescription>
                </DialogHeader>

                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.patch(
                            WorkspaceController.update.url(workspace.slug),
                            { name, root_org_unit_id: unit?.id ?? null },
                            { preserveScroll: true, onSuccess: onClose },
                        );
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="rename">Nama perusahaan</Label>
                        <Input
                            id="rename"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            required
                            autoFocus
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label>Unit organisasi</Label>
                        <p className="text-sm text-muted-foreground">
                            {unit
                                ? `Workspace ini menjalankan ${unit.name} beserta seluruh unit di bawahnya.`
                                : 'Belum dipilih. Tanpa unit, workspace tidak punya struktur untuk menempatkan anggota dan project.'}
                        </p>
                        <OrgUnitSearch
                            endpoint={masterSearch}
                            placeholder="Cari unit di struktur SAP…"
                            onSelect={(hit) =>
                                setUnit({ id: hit.id, name: hit.name })
                            }
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
                        <Button type="submit" disabled={name.trim() === ''}>
                            Simpan
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

Workspaces.layout = {
    breadcrumbs: [{ title: 'Kelola workspace', href: workspacesIndex() }],
};
