import { Form, Head, Link, router } from '@inertiajs/react';
import { CircleSlash, Plus, Search, ShieldCheck, Users, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import InputError from '@/components/input-error';
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
import { index as usersIndex, show as userShow } from '@/routes/users';

type UserRow = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    is_super_admin: boolean;
    is_active: boolean;
    /** The operator looking at the roster, so their own row can say so. */
    is_self: boolean;
    workspaces_count: number;
    created_at: string | null;
};

type Filters = { search: string; status: string };

type Stats = {
    total: number;
    active: number;
    inactive: number;
    unassigned: number;
};

/** Select and the filter group need a value; "no filter" carries a sentinel. */
const ALL = 'all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Semua' },
    { value: 'active', label: 'Aktif' },
    { value: 'inactive', label: 'Nonaktif' },
    { value: 'unassigned', label: 'Tanpa workspace' },
];

export default function UsersIndex({
    users,
    filters,
    stats,
    passwordRules,
}: {
    users: Paginated<UserRow>;
    filters: Filters;
    stats: Stats;
    passwordRules: string;
}) {
    const [search, setSearch] = useState(filters.search);
    const [createOpen, setCreateOpen] = useState(false);

    const applyFilters = (patch: Partial<Filters>) =>
        router.get(
            UserController.index.url(),
            {
                search: (patch.search ?? filters.search) || undefined,
                status: (patch.status ?? filters.status) || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // Debounce typing so the roster does not reload on every keystroke.
    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = setTimeout(() => applyFilters({ search }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const isFiltered = filters.search !== '' || filters.status !== '';

    return (
        <>
            <Head title="Kelola user" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <PageHeader
                        title="Kelola user"
                        description="Semua akun di platform, beserta workspace tempat mereka bekerja."
                    />

                    <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                        <DialogTrigger asChild>
                            <Button>
                                <Plus className="size-4" aria-hidden="true" />
                                Akun baru
                            </Button>
                        </DialogTrigger>

                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Buat akun</DialogTitle>
                                <DialogDescription>
                                    Akun langsung aktif dan terverifikasi, tapi
                                    belum menjadi anggota workspace mana pun.
                                    Penempatannya diatur di halaman akun.
                                </DialogDescription>
                            </DialogHeader>

                            <Form
                                {...UserController.store.form()}
                                onSuccess={() => setCreateOpen(false)}
                                resetOnSuccess
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">Nama</Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                required
                                                autoFocus
                                                autoComplete="off"
                                                placeholder="Nama lengkap"
                                            />
                                            <InputError message={errors.name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="email">Email</Label>
                                            <Input
                                                id="email"
                                                name="email"
                                                type="email"
                                                required
                                                autoComplete="off"
                                                placeholder="nama@perusahaan.test"
                                            />
                                            <InputError
                                                message={errors.email}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="password">
                                                Kata sandi awal
                                            </Label>
                                            <Input
                                                id="password"
                                                name="password"
                                                type="password"
                                                required
                                                autoComplete="new-password"
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {passwordRules}
                                            </p>
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="password_confirmation">
                                                Ulangi kata sandi
                                            </Label>
                                            <Input
                                                id="password_confirmation"
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
                                                onClick={() =>
                                                    setCreateOpen(false)
                                                }
                                            >
                                                Batal
                                            </Button>
                                            <Button disabled={processing}>
                                                {processing
                                                    ? 'Membuat…'
                                                    : 'Buat akun'}
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile label="Total akun" value={stats.total} />
                    <StatTile label="Aktif" value={stats.active} />
                    <StatTile
                        label="Nonaktif"
                        value={stats.inactive}
                        muted={stats.inactive === 0}
                    />
                    <StatTile
                        label="Tanpa workspace"
                        value={stats.unassigned}
                        muted={stats.unassigned === 0}
                        hint={
                            stats.unassigned > 0
                                ? 'Belum ditempatkan di mana pun'
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
                            placeholder="Cari nama atau email"
                            aria-label="Cari nama atau email"
                            className="pl-9"
                        />
                        {search !== '' && (
                            <button
                                type="button"
                                onClick={() => setSearch('')}
                                aria-label="Bersihkan pencarian"
                                className="absolute top-1/2 right-2 flex size-6 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:bg-accent"
                            >
                                <X className="size-3.5" />
                            </button>
                        )}
                    </div>

                    <div
                        className="flex flex-wrap rounded-md border p-0.5"
                        role="group"
                        aria-label="Saring status akun"
                    >
                        {STATUS_OPTIONS.map((option) => {
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
                                    onClick={() =>
                                        applyFilters({
                                            status:
                                                option.value === ALL
                                                    ? ''
                                                    : option.value,
                                        })
                                    }
                                >
                                    {option.label}
                                </Button>
                            );
                        })}
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border bg-background">
                    <Table className="min-w-[48rem]">
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>Akun</TableHead>
                                <TableHead className="text-right">
                                    Workspace
                                </TableHead>
                                <TableHead>Dibuat</TableHead>
                                <TableHead className="w-28">
                                    <span className="sr-only">Aksi</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {users.data.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={4}
                                        className="py-16 text-center"
                                    >
                                        <Users
                                            className="mx-auto mb-3 size-8 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <p className="font-medium">
                                            {isFiltered
                                                ? 'Tidak ada akun yang cocok'
                                                : 'Belum ada akun'}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {isFiltered
                                                ? 'Ubah kata kunci atau saringan status.'
                                                : 'Buat akun pertama, lalu tempatkan di sebuah workspace.'}
                                        </p>
                                    </TableCell>
                                </TableRow>
                            )}

                            {users.data.map((user) => (
                                <TableRow
                                    key={user.id}
                                    className={cn(
                                        !user.is_active && 'opacity-60',
                                    )}
                                >
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link
                                                href={userShow(user.id)}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {user.name}
                                            </Link>

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

                                            {user.is_self && (
                                                <Badge
                                                    variant="outline"
                                                    className="font-normal"
                                                >
                                                    Anda
                                                </Badge>
                                            )}
                                        </div>
                                        <span className="text-xs text-muted-foreground">
                                            {user.email}
                                        </span>
                                    </TableCell>

                                    <TableCell className="text-right tabular-nums">
                                        {user.is_super_admin ? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        ) : (
                                            user.workspaces_count
                                        )}
                                    </TableCell>

                                    <TableCell className="text-sm text-muted-foreground tabular-nums">
                                        {user.created_at}
                                    </TableCell>

                                    <TableCell className="text-right">
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="sm"
                                        >
                                            <Link href={userShow(user.id)}>
                                                Kelola
                                            </Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination meta={users} label="Navigasi halaman akun" />
            </div>
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

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Kelola user', href: usersIndex() }],
};
