import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { edit as editWorkspace } from '@/routes/workspace/settings';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profil',
        href: edit(),
        icon: null,
    },
    {
        title: 'Keamanan',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Tampilan',
        href: editAppearance(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { tenancy } = usePage().props;

    /*
     * The workspace tab is the Owner's, not the account's: everyone else in a
     * workspace only has settings about themselves. It is deliberately outside
     * the `scale` gating the sidebar uses — a solo owner still names their own
     * workspace, the same way a one-person Jira site is renamed by the person
     * who created it.
     */
    const navItems: NavItem[] =
        tenancy?.membership?.role === 'owner'
            ? [
                  ...sidebarNavItems,
                  { title: 'Workspace', href: editWorkspace(), icon: null },
              ]
            : sidebarNavItems;

    return (
        <div>
            <Heading
                title="Pengaturan"
                description="Kelola profil dan pengaturan akun Anda"
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label="Pengaturan"
                    >
                        {navItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                aria-current={
                                    isCurrentOrParentUrl(item.href)
                                        ? 'page'
                                        : undefined
                                }
                                className={cn('w-full justify-start', {
                                    'bg-muted': isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
