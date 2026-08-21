<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\WorkspaceMember;
use App\Queries\MemberWorkloadQuery;
use App\Support\TaskPresenter;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-person monitoring (6.10) — the direct replacement for the per-programmer
 * spreadsheet.
 */
class PersonController extends Controller
{
    public function __construct(
        protected Tenancy $tenancy,
        protected MemberWorkloadQuery $workload,
    ) {}

    /**
     * Roster with per-member summaries (MON-1).
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', WorkspaceMember::class);

        $viewer = $this->tenancy->member();

        return Inertia::render('monitoring/people', [
            'members' => $this->workload->forViewer($viewer),
            'viewerUserId' => $viewer->user_id,
        ]);
    }

    /**
     * One person's tasks across every project, as a hierarchy with a weekly
     * timeline (MON-2, MON-3, MON-4, MON-5).
     */
    public function show(Request $request, WorkspaceMember $member): Response
    {
        $this->authorize('viewMember', $member);

        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        $tasks = $this->workload->tasksFor($member->user_id, $from, $to);
        $member->load(['user:id,name,email,avatar_path', 'orgUnit:id,name']);

        return Inertia::render('monitoring/person', [
            'member' => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'avatar' => $member->user->avatar,
                'org_unit' => $member->orgUnit?->name,
            ],
            'tasks' => $this->groupByProject($tasks, $request),
            'filters' => ['from' => $from, 'to' => $to],
            'isSelf' => $member->user_id === $request->user()->id,
        ]);
    }

    /**
     * Shortcut to the current user's own page, used as the landing page (MON-7).
     */
    public function me(Request $request): Response
    {
        $member = $this->tenancy->member();

        abort_if($member === null, 403);

        return $this->show($request, $member);
    }

    /**
     * Group the tasks by project so the page reads like the old per-programmer
     * sheet: one block per project, tasks nested inside it.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<int, array<string, mixed>>
     */
    protected function groupByProject($tasks, Request $request): array
    {
        return $tasks
            ->groupBy('project_id')
            ->map(fn ($group): array => [
                'project' => [
                    'id' => $group->first()->project->id,
                    'name' => $group->first()->project->name,
                ],
                'tasks' => TaskPresenter::collection($group, $request->user(), false),
            ])
            ->sortBy('project.name')
            ->values()
            ->all();
    }
}
