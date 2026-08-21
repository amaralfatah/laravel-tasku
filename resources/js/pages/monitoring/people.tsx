import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CalendarOff, CheckCircle2, Users } from 'lucide-react';
import { useState } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { people, person } from '@/routes/monitoring';

type MemberSummary = {
    id: number;
    user_id: number;
    name: string;
    email: string;
    avatar: string | null;
    position: string | null;
    org_unit: string | null;
    is_self: boolean;
    active: number;
    overdue: number;
    done_recently: number;
    unscheduled: number;
};

export default function MonitoringPeople({
    members,
}: {
    members: MemberSummary[];
    viewerUserId: number;
}) {
    const getInitials = useInitials();
    const [search, setSearch] = useState('');

    const filtered = members.filter((member) =>
        member.name.toLowerCase().includes(search.toLowerCase()),
    );

    return (
        <>
            <Head title="Monitoring per orang" />

            <div className="space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">
                        Monitoring per orang
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Beban kerja setiap anggota dalam cakupan Anda, lintas
                        project.
                    </p>
                </div>

                <Input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Cari nama anggota"
                    aria-label="Cari nama anggota"
                    className="max-w-xs"
                />

                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Anggota</TableHead>
                                <TableHead>Unit</TableHead>
                                <TableHead className="text-right">
                                    Aktif
                                </TableHead>
                                <TableHead className="text-right">
                                    Terlambat
                                </TableHead>
                                <TableHead className="text-right">
                                    Tanpa jadwal
                                </TableHead>
                                <TableHead className="text-right">
                                    Selesai 30 hari
                                </TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {filtered.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-12 text-center"
                                    >
                                        <Users
                                            className="mx-auto mb-3 size-8 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <p className="font-medium">
                                            Tidak ada anggota yang cocok
                                        </p>
                                    </TableCell>
                                </TableRow>
                            )}

                            {filtered.map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell>
                                        <Link
                                            href={person(member.id)}
                                            className="flex items-center gap-3 hover:underline"
                                        >
                                            <Avatar className="size-8">
                                                <AvatarImage
                                                    src={
                                                        member.avatar ??
                                                        undefined
                                                    }
                                                    alt=""
                                                />
                                                <AvatarFallback className="text-xs">
                                                    {getInitials(member.name)}
                                                </AvatarFallback>
                                            </Avatar>

                                            <span className="min-w-0">
                                                <span className="block truncate font-medium">
                                                    {member.name}
                                                    {member.is_self && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="ml-2 font-normal"
                                                        >
                                                            Anda
                                                        </Badge>
                                                    )}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {member.position ??
                                                        member.email}
                                                </span>
                                            </span>
                                        </Link>
                                    </TableCell>

                                    <TableCell className="text-sm text-muted-foreground">
                                        {member.org_unit ?? '—'}
                                    </TableCell>

                                    <TableCell className="text-right tabular-nums">
                                        {member.active}
                                    </TableCell>

                                    <TableCell className="text-right">
                                        <span
                                            className={cn(
                                                'inline-flex items-center gap-1 tabular-nums',
                                                member.overdue > 0 &&
                                                    'font-medium text-red-600 dark:text-red-400',
                                            )}
                                        >
                                            {member.overdue > 0 && (
                                                <AlertTriangle
                                                    className="size-3.5"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {member.overdue}
                                        </span>
                                    </TableCell>

                                    <TableCell className="text-right">
                                        <span className="inline-flex items-center gap-1 text-muted-foreground tabular-nums">
                                            {member.unscheduled > 0 && (
                                                <CalendarOff
                                                    className="size-3.5"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {member.unscheduled}
                                        </span>
                                    </TableCell>

                                    <TableCell className="text-right">
                                        <span className="inline-flex items-center gap-1 tabular-nums">
                                            {member.done_recently > 0 && (
                                                <CheckCircle2
                                                    className="size-3.5 text-emerald-600"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {member.done_recently}
                                        </span>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

MonitoringPeople.layout = {
    breadcrumbs: [{ title: 'Monitoring per orang', href: people() }],
};
