import { Head, Link, router, useForm } from '@inertiajs/react';
import { FolderKanban, Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { OrgUnitPicker } from '@/components/org-unit-picker';
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
import {
    normalizeProjectKey,
    PROJECT_KEY_MAX_LENGTH,
    suggestProjectKey,
} from '@/lib/project-key';
import { index as projectsIndex, show, store } from '@/routes/projects';
import type { NamedRef, Option, OrgUnitPickerProps } from '@/types/members';
import { PROJECT_STATUS_VARIANT } from '@/types/projects';
import type { ProjectListItem } from '@/types/projects';

const ALL = 'all';

export default function Projects({
    projects,
    unitPicker,
    statuses,
    filters,
    can,
    openCreate,
}: {
    projects: ProjectListItem[];
    unitPicker: OrgUnitPickerProps;
    statuses: Option[];
    filters: {
        org_unit_id: number | null;
        org_unit: NamedRef | null;
        status: string;
    };
    can: { create: boolean };
    openCreate: boolean;
}) {
    const [createOpen, setCreateOpen] = useState(openCreate && can.create);
    // Once the key is typed by hand it stops following the name, the way the
    // Jira create form behaves.
    const [keyEdited, setKeyEdited] = useState(false);

    // The form carries only the id; the picker needs a name to show.
    const [createUnit, setCreateUnit] = useState<NamedRef | null>(
        unitPicker.default,
    );

    const form = useForm({
        name: '',
        key: '',
        description: '',
        org_unit_id: unitPicker.default?.id ?? null,
        status: 'active',
    });

    // Closing the dialog drops the sidebar's `?create=1` so a reload does not
    // bring it straight back. Rewriting the URL in place keeps the filters and
    // Inertia's own history state untouched.
    const handleCreateOpenChange = (open: boolean) => {
        setCreateOpen(open);

        if (open || !openCreate || typeof window === 'undefined') {
            return;
        }

        const url = new URL(window.location.href);
        url.searchParams.delete('create');
        window.history.replaceState(window.history.state, '', url.toString());
    };

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
                        <Dialog
                            open={createOpen}
                            onOpenChange={handleCreateOpenChange}
                        >
                            <DialogTrigger asChild>
                                <Button
                                    disabled={
                                        !unitPicker.can_choose &&
                                        unitPicker.default === null
                                    }
                                >
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
                                            onSuccess: () => {
                                                handleCreateOpenChange(false);
                                                setKeyEdited(false);
                                                form.reset();
                                            },
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
                                            onChange={(event) => {
                                                const name = event.target.value;

                                                form.setData((data) => ({
                                                    ...data,
                                                    name,
                                                    key: keyEdited
                                                        ? data.key
                                                        : suggestProjectKey(
                                                              name,
                                                          ),
                                                }));
                                            }}
                                            placeholder="Aplikasi Absensi"
                                        />
                                        <InputError
                                            message={form.errors.name}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="project-key">Key</Label>
                                        <Input
                                            id="project-key"
                                            value={form.data.key}
                                            maxLength={PROJECT_KEY_MAX_LENGTH}
                                            className="w-40 font-mono tracking-wide uppercase"
                                            onChange={(event) => {
                                                setKeyEdited(true);
                                                form.setData(
                                                    'key',
                                                    normalizeProjectKey(
                                                        event.target.value,
                                                    ),
                                                );
                                            }}
                                            placeholder="AA"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Prefix singkat untuk mengenali
                                            project ini, huruf kapital dan
                                            angka. Dibuat otomatis dari nama
                                            bila dibiarkan kosong.
                                        </p>
                                        <InputError message={form.errors.key} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label>Unit</Label>
                                        {/* A member who leads nobody may only
                                            start a project in their own unit,
                                            which the server enforces anyway. */}
                                        <OrgUnitPicker
                                            value={createUnit}
                                            canChoose={unitPicker.can_choose}
                                            emptyLabel="Belum dipilih"
                                            onChange={(picked) => {
                                                setCreateUnit(picked);
                                                form.setData(
                                                    'org_unit_id',
                                                    picked?.id ?? null,
                                                );
                                            }}
                                        />
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
                                            onClick={() =>
                                                handleCreateOpenChange(false)
                                            }
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
                    <div className="w-72">
                        <OrgUnitPicker
                            value={filters.org_unit}
                            canChoose={unitPicker.can_choose}
                            emptyLabel="Semua unit"
                            clearLabel="Semua unit"
                            onChange={(picked) =>
                                applyFilter({ org_unit_id: picked?.id ?? null })
                            }
                        />
                    </div>

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
