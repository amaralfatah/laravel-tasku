import { Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';
import { index as workspacesIndex } from '@/routes/workspaces';

export default function WorkspaceNone({
    isSuperAdmin,
}: {
    isSuperAdmin: boolean;
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
                            Belum ada workspace aktif
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {isSuperAdmin
                                ? 'Akun ini adalah super admin. Buat workspace pertama dari halaman kelola workspace.'
                                : 'Akun Anda belum tergabung di workspace mana pun, atau workspace Anda sedang dinonaktifkan. Hubungi admin perusahaan Anda.'}
                        </p>
                    </div>

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
