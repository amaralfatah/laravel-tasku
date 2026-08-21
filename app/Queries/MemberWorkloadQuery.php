<?php

namespace App\Queries;

use App\Enums\TaskStatus;
use App\Models\OrgUnit;
use App\Models\Task;
use App\Models\WorkspaceMember;
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
        return Task::query()
            ->where('assignee_id', $userId)
            ->with(['project:id,name', 'assignee:id,name,avatar_path'])
            ->when($from, fn (Builder $query, string $date) => $query->where(function (Builder $q) use ($date): void {
                $q->whereNull('due_date')->orWhereDate('due_date', '>=', $date);
            }))
            ->when($to, fn (Builder $query, string $date) => $query->where(function (Builder $q) use ($date): void {
                $q->whereNull('start_date')->orWhereDate('start_date', '<=', $date);
            }))
            ->orderBy('project_id')
            ->orderBy('path')
            ->get();
    }

    /**
     * Roster the viewer may monitor: everyone for a manager, the subtree for a
     * scoped member, otherwise just themselves (MON-1, MON-6).
     *
     * @return Collection<int, WorkspaceMember>
     */
    protected function visibleMembers(WorkspaceMember $viewer): Collection
    {
        $query = WorkspaceMember::query()
            ->with(['user:id,name,email,avatar_path', 'orgUnit:id,name']);

        if ($viewer->role->isManager()) {
            // No extra restriction.
        } elseif ($viewer->monitorsSubtree()) {
            $scopePath = OrgUnit::query()->whereKey($viewer->scope_org_unit_id)->value('path');

            $query->where(function (Builder $inner) use ($viewer, $scopePath): void {
                $inner->where('user_id', $viewer->user_id);

                if ($scopePath !== null) {
                    $inner->orWhereHas(
                        'orgUnit',
                        fn (Builder $unit) => $unit->where('path', 'like', $scopePath.'%'),
                    );
                }
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
