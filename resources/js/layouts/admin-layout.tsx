import { Link, usePage } from '@inertiajs/react';
import { LogOut, ShieldCheck } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';
import { index as adminWorkspaces } from '@/routes/admin/workspaces';
import { me } from '@/routes/monitoring';

/**
 * Chrome for the platform operator area.
 *
 * Deliberately separate from the app sidebar: this area is about workspaces as
 * objects, not about the work inside them.
 */
export default function AdminLayout({ children }: PropsWithChildren) {
    const { auth } = usePage().props;

    return (
        <div className="min-h-dvh bg-muted/30">
            <header className="sticky top-0 z-20 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                <div className="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4">
                    <Link
                        href={adminWorkspaces()}
                        className="flex items-center gap-3"
                    >
                        <span className="flex size-9 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                            <ShieldCheck
                                className="size-4.5"
                                aria-hidden="true"
                            />
                        </span>
                        <span className="leading-tight">
                            <span className="block text-sm font-semibold">
                                Panel Operator
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Kelola workspace perusahaan
                            </span>
                        </span>
                    </Link>

                    <div className="ml-auto flex items-center gap-2">
                        <span className="hidden text-sm text-muted-foreground sm:inline">
                            {auth.user?.name}
                        </span>

                        <Button variant="outline" size="sm" asChild>
                            <Link href={me()}>Masuk aplikasi</Link>
                        </Button>

                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-9"
                            aria-label="Keluar"
                            asChild
                        >
                            <Link href={logout()} as="button">
                                <LogOut className="size-4" />
                            </Link>
                        </Button>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-6xl px-4 py-8">{children}</main>
        </div>
    );
}
