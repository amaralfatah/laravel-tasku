<?php

namespace App\Console\Commands;

use App\Services\OrgUnitTree;
use Illuminate\Console\Command;

/**
 * Repair tool for R-4: recompute path and depth from parent_id when a move
 * was interrupted and left the materialized path inconsistent.
 */
class RebuildOrgUnitPaths extends Command
{
    protected $signature = 'orgunit:rebuild-path';

    protected $description = 'Recompute org unit path and depth from parent_id';

    public function handle(OrgUnitTree $tree): int
    {
        $touched = $tree->rebuild();

        $this->info("Selesai. {$touched} unit diperbarui.");

        return self::SUCCESS;
    }
}
