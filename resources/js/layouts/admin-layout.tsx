import { Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';

/**
 * Chrome for the platform operator area.
 *
 * Deliberately separate from the app sidebar: this area never has an active
 * workspace, so none of the workspace navigation applies here.
 */
export default function AdminLayout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-dvh bg-background">
            <header className="border-b">
                <div className="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4">
                    <div className="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                        <ShieldCheck className="size-4" aria-hidden="true" />
                    </div>

                    <div className="flex-1">
                        <p className="text-sm font-semibold">Panel Operator</p>
                        <p className="text-xs text-muted-foreground">
                            Kelola workspace perusahaan
                        </p>
                    </div>

                    <Button variant="outline" size="sm" asChild>
                        <Link href={logout()} as="button">
                            Keluar
                        </Link>
                    </Button>
                </div>
            </header>

            <main className="mx-auto max-w-6xl px-4 py-8">{children}</main>
        </div>
    );
}
