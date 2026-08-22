import { Head, router, useForm } from '@inertiajs/react';
import { Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { OrgUnitPicker } from '@/components/org-unit-picker';
import { ProjectHeader } from '@/components/project/project-header';
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
import { UserInfo } from '@/components/user-info';
import { normalizeProjectKey, PROJECT_KEY_MAX_LENGTH } from '@/lib/project-key';
import {
    destroy as removeMember,
    store as addMember,
} from '@/routes/project-members';
import {
    destroy as destroyProject,
    index as projectsIndex,
    show,
    update as updateProject,
} from '@/routes/projects';
import type { User } from '@/types';
import type { NamedRef, Option, OrgUnitPickerProps } from '@/types/members';
import type { ProjectDetail } from '@/types/projects';

export default function ProjectSettings({
    project,
    unitPicker,
    statuses,
    candidates,
    can,
}: {
    project: ProjectDetail;
    unitPicker: OrgUnitPickerProps;
    statuses: Option[];
    candidates: { id: number; name: string; email: string }[];
    can: { edit: boolean; contribute: boolean };
}) {
    const [editOpen, setEditOpen] = useState(false);
    const [candidateId, setCandidateId] = useState<string>('');

    // The form carries only the id; the picker needs a name to show.
    const [editUnit, setEditUnit] = useState<NamedRef | null>(project.org_unit);

    const editForm = useForm({
        name: project.name,
        key: project.key,
        description: project.description ?? '',
        org_unit_id: project.org_unit.id as number | null,
        status: project.status,
    });

    return (
        <>
            <Head title={project.name} />

            <div className="space-y-6">
                <ProjectHeader project={project} active="settings" />

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        {project.description && (
                            <p className="max-w-2xl text-sm">
                                {project.description}
                            </p>
                        )}
                        {project.created_by && (
                            <p className="text-sm text-muted-foreground">
                                Dibuat oleh {project.created_by}
                            </p>
                        )}
                    </div>

                    {can.edit && (
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={() => setEditOpen(true)}
                            >
                                Ubah project
                            </Button>
                            <Button
                                variant="ghost"
                                className="text-destructive hover:text-destructive"
                                onClick={() => {
                                    if (
                                        confirm(
                                            `Hapus project "${project.name}"? Project bisa dipulihkan oleh admin.`,
                                        )
                                    ) {
                                        router.delete(
                                            destroyProject(project.id).url,
                                        );
                                    }
                                }}
                            >
                                Hapus
                            </Button>
                        </div>
                    )}
                </div>

                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="font-medium">
                            Anggota project ({project.members.length})
                        </h2>

                        {can.edit && candidates.length > 0 && (
                            <div className="flex gap-2">
                                <Select
                                    value={candidateId}
                                    onValueChange={setCandidateId}
                                >
                                    <SelectTrigger
                                        className="w-64"
                                        aria-label="Pilih anggota workspace"
                                    >
                                        <SelectValue placeholder="Pilih anggota" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {candidates.map((candidate) => (
                                            <SelectItem
                                                key={candidate.id}
                                                value={String(candidate.id)}
                                            >
                                                {candidate.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <Button
                                    disabled={candidateId === ''}
                                    onClick={() =>
                                        router.post(
                                            addMember(project.id).url,
                                            { user_id: Number(candidateId) },
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    setCandidateId(''),
                                            },
                                        )
                                    }
                                >
                                    <UserPlus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Tambah
                                </Button>
                            </div>
                        )}
                    </div>

                    <ul className="divide-y rounded-lg border">
                        {project.members.length === 0 && (
                            <li className="p-8 text-center text-sm text-muted-foreground">
                                Belum ada anggota. Tambahkan anggota agar mereka
                                bisa mengerjakan task di project ini.
                            </li>
                        )}

                        {project.members.map((member) => (
                            <li
                                key={member.id}
                                className="flex min-h-14 items-center gap-2 px-3"
                            >
                                <UserInfo
                                    user={member as unknown as User}
                                    showEmail
                                />

                                {can.edit && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-8 shrink-0 text-destructive hover:text-destructive"
                                        aria-label={`Keluarkan ${member.name}`}
                                        onClick={() =>
                                            router.delete(
                                                removeMember([
                                                    project.id,
                                                    member.id,
                                                ]).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>

            <Dialog open={editOpen} onOpenChange={setEditOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Ubah project</DialogTitle>
                        <DialogDescription>
                            Memindahkan project ke unit lain juga mengubah siapa
                            saja yang dapat memantaunya.
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            editForm.patch(updateProject(project.id).url, {
                                preserveScroll: true,
                                onSuccess: () => setEditOpen(false),
                            });
                        }}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="edit-name">Nama project</Label>
                            <Input
                                id="edit-name"
                                required
                                value={editForm.data.name}
                                onChange={(event) =>
                                    editForm.setData('name', event.target.value)
                                }
                            />
                            <InputError message={editForm.errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="edit-key">Key</Label>
                            <Input
                                id="edit-key"
                                required
                                value={editForm.data.key}
                                maxLength={PROJECT_KEY_MAX_LENGTH}
                                className="w-40 font-mono tracking-wide uppercase"
                                onChange={(event) =>
                                    editForm.setData(
                                        'key',
                                        normalizeProjectKey(event.target.value),
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Prefix referensi task project ini. Menggantinya
                                mengubah setiap referensi, misalnya{' '}
                                {project.key}-1 menjadi{' '}
                                {editForm.data.key || '…'}-1.
                            </p>
                            <InputError message={editForm.errors.key} />
                        </div>

                        <div className="grid gap-2">
                            <Label>Unit</Label>
                            <OrgUnitPicker
                                value={editUnit}
                                canChoose={unitPicker.can_choose}
                                onChange={(picked) => {
                                    setEditUnit(picked);
                                    editForm.setData(
                                        'org_unit_id',
                                        picked?.id ?? null,
                                    );
                                }}
                            />
                            <InputError message={editForm.errors.org_unit_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="edit-status">Status</Label>
                            <Select
                                value={editForm.data.status}
                                onValueChange={(value) =>
                                    editForm.setData(
                                        'status',
                                        value as ProjectDetail['status'],
                                    )
                                }
                            >
                                <SelectTrigger id="edit-status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
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
                            <InputError message={editForm.errors.status} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="edit-description">Deskripsi</Label>
                            <textarea
                                id="edit-description"
                                rows={3}
                                value={editForm.data.description}
                                onChange={(event) =>
                                    editForm.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                                className="min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            <InputError message={editForm.errors.description} />
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditOpen(false)}
                            >
                                Batal
                            </Button>
                            <Button
                                type="submit"
                                disabled={editForm.processing}
                            >
                                {editForm.processing ? 'Menyimpan…' : 'Simpan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

ProjectSettings.layout = ({ project }: { project: ProjectDetail }) => ({
    breadcrumbs: [
        { title: 'Project', href: projectsIndex() },
        { title: project.name, href: show(project.id) },
    ],
});
