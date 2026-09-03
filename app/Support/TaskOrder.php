<?php

namespace App\Support;

use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * Reading order for a set of tasks: by project, then down the tree.
 *
 * Neither `path` nor `wbs_number` sorts correctly in the database — both are
 * text columns, so `/10/` lands before `/2/` and `1.10` before `1.9`. The
 * numbers are compared naturally here instead, which is what makes a parent's
 * sub tasks follow it rather than scatter through the list.
 *
 * Every view that draws the hierarchy reads this order, the exports included;
 * dropping it is what breaks a Gantt into an unreadable shuffle.
 */
class TaskOrder
{
    /**
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    public static function tree(Collection $tasks): Collection
    {
        return $tasks
            ->sort(fn (Task $left, Task $right): int => $left->project_id <=> $right->project_id
                ?: strnatcmp($left->wbs_number, $right->wbs_number))
            ->values();
    }
}
