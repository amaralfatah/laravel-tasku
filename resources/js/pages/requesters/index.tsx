import { Head, router, useForm } from '@inertiajs/react';
import { ContactRound, MoreHorizontal, UserPlus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    destroy as destroyRequester,
    index as requestersIndex,
    store as storeRequester,
    update as updateRequester,
} from '@/routes/requesters';
import type { RequesterRow } from '@/types/requesters';

/**
 * The workspace's requester list (see App\Policies\RequesterPolicy).
 *
 * A leader keeps it; everyone else only picks from it on a task form. That
 * split is the whole design: a field people type into fills up with "Budi",
 * "budi" and "Pak Budi" within a month, and every report that groups by
 * requester is then wrong. Adding a name is deliberate, and it happens here.
 */
export default function Requesters({
    requesters,
}: {
    requesters: RequesterRow[];
}) {
    const [editing, setEditing] = useState<RequesterRow | null>(null);
    const [creating, setCreating] = useState(false);

    const activeCount = requesters.filter(
        (requester) => requester.is_active,
    ).length;

    return (
        <>
            <Head title="Pemohon" />

            <div className="space-y-6">
                <PageHeader
                    title="Pemohon"
                    description={`${activeCount} pemohon aktif. Daftar inilah yang muncul saat memilih pemohon di task.`}
                    actions={
                        <Button onClick={() => setCreating(true)}>
                            <UserPlus className="size-4" aria-hidden="true" />
                            Tambah pemohon
                        </Button>
                    }
                />

                <div className="rounded-lg border">
                    <Table className="min-w-[44rem]">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead>Asal</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead className="text-right">
                                    Task
                                </TableHead>
                                <TableHead className="text-right">
                                    Aksi
                                </TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {requesters.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="py-12 text-center"
                                    >
                                        <ContactRound
                                            className="mx-auto mb-3 size-8 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <p className="font-medium">
                                            Belum ada pemohon
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Tambahkan orang atau pihak yang
                                            meminta pekerjaan, lalu pilih
                                            namanya di form task.
                                        </p>
                                    </TableCell>
                                </TableRow>
                            )}

                            {requesters.map((requester) => (
                                <TableRow key={requester.id}>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <span
                                                className={
                                                    requester.is_active
                                                        ? 'font-medium'
                                                        : 'font-medium text-muted-foreground'
                                                }
                                            >
                                                {requester.name}
                                            </span>
                                            {!requester.is_active && (
                                                <Badge variant="outline">
                                                    Nonaktif
                                                </Badge>
                                            )}
                                        </div>
                                    </TableCell>

                                    <TableCell className="text-sm">
                                        {requester.organization ?? (
                                            <span className="text-muted-foreground">
                                                &mdash;
                                            </span>
                                        )}
                                    </TableCell>

                                    <TableCell className="text-sm">
                                        {requester.email ?? (
                                            <span className="text-muted-foreground">
                                                &mdash;
                                            </span>
                                        )}
                                    </TableCell>

                                    <TableCell className="text-right text-sm tabular-nums">
                                        {requester.tasks_count}
                                    </TableCell>

                                    <TableCell>
                                        <div className="flex justify-end">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        aria-label={`Aksi untuk ${requester.name}`}
                                                    >
                                                        <MoreHorizontal className="size-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>

                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            setEditing(
                                                                requester,
                                                            )
                                                        }
                                                    >
                                                        Ubah data
                                                    </DropdownMenuItem>

                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            router.patch(
                                                                updateRequester(
                                                                    requester.id,
                                                                ).url,
                                                                {
                                                                    is_active:
                                                                        !requester.is_active,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {requester.is_active
                                                            ? 'Nonaktifkan'
                                                            : 'Aktifkan kembali'}
                                                    </DropdownMenuItem>

                                                    <DropdownMenuSeparator />

                                                    {/* A requester already named
                                                        by a task is kept:
                                                        deleting them would
                                                        rewrite what those tasks
                                                        say about who asked. */}
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        disabled={
                                                            requester.tasks_count >
                                                            0
                                                        }
                                                        onSelect={() => {
                                                            if (
                                                                confirm(
                                                                    `Hapus ${requester.name} dari daftar pemohon?`,
                                                                )
                                                            ) {
                                                                router.delete(
                                                                    destroyRequester(
                                                                        requester.id,
                                                                    ).url,
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        {requester.tasks_count >
                                                        0
                                                            ? 'Dipakai di task'
                                                            : 'Hapus'}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <RequesterDialog
                key={editing?.id ?? (creating ? 'new' : 'none')}
                requester={editing}
                open={creating || editing !== null}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
            />
        </>
    );
}

/**
 * Adds a requester, or edits one. The same three fields either way, so one
 * dialog serves both rather than two that drift apart.
 */
function RequesterDialog({
    requester,
    open,
    onClose,
}: {
    requester: RequesterRow | null;
    open: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        name: requester?.name ?? '',
        organization: requester?.organization ?? '',
        email: requester?.email ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };

        if (requester) {
            form.patch(updateRequester(requester.id).url, options);

            return;
        }

        form.post(storeRequester().url, options);
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {requester ? 'Ubah pemohon' : 'Tambah pemohon'}
                    </DialogTitle>
                    <DialogDescription>
                        Satu orang cukup didaftarkan sekali. Nama yang sudah ada
                        akan ditolak, supaya laporan per pemohon tidak terpecah.
                    </DialogDescription>
                </DialogHeader>

                <form className="space-y-4" onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="requester-name">Nama</Label>
                        <Input
                            id="requester-name"
                            required
                            autoFocus
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            placeholder="Budi Santoso"
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="requester-organization">
                            Asal{' '}
                            <span className="text-muted-foreground">
                                (opsional)
                            </span>
                        </Label>
                        <Input
                            id="requester-organization"
                            value={form.data.organization}
                            onChange={(event) =>
                                form.setData('organization', event.target.value)
                            }
                            placeholder="Divisi Keuangan / PT XYZ"
                        />
                        <InputError message={form.errors.organization} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="requester-email">
                            Email{' '}
                            <span className="text-muted-foreground">
                                (opsional)
                            </span>
                        </Label>
                        <Input
                            id="requester-email"
                            type="email"
                            value={form.data.email}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                            placeholder="budi@perusahaan.com"
                        />
                        <InputError message={form.errors.email} />
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

Requesters.layout = {
    breadcrumbs: [{ title: 'Pemohon', href: requestersIndex() }],
};
