import { Head, router } from '@inertiajs/react';
import { Building2, ChevronRight } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { ProgressBar } from '@/components/task/progress-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { enter, index as groupIndex } from '@/routes/group';

type CompanyRow = {
    id: number;
    name: string;
    slug: string;
    members: number;
    projects: number;
    tasks: number;
    done: number;
    overdue: number;
    progress: number;
};

type Totals = {
    companies: number;
    projects: number;
    tasks: number;
    done: number;
    overdue: number;
    progress: number;
};

/**
 * The holding's own page: one row per operating company, nothing per task.
 *
 * The point of it is comparison — which entity is behind, and by how much —
 * so every column is an aggregate and the only action is to step into a
 * company, where the ordinary permissions apply again.
 */
export default function GroupIndex({
    holding,
    companies,
    totals,
    can,
}: {
    holding: { id: number; name: string; slug: string };
    companies: CompanyRow[];
    totals: Totals;
    can: { write: boolean };
}) {
    return (
        <>
            <Head title="Konsolidasi grup" />

            <div className="space-y-6">
                <PageHeader
                    title="Konsolidasi grup"
                    description={`${holding.name} membawahi ${totals.companies} entitas. Angka di bawah adalah agregat, bukan detail task.`}
                />

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryTile label="Entitas" value={totals.companies} />
                    <SummaryTile
                        label="Project aktif"
                        value={totals.projects}
                    />
                    <SummaryTile label="Task" value={totals.tasks} />
                    <SummaryTile
                        label="Terlambat"
                        value={totals.overdue}
                        tone={totals.overdue > 0 ? 'warning' : undefined}
                    />
                </dl>

                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Entitas</TableHead>
                                <TableHead className="text-right">
                                    Anggota
                                </TableHead>
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
                                    Terlambat
                                </TableHead>
                                <TableHead className="w-40">Progress</TableHead>
                                <TableHead className="text-right">
                                    Aksi
                                </TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {companies.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="py-12 text-center"
                                    >
                                        <Building2
                                            className="mx-auto mb-3 size-8 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <p className="font-medium">
                                            Belum ada anak perusahaan aktif
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Operator platform yang menautkan
                                            entitas ke holding ini.
                                        </p>
                                    </TableCell>
                                </TableRow>
                            )}

                            {companies.map((company) => (
                                <TableRow key={company.id}>
                                    <TableCell className="font-medium">
                                        {company.name}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {company.members}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {company.projects}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {company.tasks}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {company.done}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {company.overdue > 0 ? (
                                            <Badge variant="outline">
                                                {company.overdue}
                                            </Badge>
                                        ) : (
                                            company.overdue
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <ProgressBar
                                            value={company.progress}
                                            showLabel
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    enter(company.slug).url,
                                                )
                                            }
                                        >
                                            {can.write ? 'Masuk' : 'Lihat'}
                                            <ChevronRight
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <p className="text-xs text-muted-foreground">
                    Data setiap entitas tetap terpisah. Masuk ke sebuah entitas
                    memindahkan konteks aplikasi ke entitas itu, dan hak akses
                    di dalamnya berlaku seperti biasa.
                </p>
            </div>
        </>
    );
}

function SummaryTile({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: 'warning';
}) {
    return (
        <div className="rounded-lg border p-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd
                className={
                    tone === 'warning'
                        ? 'text-2xl font-semibold text-warning tabular-nums'
                        : 'text-2xl font-semibold tabular-nums'
                }
            >
                {value}
            </dd>
        </div>
    );
}

GroupIndex.layout = {
    breadcrumbs: [{ title: 'Konsolidasi grup', href: groupIndex() }],
};
