import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Network } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { ProgressBar } from '@/components/task/progress-bar';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { divisions } from '@/routes/monitoring';
import { ORG_UNIT_TYPE_LABELS } from '@/types/organization';

type UnitSummary = {
    id: number;
    name: string;
    type: string;
    depth: number;
    has_children: boolean;
    projects: number;
    tasks: number;
    done: number;
    in_progress: number;
    overdue: number;
    unscheduled: number;
    average_progress: number;
};

export default function MonitoringDivisions({
    units,
    current,
    trail,
}: {
    units: UnitSummary[];
    current: { id: number; name: string } | null;
    trail: { id: number; name: string }[];
}) {
    return (
        <>
            <Head title="Monitoring per divisi" />

            <div className="space-y-6">
                <PageHeader
                    title="Monitoring per divisi"
                    description="Angka setiap unit sudah termasuk seluruh unit di bawahnya."
                />

                <nav
                    className="flex flex-wrap items-center gap-1 text-sm"
                    aria-label="Jalur unit"
                >
                    <Link
                        href={divisions()}
                        className={cn(
                            'rounded px-2 py-1 hover:bg-muted',
                            current === null
                                ? 'font-medium'
                                : 'text-muted-foreground',
                        )}
                    >
                        Semua unit
                    </Link>

                    {trail.map((node, index) => (
                        <span key={node.id} className="flex items-center gap-1">
                            <ChevronRight
                                className="size-3.5 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <Link
                                href={divisions({
                                    query: { unit: node.id },
                                })}
                                aria-current={
                                    index === trail.length - 1
                                        ? 'page'
                                        : undefined
                                }
                                className={cn(
                                    'rounded px-2 py-1 hover:bg-muted',
                                    index === trail.length - 1
                                        ? 'font-medium'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {node.name}
                            </Link>
                        </span>
                    ))}
                </nav>

                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Unit</TableHead>
                                <TableHead className="text-right">
                                    Project
                                </TableHead>
                                <TableHead className="text-right">
                                    Task
                                </TableHead>
                                <TableHead className="text-right">
                                    Selesai
                                </TableHead>
                                <TableHead className="text-right">
                                    Berjalan
                                </TableHead>
                                <TableHead className="text-right">
                                    Terlambat
                                </TableHead>
                                <TableHead className="text-right">
                                    Tanpa jadwal
                                </TableHead>
                                <TableHead className="w-36">
                                    Rata-rata progress
                                </TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {units.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="py-12 text-center"
                                    >
                                        <Network
                                            className="mx-auto mb-3 size-8 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <p className="font-medium">
                                            Tidak ada unit di bawah sini
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Unit ini tidak punya sub unit.
                                        </p>
                                    </TableCell>
                                </TableRow>
                            )}

                            {units.map((unit) => (
                                <TableRow key={unit.id}>
                                    <TableCell>
                                        {unit.has_children ? (
                                            <Link
                                                href={divisions({
                                                    query: { unit: unit.id },
                                                })}
                                                className="flex items-center gap-2 hover:underline"
                                            >
                                                <span className="font-medium">
                                                    {unit.name}
                                                </span>
                                                <Badge
                                                    variant="secondary"
                                                    className="font-normal"
                                                >
                                                    {ORG_UNIT_TYPE_LABELS[
                                                        unit.type
                                                    ] ?? unit.type}
                                                </Badge>
                                                <ChevronRight
                                                    className="size-4 text-muted-foreground"
                                                    aria-hidden="true"
                                                />
                                            </Link>
                                        ) : (
                                            <span className="flex items-center gap-2">
                                                <span className="font-medium">
                                                    {unit.name}
                                                </span>
                                                <Badge
                                                    variant="secondary"
                                                    className="font-normal"
                                                >
                                                    {ORG_UNIT_TYPE_LABELS[
                                                        unit.type
                                                    ] ?? unit.type}
                                                </Badge>
                                            </span>
                                        )}
                                    </TableCell>

                                    <TableCell className="text-right tabular-nums">
                                        {unit.projects}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {unit.tasks}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {unit.done}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {unit.in_progress}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-right tabular-nums',
                                            unit.overdue > 0 &&
                                                'font-medium text-red-600 dark:text-red-400',
                                        )}
                                    >
                                        {unit.overdue}
                                    </TableCell>
                                    <TableCell className="text-right text-muted-foreground tabular-nums">
                                        {unit.unscheduled}
                                    </TableCell>
                                    <TableCell>
                                        <ProgressBar
                                            value={unit.average_progress}
                                            showLabel
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <p className="text-xs text-muted-foreground">
                    Terlambat = tanggal selesai sudah lewat dan status belum
                    Selesai. Task tanpa tanggal selesai tidak dihitung
                    terlambat, tapi muncul di kolom &ldquo;Tanpa jadwal&rdquo;.
                </p>
            </div>
        </>
    );
}

MonitoringDivisions.layout = {
    breadcrumbs: [{ title: 'Monitoring per divisi', href: divisions() }],
};
