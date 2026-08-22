<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use App\Services\TaskHierarchy;
use Illuminate\Console\Command;

/**
 * Backfill for TSK-17: a task's progress now comes from its sub tasks, so rows
 * written before that rule — seeded or typed by hand — need one pass.
 */
class SyncTaskProgress extends Command
{
    protected $signature = 'task:sync-progress {--project= : Limit to one project id}';

    protected $description = 'Recompute the progress of every task that has sub tasks';

    public function handle(TaskHierarchy $hierarchy): int
    {
        $projects = Project::withoutGlobalScopes()
            ->when($this->option('project'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($projects as $project) {
            // Deepest first, so a parent reads children that are already right.
            $tasks = Task::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->orderByDesc('depth')
                ->get();

            foreach ($tasks as $task) {
                $before = [$task->progress, $task->status];

                $hierarchy->syncParentProgress($task);

                if ($before !== [$task->refresh()->progress, $task->status]) {
                    $total++;
                }
            }
        }

        $this->info("Selesai. {$total} task diperbarui.");

        return self::SUCCESS;
    }
}
