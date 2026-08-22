<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
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
        $this->authorize('monitorPeople', WorkspaceMember::class);

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
            'statuses' => TaskPresenter::statusOptions(),
            'priorities' => TaskPresenter::priorityOptions(),
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
     * Each block carries its own edit permission and assignee list, because
     * this page crosses projects: someone may contribute to one of them and
     * only be able to read another.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<int, array<string, mixed>>
     */
    protected function groupByProject(Collection $tasks, Request $request): array
    {
        $user = $request->user();
        $groups = [];

        foreach ($tasks->groupBy('project_id') as $group) {
            $project = $group->first()->project;
            $canEdit = $user->can('contribute', $project);

            $groups[] = [
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                ],
                'can_edit' => $canEdit,
                'assignees' => $this->assigneeOptions($project),
                'tasks' => TaskPresenter::collection($group, $user, $canEdit, $project->key),
            ];
        }

        usort($groups, fn (array $a, array $b): int => strcmp($a['project']['name'], $b['project']['name']));

        return $groups;
    }

    /**
     * People the tasks of this project may be reassigned to (TSK-4).
     *
     * @return array<int, array{id: int, name: string, avatar: string|null}>
     */
    protected function assigneeOptions(Project $project): array
    {
        return $project->members
            ->sortBy('name')
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar,
            ])
            ->values()
            ->all();
    }
}
