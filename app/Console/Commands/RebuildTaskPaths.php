<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\TaskHierarchy;
use Illuminate\Console\Command;

/**
 * Repair tool for R-4: recompute path, depth and WBS from parent_task_id when
 * a move was interrupted.
 */
class RebuildTaskPaths extends Command
{
    protected $signature = 'task:rebuild-path {--project= : Limit to one project id}';

    protected $description = 'Recompute task path, depth and WBS numbering';

    public function handle(TaskHierarchy $hierarchy): int
    {
        $projects = Project::withoutGlobalScopes()
            ->when($this->option('project'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($projects as $project) {
            $touched = $hierarchy->rebuild($project);
            $total += $touched;

            $this->line("{$project->name}: {$touched} task diperbaiki.");
        }

        $this->info("Selesai. {$total} task diperbarui.");

        return self::SUCCESS;
    }
}
