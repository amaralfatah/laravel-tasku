import { Head, Link, router, usePage } from '@inertiajs/react';
import { ClipboardList, Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { WorkloadSheet } from '@/components/monitoring/workload-sheet';
import { PersonAiChat } from '@/components/task/person-ai-chat';
import { TaskCreateDialog } from '@/components/task/task-create-dialog';
import { TaskDetailModal } from '@/components/task/task-detail-modal';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import { SHEET_ZOOM_LABELS, SHEET_ZOOMS } from '@/lib/sheet-grid';
import type { SheetZoom } from '@/lib/sheet-grid';
import { me, people, person as personRoute } from '@/routes/monitoring';
import { exportMethod as exportPerson } from '@/routes/monitoring/person';
import type { Option } from '@/types/members';
import type { RequesterOption } from '@/types/requesters';
import type { TaskAssignee, TaskNode } from '@/types/tasks';
import type { Tenancy } from '@/types/tenancy';

type ProjectGroup = {
    project: { id: number; name: string; key: string };
    /** Whether the viewer may edit tasks of this project (varies per block). */
    can_edit: boolean;
    assignees: TaskAssignee[];
    tasks: TaskNode[];
};

type Member = {
    id: number;
    user_id: number;
    name: string;
    email: string;
    avatar: string | null;
    org_unit: string | null;
};

/**
 * One person's work across every project (MON-2..MON-5).
 *
 * The page is the per-programmer workbook rather than a view of its own: the
 * sheet below the controls is `WorkloadExport`'s output drawn in the browser,
 * so what is on screen and what downloads are the same document. See
 * {@link WorkloadSheet} — the layout belongs to `ContohLaporan.xlsx` and is not
 * this application's to restyle.
 */
export default function MonitoringPerson({
    member,
    tasks,
    statuses,
    priorities,
    requesters,
    filters,
    isSelf,
    aiModels,
    aiModel,
}: {
    member: Member;
    tasks: ProjectGroup[];
    statuses: Option[];
    priorities: Option[];
    requesters: RequesterOption[];
    filters: { from: string | null; to: string | null };
    isSelf: boolean;
    /** Empty unless the viewer may contribute to at least one of these projects. */
    aiModels: Option[];
    aiModel: string | null;
}) {
    const getInitials = useInitials();
    const [openTaskId, setOpenTaskId] = useState<number | null>(null);
    const [createParent, setCreateParent] = useState<TaskNode | null>(null);
    const [createGroup, setCreateGroup] = useState<ProjectGroup | null>(null);
    const [createOpen, setCreateOpen] = useState(false);

    // The sheet's reference layout is the weekly grid — four columns a month,
    // the one people diff against older copies of the report. The coarser
    // levels exist so a plan running over several years fits on a screen.
    const [zoom, setZoom] = useState<SheetZoom>('week');

    const canMonitor = usePage().props.tenancy.membership?.can_monitor ?? false;

    /**
     * The open task, with the assignee list of the project it belongs to and
     * the family around it, so the modal shows sub tasks here as it does on a
     * project page.
     *
     * The family is read from the same block, which holds this member's tasks
     * and no others. That is the hierarchy the sheet already indents by, so the
     * modal agrees with the rows behind it.
     */
    const open = useMemo(() => {
        const group = tasks.find((item) =>
            item.tasks.some((task) => task.id === openTaskId),
        );
        const task = group?.tasks.find((item) => item.id === openTaskId);

        return group === undefined || task === undefined
            ? null
            : {
                  task,
                  group,
                  assignees: group.assignees,
                  subtasks: group.tasks.filter(
                      (item) => item.parent_task_id === task.id,
                  ),
                  parent:
                      group.tasks.find(
                          (item) => item.id === task.parent_task_id,
                      ) ?? null,
              };
    }, [tasks, openTaskId]);

    // The download mirrors what is on screen, so the range filter and the zoom
    // both ride along.
    const exportUrl = exportPerson(member.id, {
        query: {
            from: filters.from ?? undefined,
            to: filters.to ?? undefined,
            zoom,
        },
    }).url;

    const applyRange = (patch: { from?: string | null; to?: string | null }) =>
        router.get(
            personRoute(member.id).url,
            {
                from: (patch.from ?? filters.from) || undefined,
                to: (patch.to ?? filters.to) || undefined,
            },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title={member.name} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center gap-3">
                    <Avatar className="size-12">
                        <AvatarImage src={member.avatar ?? undefined} alt="" />
                        <AvatarFallback>
                            {getInitials(member.name)}
                        </AvatarFallback>
                    </Avatar>

                    <div className="min-w-0 flex-1">
                        <h1 className="flex min-w-0 items-center gap-2 text-xl font-semibold">
                            <span className="truncate">{member.name}</span>
                            {isSelf && (
                                <Badge
                                    variant="secondary"
                                    className="shrink-0 font-normal"
                                >
                                    Anda
                                </Badge>
                            )}
                        </h1>
                        <p className="truncate text-sm text-muted-foreground">
                            {member.org_unit ?? member.email}
                        </p>
                    </div>

                    {/* One wrapping unit: the identity beside it is `flex-1`
                        with `min-w-0`, so loose buttons never pushed a row of
                        their own — they squeezed the name to nothing instead. */}
                    <div className="flex w-full shrink-0 gap-2 sm:w-auto">
                        <Button variant="outline" size="sm" asChild>
                            <a href={exportUrl}>
                                <Download aria-hidden="true" />
                                Ekspor Excel
                            </a>
                        </Button>

                        {/* The roster is not everyone's to open: a member who
                            leads nobody reaches their own timeline but is
                            refused `monitoring.people`. Reading yourself, the
                            sidebar already carries the way back. */}
                        {canMonitor && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={people()}>Semua anggota</Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid min-w-0 flex-1 gap-1.5 sm:flex-none">
                        <Label htmlFor="range-from" className="text-xs">
                            Dari tanggal
                        </Label>
                        <Input
                            id="range-from"
                            type="date"
                            className="w-full sm:w-44"
                            value={filters.from ?? ''}
                            onChange={(event) =>
                                applyRange({ from: event.target.value || null })
                            }
                        />
                    </div>

                    <div className="grid min-w-0 flex-1 gap-1.5 sm:flex-none">
                        <Label htmlFor="range-to" className="text-xs">
                            Sampai tanggal
                        </Label>
                        <Input
                            id="range-to"
                            type="date"
                            className="w-full sm:w-44"
                            value={filters.to ?? ''}
                            onChange={(event) =>
                                applyRange({ to: event.target.value || null })
                            }
                        />
                    </div>

                    {(filters.from || filters.to) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => applyRange({ from: null, to: null })}
                        >
                            Reset rentang
                        </Button>
                    )}

                    {/* Full width on a phone, where `ml-auto` left it stranded
                        against the right edge on a line of its own. Three
                        equal segments is what a control of a few exclusive
                        choices looks like at that width. */}
                    <div
                        className="flex w-full rounded-md border p-0.5 sm:ml-auto sm:w-auto"
                        role="group"
                        aria-label="Tingkat zoom"
                    >
                        {SHEET_ZOOMS.map((level) => (
                            <Button
                                key={level}
                                size="sm"
                                className="flex-1 sm:flex-none"
                                variant={zoom === level ? 'secondary' : 'ghost'}
                                aria-pressed={zoom === level}
                                onClick={() => setZoom(level)}
                            >
                                {SHEET_ZOOM_LABELS[level]}
                            </Button>
                        ))}
                    </div>
                </div>

                {tasks.length === 0 ? (
                    <div className="rounded-lg border p-12 text-center">
                        <ClipboardList
                            className="mx-auto mb-3 size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Belum ada task</p>
                        <p className="text-sm text-muted-foreground">
                            Tidak ada task yang ditugaskan pada rentang ini.
                        </p>
                    </div>
                ) : (
                    <WorkloadSheet
                        groups={tasks}
                        zoom={zoom}
                        onOpenTask={setOpenTaskId}
                    />
                )}
            </div>

            <TaskDetailModal
                task={open?.task ?? null}
                subtasks={open?.subtasks ?? []}
                parent={open?.parent ?? null}
                assignees={open?.assignees ?? []}
                requesters={requesters}
                statuses={statuses}
                priorities={priorities}
                onClose={() => setOpenTaskId(null)}
                onOpenTask={setOpenTaskId}
                onAddSubtask={
                    open
                        ? () => {
                              setCreateParent(open.task);
                              setCreateGroup(open.group);
                              setCreateOpen(true);
                          }
                        : undefined
                }
            />

            {createGroup && (
                <TaskCreateDialog
                    open={createOpen}
                    project={createGroup.project}
                    parent={createParent}
                    assignees={createGroup.assignees}
                    requesters={requesters}
                    statuses={statuses}
                    priorities={priorities}
                    onClose={() => setCreateOpen(false)}
                />
            )}

            {aiModels.length > 0 && aiModel !== null && (
                <PersonAiChat
                    memberId={member.id}
                    groups={tasks}
                    statuses={statuses}
                    models={aiModels}
                    defaultModel={aiModel}
                />
            )}
        </>
    );
}

/**
 * Own timeline or someone else's — the trail differs, because the roster above
 * it is closed to a member who leads nobody. Reading yourself always starts at
 * "Task saya", which is a page everybody may open.
 */
MonitoringPerson.layout = ({
    member,
    isSelf,
    tenancy,
}: {
    member: Member;
    isSelf: boolean;
    tenancy: Tenancy;
}) =>
    isSelf && !tenancy.membership?.can_monitor
        ? {
              wide: true,
              breadcrumbs: [
                  { title: 'Task saya', href: me() },
                  { title: 'Timeline', href: personRoute(member.id) },
              ],
          }
        : {
              wide: true,
              breadcrumbs: [
                  { title: 'Monitoring per anggota', href: people() },
                  { title: member.name, href: personRoute(member.id) },
              ],
          };
