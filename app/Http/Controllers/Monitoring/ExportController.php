<?php

namespace App\Http\Controllers\Monitoring;

use App\Concerns\StreamsWorkbook;
use App\Enums\ExportZoom;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\WorkspaceMember;
use App\Queries\MemberWorkloadQuery;
use App\Services\WorkloadExport;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands the monitoring pages back as the workbook they replaced (MON-8).
 *
 * Both endpoints answer with the same shape the person page renders, so what
 * someone downloads is what they were just looking at, range filter included.
 */
class ExportController extends Controller
{
    use StreamsWorkbook;

    public function __construct(
        protected Tenancy $tenancy,
        protected MemberWorkloadQuery $workload,
        protected WorkloadExport $export,
    ) {}

    /**
     * One person, one sheet.
     */
    public function person(Request $request, WorkspaceMember $member): StreamedResponse
    {
        $this->authorize('viewMember', $member);

        $member->load(['user:id,name,email', 'orgUnit:id,name']);

        [$from, $to] = $this->range($request);
        $tasks = $this->workload->tasksForMany([$member->user_id], $from, $to);

        $spreadsheet = $this->export->build(
            [$this->sheetFor($member, $tasks[$member->user_id] ?? null)],
            zoom: $this->zoom($request),
        );

        return $this->streamWorkbook($spreadsheet, $member->user->name);
    }

    /**
     * The whole roster the viewer may monitor, one sheet each behind a cover.
     */
    public function people(Request $request): StreamedResponse
    {
        $this->authorize('monitorPeople', WorkspaceMember::class);

        $viewer = $this->tenancy->member();
        $members = $this->workload->visibleMembers($viewer);

        [$from, $to] = $this->range($request);
        $tasks = $this->workload->tasksForMany($members->pluck('user_id')->all(), $from, $to);

        $workspace = $this->tenancy->workspace()->name;

        $spreadsheet = $this->export->build(
            $members->map(fn (WorkspaceMember $member): array => $this->sheetFor(
                $member,
                $tasks[$member->user_id] ?? null,
            ))->all(),
            $workspace,
            $this->zoom($request),
        );

        return $this->streamWorkbook($spreadsheet, $workspace);
    }

    /**
     * @param  Collection<int, Task>|null  $tasks
     * @return array{name: string, subtitle: string|null, tasks: Collection<int, Task>}
     */
    protected function sheetFor(WorkspaceMember $member, ?Collection $tasks): array
    {
        return [
            'name' => $member->user->name,
            'subtitle' => $member->orgUnit->name ?? $member->user->email,
            'tasks' => $tasks ?? collect(),
        ];
    }

    /**
     * The zoom the timeline was showing. An unknown value falls back to the
     * week grid, which is the layout the report is normally read in.
     */
    protected function zoom(Request $request): ExportZoom
    {
        return $request->enum('zoom', ExportZoom::class) ?? ExportZoom::Week;
    }

    /**
     * The same `from`/`to` filter the person page uses.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function range(Request $request): array
    {
        return [
            $request->date('from')?->toDateString(),
            $request->date('to')?->toDateString(),
        ];
    }
}
