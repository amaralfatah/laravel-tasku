<?php

namespace App\Queries;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Collection;

/**
 * Consolidated figures for a holding: one row per operating company.
 *
 * Counted in grouped queries rather than company by company, so a group of
 * thirty entities costs the same handful of statements as a group of two. The
 * global tenant scope is off throughout — this deliberately reads across
 * companies, which is the one place in the application that does.
 */
class GroupSummaryQuery
{
    /**
     * @param  Collection<int, Workspace>  $companies
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     members: int,
     *     projects: int,
     *     tasks: int,
     *     done: int,
     *     overdue: int,
     *     progress: int,
     * }>
     */
    public function forCompanies(Collection $companies): array
    {
        $ids = $companies->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $members = $this->memberCounts($ids);
        $projects = $this->projectCounts($ids);
        $tasks = $this->taskCounts($ids);

        return $companies
            ->map(function (Workspace $company) use ($members, $projects, $tasks): array {
                $row = $tasks[$company->id] ?? ['tasks' => 0, 'done' => 0, 'overdue' => 0];
                $total = (int) $row['tasks'];
                $done = (int) $row['done'];

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'slug' => $company->slug,
                    'members' => $members[$company->id] ?? 0,
                    'projects' => $projects[$company->id] ?? 0,
                    'tasks' => $total,
                    'done' => $done,
                    'overdue' => (int) $row['overdue'],
                    // Share of finished work, so a small company and a large
                    // one are comparable at a glance.
                    'progress' => $total === 0 ? 0 : (int) round($done / $total * 100),
                ];
            })
            ->all();
    }

    /**
     * Totals across the whole group, for the headline row.
     *
     * @param  array<int, array<string, int|string>>  $rows
     * @return array{companies: int, projects: int, tasks: int, done: int, overdue: int, progress: int}
     */
    public function totals(array $rows): array
    {
        $sum = fn (string $key): int => (int) array_sum(array_column($rows, $key));

        $tasks = $sum('tasks');
        $done = $sum('done');

        return [
            'companies' => count($rows),
            'projects' => $sum('projects'),
            'tasks' => $tasks,
            'done' => $done,
            'overdue' => $sum('overdue'),
            'progress' => $tasks === 0 ? 0 : (int) round($done / $tasks * 100),
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    protected function memberCounts(array $ids): array
    {
        return WorkspaceMember::withoutGlobalScopes()
            ->whereIn('workspace_id', $ids)
            ->groupBy('workspace_id')
            ->selectRaw('workspace_id, count(*) as aggregate')
            ->pluck('aggregate', 'workspace_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Live projects only: an archived one is history and would flatter or
     * drag a company's numbers for no reason.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    protected function projectCounts(array $ids): array
    {
        return Project::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->whereIn('workspace_id', $ids)
            ->groupBy('workspace_id')
            ->selectRaw('workspace_id, count(*) as aggregate')
            ->pluck('aggregate', 'workspace_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{tasks: int, done: int, overdue: int}>
     */
    protected function taskCounts(array $ids): array
    {
        $today = now()->toDateString();

        return Task::withoutGlobalScopes()
            ->whereNull('tasks.deleted_at')
            ->whereIn('tasks.workspace_id', $ids)
            // Archived projects are out of the picture here too, and a soft
            // deleted project must not leave its tasks counted.
            ->whereExists(fn ($query) => $query
                ->from('projects')
                ->whereColumn('projects.id', 'tasks.project_id')
                ->whereNull('projects.deleted_at')
                ->where('projects.status', '!=', ProjectStatus::Archived->value)
            )
            ->groupBy('tasks.workspace_id')
            ->selectRaw('tasks.workspace_id')
            ->selectRaw('count(*) as tasks')
            ->selectRaw('count(*) filter (where status = ?) as done', [TaskStatus::Done->value])
            ->selectRaw(
                'count(*) filter (where due_date is not null and due_date < ? and status != ?) as overdue',
                [$today, TaskStatus::Done->value],
            )
            ->get()
            ->keyBy('workspace_id')
            // Raw aggregate columns, not real Task attributes — getAttribute()
            // reads them without PHPStan mistaking this for the Task shape.
            ->map(fn (Task $row): array => [
                'tasks' => (int) $row->getAttribute('tasks'),
                'done' => (int) $row->getAttribute('done'),
                'overdue' => (int) $row->getAttribute('overdue'),
            ])
            ->all();
    }
}
