import { Form, Head, router } from '@inertiajs/react';
import { Building2, MailCheck, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import WorkspaceController from '@/actions/App/Http/Controllers/Admin/WorkspaceController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type WorkspaceRow = {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    members_count: number;
    created_at: string;
    pending_owner_invite: { email: string; expires_at: string } | null;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

export default function AdminWorkspaces({
    workspaces,
    filters,
}: {
    workspaces: Paginated<WorkspaceRow>;
    filters: { search: string };
}) {
    const [search, setSearch] = useState(filters.search);
    const [createOpen, setCreateOpen] = useState(false);

    return (
        <>
            <Head title="Workspace" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Workspace</h1>
                        <p className="text-sm text-muted-foreground">
                            {workspaces.total} perusahaan terdaftar
                        </p>
                    </div>

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
                                    isi. Owner menyiapkan struktur divisi
                                    setelah menerima undangan.
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
                                                placeholder="PT Contoh Sejahtera"
                                            />
                                            <InputError
                                                message={errors.name}
                                            />
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
                                                placeholder="owner@perusahaan.com"
                                            />
                                            <InputError
                                                message={errors.owner_email}
                                            />
                                        </div>

                                        <DialogFooter>
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                >
                                                    Batal
                                                </Button>
                                            </DialogClose>
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

                <form
                    className="flex max-w-sm gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            WorkspaceController.index.url(),
                            { search },
                            { preserveState: true, replace: true },
                        );
                    }}
                >
                    <div className="relative flex-1">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Cari workspace"
                            aria-label="Cari workspace"
                            className="pl-9"
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        Cari
                    </Button>
                </form>

                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Anggota
                                </TableHead>
                                <TableHead>Owner</TableHead>
                                <TableHead>Dibuat</TableHead>
                                <TableHead className="text-right">
                                    Aksi
                                </TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {workspaces.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-12 text-center"
                                    >
                                        <Building2
                                            className="mx-auto mb-3 size-8 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <p className="font-medium">
                                            Belum ada workspace
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Buat workspace pertama dan undang
                                            Owner-nya.
                                        </p>
                                    </TableCell>
                                </TableRow>
                            )}

                            {workspaces.data.map((workspace) => (
                                <TableRow key={workspace.id}>
                                    <TableCell>
                                        <span className="font-medium">
                                            {workspace.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            /{workspace.slug}
                                        </span>
                                    </TableCell>

                                    <TableCell>
                                        <Badge
                                            variant={
                                                workspace.is_active
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {workspace.is_active
                                                ? 'Aktif'
                                                : 'Nonaktif'}
                                        </Badge>
                                    </TableCell>

                                    <TableCell className="text-right tabular-nums">
                                        {workspace.members_count}
                                    </TableCell>

                                    <TableCell className="text-sm">
                                        {workspace.pending_owner_invite ? (
                                            <span className="flex items-center gap-1.5 text-muted-foreground">
                                                <MailCheck
                                                    className="size-3.5"
                                                    aria-hidden="true"
                                                />
                                                Undangan terkirim
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Sudah bergabung
                                            </span>
                                        )}
                                    </TableCell>

                                    <TableCell className="text-sm text-muted-foreground tabular-nums">
                                        {workspace.created_at}
                                    </TableCell>

                                    <TableCell>
                                        <div className="flex justify-end gap-2">
                                            {workspace.pending_owner_invite && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            WorkspaceController.resendOwnerInvite.url(
                                                                workspace.slug,
                                                            ),
                                                            {},
                                                            {
                                                                preserveScroll:
                                                                    true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Kirim ulang
                                                </Button>
                                            )}

                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.patch(
                                                        WorkspaceController.update.url(
                                                            workspace.slug,
                                                        ),
                                                        {
                                                            is_active:
                                                                !workspace.is_active,
                                                        },
                                                        {
                                                            preserveScroll:
                                                                true,
                                                        },
                                                    )
                                                }
                                            >
                                                {workspace.is_active
                                                    ? 'Nonaktifkan'
                                                    : 'Aktifkan'}
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}
