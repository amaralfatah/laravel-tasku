<?php

namespace App\Queries;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\WorkspaceMember;
use App\Support\TaskOrder;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Per-person workload summaries (MON-1).
 *
 * The counts are one aggregate query over the whole roster rather than a query
 * per member, which is what keeps this page inside its 2 second budget (NFR).
 */
class MemberWorkloadQuery
{
    public function __construct(protected Tenancy $tenancy) {}

    /**
     * The members the viewer is allowed to monitor, each with its summary.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forViewer(WorkspaceMember $viewer): array
    {
        $members = $this->visibleMembers($viewer);
        $summaries = $this->summaries($members->pluck('user_id')->all());

        return $members
            ->map(function (WorkspaceMember $member) use ($summaries, $viewer): array {
                $summary = $summaries[$member->user_id] ?? [];

                return [
                    'id' => $member->id,
                    'user_id' => $member->user_id,
                    'name' => $member->user->name,
                    'email' => $member->user->email,
                    'avatar' => $member->user->avatar,
                    'org_unit' => $member->orgUnit?->name,
                    'is_self' => $member->user_id === $viewer->user_id,
                    'active' => (int) ($summary['active'] ?? 0),
                    'overdue' => (int) ($summary['overdue'] ?? 0),
                    'done_recently' => (int) ($summary['done_recently'] ?? 0),
                    'unscheduled' => (int) ($summary['unscheduled'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Every task assigned to a user, across all projects (MON-2, MON-3).
     *
     * @return Collection<int, Task>
     */
    public function tasksFor(int $userId, ?string $from = null, ?string $to = null): Collection
    {
        return $this->inTreeOrder(
            $this->scheduledTasks([$userId], $from, $to)
                // `project.members` feeds the assignee picker on the person
                // page, where every project block has its own member list. The
                // export draws no picker, so it leaves that relation alone.
                ->with('project.members')
                ->get(),
        );
    }

    /**
     * The same tasks for several people at once, keyed by assignee id.
     *
     * A workspace wide export covers the whole roster, so the rows are read in
     * one pass instead of one query per person.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, Collection<int, Task>>
     */
    public function tasksForMany(array $userIds, ?string $from = null, ?string $to = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $grouped = [];

        foreach ($this->inTreeOrder($this->scheduledTasks($userIds, $from, $to)->get()) as $task) {
            // The query filters on the column, so this only guards the type.
            if ($task->assignee_id === null) {
                continue;
            }

            $grouped[$task->assignee_id][] = $task;
        }

        return array_map(fn (array $tasks): Collection => collect($tasks), $grouped);
    }

    /**
     * Put a set of tasks in reading order: by project, then down the tree.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    protected function inTreeOrder(Collection $tasks): Collection
    {
        return TaskOrder::tree($tasks);
    }

    /**
     * Tasks of the given people that overlap the range, ordered the way both
     * the person page and the export read them: project, then tree position.
     *
     * An open ended task counts as overlapping — unscheduled work is reported
     * separately, never hidden.
     *
     * @param  array<int, int>  $userIds
     * @return Builder<Task>
     */
    protected function scheduledTasks(array $userIds, ?string $from, ?string $to): Builder
    {
        return Task::query()
            ->whereIn('assignee_id', $userIds)
            // `workspace_id`, `org_unit_id` and `created_by` are part of the
            // select because the project policy reads all three.
            ->with([
                'project:id,name,key,workspace_id,org_unit_id,created_by',
                'assignee:id,name,avatar_path',
                // The review check reads the parent's assignee, once per task.
                'parent:id,assignee_id',
                // Rendered on every task, so it is loaded rather than left to
                // one query per row (see MonitoringQueryBudgetTest).
                'requester:id,name,organization',
            ])
            ->when($from, fn (Builder $query, string $date) => $query->where(function (Builder $q) use ($date): void {
                $q->whereNull('due_date')->orWhereDate('due_date', '>=', $date);
            }))
            ->when($to, fn (Builder $query, string $date) => $query->where(function (Builder $q) use ($date): void {
                $q->whereNull('start_date')->orWhereDate('start_date', '<=', $date);
            }))
            ->orderBy('project_id')
            ->orderBy('path');
    }

    /**
     * Roster the viewer may monitor: the whole workspace for an Owner, the
     * viewer's own subtree for a leader below that, otherwise just themselves
     * (MON-1, MON-6).
     *
     * @return Collection<int, WorkspaceMember>
     */
    public function visibleMembers(WorkspaceMember $viewer): Collection
    {
        $query = WorkspaceMember::query()
            ->with(['user:id,name,email,avatar_path', 'orgUnit:id,name']);

        $scopePath = $viewer->managesTeam() ? $viewer->scopePath() : null;

        if ($viewer->hasFullScope()) {
            // No extra restriction.
        } elseif ($scopePath !== null) {
            $query->where(function (Builder $inner) use ($viewer, $scopePath): void {
                $inner->where('user_id', $viewer->user_id)
                    ->orWhereHas(
                        'orgUnit',
                        fn (Builder $unit) => $unit->where('path', 'like', $scopePath.'%'),
                    );
            });
        } else {
            $query->where('user_id', $viewer->user_id);
        }

        return $query
            ->get()
            ->sortBy(fn (WorkspaceMember $member): string => $member->user->name)
            ->values();
    }

    /**
     * One aggregate pass over the task table for the whole roster.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array<string, int>>
     */
    protected function summaries(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $today = now()->toDateString();
        $since = now()->subDays(30)->toDateString();

        return Task::query()
            ->whereIn('assignee_id', $userIds)
            ->selectRaw('assignee_id')
            ->selectRaw('count(*) filter (where status <> ?) as active', [TaskStatus::Done->value])
            ->selectRaw(
                'count(*) filter (where status <> ? and due_date is not null and due_date < ?) as overdue',
                [TaskStatus::Done->value, $today],
            )
            ->selectRaw(
                'count(*) filter (where status = ? and updated_at >= ?) as done_recently',
                [TaskStatus::Done->value, $since],
            )
            ->selectRaw(
                'count(*) filter (where status <> ? and due_date is null) as unscheduled',
                [TaskStatus::Done->value],
            )
            ->groupBy('assignee_id')
            ->get()
            ->keyBy('assignee_id')
            // The rows carry aggregate columns rather than task attributes, so
            // they are read through getAttribute rather than as properties.
            ->map(fn (Task $row): array => [
                'active' => (int) $row->getAttribute('active'),
                'overdue' => (int) $row->getAttribute('overdue'),
                'done_recently' => (int) $row->getAttribute('done_recently'),
                'unscheduled' => (int) $row->getAttribute('unscheduled'),
            ])
            ->all();
    }
}
