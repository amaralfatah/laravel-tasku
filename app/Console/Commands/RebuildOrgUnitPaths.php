<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\OrgUnitTree;
use Illuminate\Console\Command;

/**
 * Repair tool for R-4: recompute path and depth from parent_id when a move
 * was interrupted and left the materialized path inconsistent.
 */
class RebuildOrgUnitPaths extends Command
{
    protected $signature = 'orgunit:rebuild-path {--workspace= : Limit to one workspace id}';

    protected $description = 'Recompute org unit path and depth from parent_id';

    public function handle(OrgUnitTree $tree): int
    {
        $workspaces = Workspace::query()
            ->when($this->option('workspace'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($workspaces as $workspace) {
            $touched = $tree->rebuild($workspace->id);
            $total += $touched;

            $this->line("{$workspace->name}: {$touched} unit diperbaiki.");
        }

        $this->info("Selesai. {$total} unit diperbarui.");

        return self::SUCCESS;
    }
}
