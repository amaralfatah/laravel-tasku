import { router } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { formatDateTime } from '@/lib/week';
import {
    index as notificationsIndex,
    read as readNotification,
    readAll as readAllNotifications,
} from '@/routes/notifications';

/** NTF-5: polling interval, in milliseconds. */
const POLL_INTERVAL = 45_000;

type NotificationItem = {
    id: number;
    type: string;
    type_label: string;
    message: string;
    is_read: boolean;
    created_at: string | null;
    actor: string | null;
};

/**
 * Notification bell (NTF-1..NTF-5).
 *
 * Polls every 45 seconds rather than holding a socket open — real-time is
 * explicitly out of scope for the MVP.
 */
export function NotificationBell() {
    const [unread, setUnread] = useState(0);
    const [items, setItems] = useState<NotificationItem[]>([]);

    const load = useCallback(() => {
        // Skip polling while the tab is hidden; it resumes on focus.
        if (document.visibilityState === 'hidden') {
            return;
        }

        fetch(notificationsIndex().url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                if (data) {
                    setUnread(data.unread);
                    setItems(data.items);
                }
            })
            .catch(() => undefined);
    }, []);

    useEffect(() => {
        load();

        const timer = setInterval(load, POLL_INTERVAL);
        document.addEventListener('visibilitychange', load);

        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', load);
        };
    }, [load]);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative size-9"
                    aria-label={
                        unread > 0
                            ? `Notifikasi, ${unread} belum dibaca`
                            : 'Notifikasi'
                    }
                >
                    <Bell className="size-4" />
                    {unread > 0 && (
                        <span className="absolute top-1 right-1 flex min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] leading-4 font-medium text-white tabular-nums">
                            {unread > 99 ? '99+' : unread}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-80">
                <DropdownMenuLabel className="flex items-center justify-between gap-2">
                    <span>Notifikasi</span>
                    {unread > 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs"
                            onClick={(event) => {
                                event.preventDefault();
                                router.post(
                                    readAllNotifications().url,
                                    {},
                                    { preserveScroll: true, onSuccess: load },
                                );
                            }}
                        >
                            <CheckCheck
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            Tandai semua
                        </Button>
                    )}
                </DropdownMenuLabel>

                <DropdownMenuSeparator />

                {items.length === 0 ? (
                    <p className="px-2 py-6 text-center text-sm text-muted-foreground">
                        Belum ada notifikasi.
                    </p>
                ) : (
                    <div className="max-h-96 overflow-y-auto">
                        {items.map((item) => (
                            <DropdownMenuItem
                                key={item.id}
                                className="flex-col items-start gap-0.5 py-2"
                                onSelect={() =>
                                    router.post(
                                        readNotification(item.id).url,
                                        {},
                                        { onSuccess: load },
                                    )
                                }
                            >
                                <span className="flex w-full items-center gap-2">
                                    {!item.is_read && (
                                        <span
                                            className="size-1.5 shrink-0 rounded-full bg-primary"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <span
                                        className={cn(
                                            'min-w-0 flex-1 truncate text-sm',
                                            !item.is_read && 'font-medium',
                                        )}
                                    >
                                        {item.message}
                                    </span>
                                </span>

                                <span className="pl-3.5 text-xs text-muted-foreground">
                                    {item.type_label}
                                    {item.actor && ` · ${item.actor}`}
                                    {item.created_at &&
                                        ` · ${formatDateTime(item.created_at)}`}
                                    {!item.is_read && (
                                        <span className="sr-only">
                                            {' '}
                                            belum dibaca
                                        </span>
                                    )}
                                </span>
                            </DropdownMenuItem>
                        ))}
                    </div>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
