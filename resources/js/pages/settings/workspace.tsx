import { Form, Head } from '@inertiajs/react';
import WorkspaceController from '@/actions/App/Http/Controllers/Settings/WorkspaceController';
import { AvatarField } from '@/components/avatar-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/workspace/settings';

type WorkspaceSettings = {
    name: string;
    slug: string;
    logo: string | null;
};

export default function WorkspaceSettings({
    workspace,
}: {
    workspace: WorkspaceSettings;
}) {
    return (
        <>
            <Head title="Pengaturan workspace" />

            <h1 className="sr-only">Pengaturan workspace</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Workspace"
                    description="Perbarui nama dan logo workspace yang Anda kelola"
                />

                <Form
                    {...WorkspaceController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label>Logo workspace</Label>

                                <AvatarField
                                    name={workspace.name}
                                    currentUrl={workspace.logo}
                                    field="logo"
                                    shape="square"
                                    label="Unggah logo"
                                    alt={`Logo ${workspace.name}`}
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.logo}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama workspace</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={workspace.name}
                                    name="name"
                                    required
                                    placeholder="Nama perusahaan atau usaha Anda"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />

                                {/*
                                 * The slug is the workspace's address and stays
                                 * put on a rename, the way a Jira site keeps its
                                 * URL when the site name changes. Saying so here
                                 * stops the rename from feeling destructive.
                                 */}
                                <p className="text-sm text-muted-foreground">
                                    Tautan workspace tetap{' '}
                                    <span className="font-mono">
                                        {workspace.slug}
                                    </span>{' '}
                                    setelah nama diubah.
                                </p>
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-workspace-button"
                                >
                                    {processing ? 'Menyimpan…' : 'Simpan'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

WorkspaceSettings.layout = {
    breadcrumbs: [
        {
            title: 'Pengaturan workspace',
            href: edit(),
        },
    ],
};
