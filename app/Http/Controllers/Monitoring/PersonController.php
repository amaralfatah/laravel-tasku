<?php

namespace App\Http\Controllers\Monitoring;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Queries\MemberWorkloadQuery;
use App\Support\TaskPresenter;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-person monitoring (6.10) — the direct replacement for the per-programmer
 * spreadsheet.
 */
class PersonController extends Controller
{
    /**
     * How far back the landing page carries finished work.
     *
     * Someone who has been here a year has hundreds of done tasks, and sending
     * every one of them to the browser to sit behind a collapsed toggle costs
     * a payload that grows for as long as the account lives. The timeline
     * still shows the lot.
     */
    protected const DONE_WINDOW_DAYS = 14;

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
        return Inertia::render('monitoring/person', $this->personProps($request, $member));
    }

    /**
     * Where a signed-in member lands (MON-7).
     *
     * The same tasks as the timeline, ordered by when they are due instead of
     * by project, so the first screen after signing in answers "what do I do
     * now" rather than "how does the quarter look". The gantt stays one click
     * away on `monitoring.person`.
     */
    public function me(Request $request): Response
    {
        $member = $this->tenancy->member();

        abort_if($member === null, 403);

        $props = $this->personProps($request, $member);
        [$groups, $olderDone] = $this->trimFinishedWork($props['tasks']);

        return Inertia::render('monitoring/focus', [
            ...$props,
            'tasks' => $groups,
            'doneWindowDays' => self::DONE_WINDOW_DAYS,
            'olderDone' => $olderDone,
        ]);
    }

    /**
     * Props shared by the timeline and the landing page, so the two views
     * cannot drift apart or cost a different number of queries.
     *
     * @return array<string, mixed>
     */
    protected function personProps(Request $request, WorkspaceMember $member): array
    {
        $this->authorize('viewMember', $member);

        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        $tasks = $this->workload->tasksFor($member->user_id, $from, $to);
        $member->load(['user:id,name,email,avatar_path', 'orgUnit:id,name']);

        return [
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
            'requesters' => TaskPresenter::requesterOptions(),
            'filters' => ['from' => $from, 'to' => $to],
            'isSelf' => $member->user_id === $request->user()->id,
        ];
    }

    /**
     * Drop finished work older than the window, and count what was dropped.
     *
     * The filtering happens after the tasks are serialised, never before: the
     * rollup percentage of a parent is averaged over the children in the same
     * collection, so removing a finished child any earlier would quietly move
     * its parent's number.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected function trimFinishedWork(array $groups): array
    {
        $cutoff = Carbon::now()->subDays(self::DONE_WINDOW_DAYS);
        $dropped = 0;
        $kept = [];

        foreach ($groups as $group) {
            $tasks = array_values(array_filter(
                $group['tasks'],
                function (array $task) use ($cutoff, &$dropped): bool {
                    if ($task['status'] !== TaskStatus::Done->value) {
                        return true;
                    }

                    // Work finished before the trail was kept has no date to
                    // judge by, and it is old by definition.
                    $finished = $task['completed_at'] === null
                        ? null
                        : Carbon::parse($task['completed_at']);

                    if ($finished !== null && $finished->greaterThanOrEqualTo($cutoff)) {
                        return true;
                    }

                    $dropped++;

                    return false;
                },
            ));

            if ($tasks !== []) {
                $kept[] = [...$group, 'tasks' => $tasks];
            }
        }

        return [$kept, $dropped];
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
                    'key' => $project->key,
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
