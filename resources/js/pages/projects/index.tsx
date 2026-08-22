import { Head, Link, router, useForm } from '@inertiajs/react';
import { FolderKanban, Plus } from 'lucide-react';
import { useState } from 'react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as projectsIndex, show, store } from '@/routes/projects';
import type { Option, OrgUnitOption } from '@/types/members';
import {
    PROJECT_STATUS_VARIANT
    
} from '@/types/projects';
import type {ProjectListItem} from '@/types/projects';

const ALL = 'all';

export default function Projects({
    projects,
    orgUnits,
    statuses,
    filters,
    can,
}: {
    projects: ProjectListItem[];
    orgUnits: OrgUnitOption[];
    statuses: Option[];
    filters: { org_unit_id: number | null; status: string };
    can: { create: boolean };
}) {
    const [createOpen, setCreateOpen] = useState(false);

    const form = useForm({
        name: '',
        description: '',
        org_unit_id: orgUnits[0]?.id ?? null,
        status: 'active',
    });

    const applyFilter = (patch: Record<string, string | number | null>) => {
        router.get(
            projectsIndex().url,
            {
                org_unit_id: filters.org_unit_id,
                status: filters.status || undefined,
                ...patch,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Project" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <PageHeader
                        title="Project"
                        description={`${projects.length} project dalam cakupan Anda.`}
                    />

                    {can.create && (
                        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                            <DialogTrigger asChild>
                                <Button disabled={orgUnits.length === 0}>
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Project baru
                                </Button>
                            </DialogTrigger>

                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Buat project</DialogTitle>
                                    <DialogDescription>
                                        Project menempel pada satu unit
                                        organisasi. Anggota bisa ditambahkan
                                        setelahnya.
                                    </DialogDescription>
                                </DialogHeader>

                                <form
                                    className="space-y-4"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        form.post(store().url, {
                                            onSuccess: () =>
                                                setCreateOpen(false),
                                        });
                                    }}
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="project-name">
                                            Nama project
                                        </Label>
                                        <Input
                                            id="project-name"
                                            required
                                            autoFocus
                                            value={form.data.name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Aplikasi Absensi"
                                        />
                                        <InputError
                                            message={form.errors.name}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="project-unit">
                                            Unit
                                        </Label>
                                        <Select
                                            value={String(
                                                form.data.org_unit_id ?? '',
                                            )}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'org_unit_id',
                                                    Number(value),
                                                )
                                            }
                                        >
                                            <SelectTrigger id="project-unit">
                                                <SelectValue placeholder="Pilih unit" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {orgUnits.map((unit) => (
                                                    <SelectItem
                                                        key={unit.id}
                                                        value={String(unit.id)}
                                                    >
                                                        {'— '.repeat(
                                                            unit.depth,
                                                        )}
                                                        {unit.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={form.errors.org_unit_id}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="project-description">
                                            Deskripsi
                                        </Label>
                                        <textarea
                                            id="project-description"
                                            rows={3}
                                            value={form.data.description}
                                            onChange={(event) =>
                                                form.setData(
                                                    'description',
                                                    event.target.value,
                                                )
                                            }
                                            className="min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            placeholder="Ringkas tujuan project ini"
                                        />
                                        <InputError
                                            message={form.errors.description}
                                        />
                                    </div>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setCreateOpen(false)}
                                        >
                                            Batal
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={form.processing}
                                        >
                                            {form.processing
                                                ? 'Membuat…'
                                                : 'Buat project'}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    )}
                </div>

                <div className="flex flex-wrap gap-3">
                    <Select
                        value={String(filters.org_unit_id ?? ALL)}
                        onValueChange={(value) =>
                            applyFilter({
                                org_unit_id: value === ALL ? null : Number(value),
                            })
                        }
                    >
                        <SelectTrigger className="w-56" aria-label="Filter unit">
                            <SelectValue placeholder="Semua unit" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Semua unit</SelectItem>
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

                    <Select
                        value={filters.status || ALL}
                        onValueChange={(value) =>
                            applyFilter({
                                status: value === ALL ? null : value,
                            })
                        }
                    >
                        <SelectTrigger
                            className="w-44"
                            aria-label="Filter status"
                        >
                            <SelectValue placeholder="Semua status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Semua status</SelectItem>
                            {statuses.map((status) => (
                                <SelectItem
                                    key={status.value}
                                    value={status.value}
                                >
                                    {status.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {projects.length === 0 ? (
                    <div className="rounded-xl border border-dashed bg-card/50 p-12 text-center">
                        <div
                            className="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-accent"
                            aria-hidden="true"
                        >
                            <FolderKanban className="size-6 text-accent-foreground" />
                        </div>
                        <p className="font-medium">Belum ada project</p>
                        <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                            {can.create
                                ? 'Buat project pertama dan tempelkan ke unit yang sesuai.'
                                : 'Anda akan melihat project setelah didaftarkan sebagai anggota.'}
                        </p>
                    </div>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {projects.map((project) => (
                            <li key={project.id}>
                                <Link
                                    href={show(project.id)}
                                    className="flex h-full flex-col gap-2 rounded-xl border bg-card p-4 shadow-sm transition-[box-shadow,border-color,transform] duration-150 ease-out hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="font-medium">
                                            {project.name}
                                        </span>
                                        <Badge
                                            variant={
                                                PROJECT_STATUS_VARIANT[
                                                    project.status
                                                ]
                                            }
                                        >
                                            {project.status_label}
                                        </Badge>
                                    </div>

                                    {project.description && (
                                        <p className="line-clamp-2 text-sm text-muted-foreground">
                                            {project.description}
                                        </p>
                                    )}

                                    <div className="mt-auto flex items-center gap-3 pt-2 text-xs text-muted-foreground">
                                        <span>{project.org_unit.name}</span>
                                        <span aria-hidden="true">·</span>
                                        <span className="tabular-nums">
                                            {project.members_count} anggota
                                        </span>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Projects.layout = {
    breadcrumbs: [{ title: 'Project', href: projectsIndex() }],
};
