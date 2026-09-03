import { Form, Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { logout } from '@/routes';
import { start } from '@/routes/workspace';
import { index as workspacesIndex } from '@/routes/workspaces';

/**
 * Where someone lands with no workspace to work in.
 *
 * Three different situations end up here, so the page says which one it is:
 * a brand new account (start one), an account whose workspace was switched off
 * (nothing to do but ask an admin), and the platform operator (who never works
 * inside a workspace and manages them from the roster instead).
 */
export default function WorkspaceNone({
    isSuperAdmin,
    canStart,
}: {
    isSuperAdmin: boolean;
    canStart: boolean;
}) {
    return (
        <>
            <Head title="Belum ada workspace" />
            <div className="flex min-h-dvh items-center justify-center p-6">
                <div className="w-full max-w-md space-y-6 text-center">
                    <div className="mx-auto flex size-12 items-center justify-center rounded-xl bg-muted">
                        <Building2
                            className="size-6 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>

                    <div className="space-y-2">
                        <h1 className="text-xl font-semibold">
                            {canStart
                                ? 'Mulai workspace Anda'
                                : 'Belum ada workspace aktif'}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {isSuperAdmin
                                ? 'Akun ini adalah super admin. Buat workspace pertama dari halaman kelola workspace.'
                                : canStart
                                  ? 'Beri nama ruang kerja Anda — bisa nama Anda sendiri, nama tim, atau nama perusahaan. Struktur dan anggotanya bisa ditambahkan kapan saja.'
                                  : 'Akun Anda belum tergabung di workspace mana pun, atau workspace Anda sedang dinonaktifkan. Hubungi admin perusahaan Anda.'}
                        </p>
                    </div>

                    {canStart && (
                        <Form
                            {...start.form()}
                            className="space-y-3 text-left"
                            resetOnSuccess
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            Nama workspace
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            autoFocus
                                            placeholder="Studio Rekayasa"
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <Button
                                        type="submit"
                                        className="w-full"
                                        disabled={processing}
                                    >
                                        {processing
                                            ? 'Menyiapkan…'
                                            : 'Mulai bekerja'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}

                    <div className="flex justify-center gap-3">
                        {isSuperAdmin && (
                            <Button asChild>
                                <Link href={workspacesIndex()}>
                                    Kelola workspace
                                </Link>
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={logout()} as="button">
                                Keluar
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}
