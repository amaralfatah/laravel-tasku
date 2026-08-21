<?php

namespace App\Queries;

use App\Enums\TaskStatus;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;

/**
 * Per-unit rollups for the division monitoring page (6.11).
 *
 * A unit's numbers include everything in its subtree, so a division shows the
 * totals of all its sub divisions. The counts come from two aggregate queries
 * over the whole workspace, then get folded onto the tree in PHP — cheaper
 * than one query per unit, and correct regardless of tree depth.
 */
class DivisionSummaryQuery
{
    /**
     * Summaries for the direct children of `$parent`, or for the roots when it
     * is null (DIV-1, DIV-2).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forChildrenOf(?OrgUnit $parent): array
    {
        $units = OrgUnit::query()->orderBy('path')->get();

        if ($units->isEmpty()) {
            return [];
        }

        $projectCounts = $this->projectCountsByUnit();
        $taskCounts = $this->taskCountsByUnit();

        $children = $units->filter(
            fn (OrgUnit $unit): bool => $unit->parent_id === $parent?->id,
        );

        return $children
            ->map(function (OrgUnit $unit) use ($units, $projectCounts, $taskCounts): array {
                // Everything at or below this unit.
                $subtree = $units->filter(
                    fn (OrgUnit $candidate): bool => str_starts_with($candidate->path, $unit->path),
                );

                $projects = 0;
                $totals = ['total' => 0, 'done' => 0, 'in_progress' => 0, 'overdue' => 0, 'unscheduled' => 0, 'progress_sum' => 0];

                foreach ($subtree as $node) {
                    $projects += $projectCounts[$node->id] ?? 0;
                    $counts = $taskCounts[$node->id] ?? [];

                    foreach ($totals as $key => $value) {
                        $totals[$key] = $value + ($counts[$key] ?? 0);
                    }
                }

                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'type' => $unit->type,
                    'depth' => $unit->depth,
                    'has_children' => $units->contains(
                        fn (OrgUnit $candidate): bool => $candidate->parent_id === $unit->id,
                    ),
                    'projects' => $projects,
                    'tasks' => $totals['total'],
                    'done' => $totals['done'],
                    'in_progress' => $totals['in_progress'],
                    'overdue' => $totals['overdue'],
                    'unscheduled' => $totals['unscheduled'],
                    // DIV-3: average progress across the subtree's tasks.
                    'average_progress' => $totals['total'] === 0
                        ? 0
                        : (int) round($totals['progress_sum'] / $totals['total']),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Breadcrumb trail from the root down to the given unit (DIV-2).
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function trail(?OrgUnit $unit): array
    {
        if ($unit === null) {
            return [];
        }

        $ids = array_filter(explode('/', $unit->path));

        return OrgUnit::query()
            ->whereIn('id', $ids)
            ->orderBy('depth')
            ->get(['id', 'name'])
            ->map(fn (OrgUnit $node): array => ['id' => $node->id, 'name' => $node->name])
            ->all();
    }

    /**
     * @return array<int, int> project count keyed by org unit id
     */
    protected function projectCountsByUnit(): array
    {
        return Project::query()
            ->selectRaw('org_unit_id, count(*) as total')
            ->groupBy('org_unit_id')
            ->pluck('total', 'org_unit_id')
            ->all();
    }

    /**
     * One aggregate pass over the tasks, grouped by the unit their project
     * hangs off.
     *
     * @return array<int, array<string, int>>
     */
    protected function taskCountsByUnit(): array
    {
        $today = now()->toDateString();

        return Task::query()
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereNull('projects.deleted_at')
            ->selectRaw('projects.org_unit_id as unit_id')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where tasks.status = ?) as done', [TaskStatus::Done->value])
            ->selectRaw('count(*) filter (where tasks.status = ?) as in_progress', [TaskStatus::InProgress->value])
            ->selectRaw(
                // DIV-4: overdue is past due and not done.
                'count(*) filter (where tasks.status <> ? and tasks.due_date is not null and tasks.due_date < ?) as overdue',
                [TaskStatus::Done->value, $today],
            )
            ->selectRaw(
                // DIV-5: no due date is never overdue, it is reported separately.
                'count(*) filter (where tasks.due_date is null) as unscheduled',
            )
            ->selectRaw('coalesce(sum(tasks.progress), 0) as progress_sum')
            ->groupBy('projects.org_unit_id')
            ->get()
            ->keyBy('unit_id')
            ->map(fn ($row): array => [
                'total' => (int) $row->total,
                'done' => (int) $row->done,
                'in_progress' => (int) $row->in_progress,
                'overdue' => (int) $row->overdue,
                'unscheduled' => (int) $row->unscheduled,
                'progress_sum' => (int) $row->progress_sum,
            ])
            ->all();
    }
}
