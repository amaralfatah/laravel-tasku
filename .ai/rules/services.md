---
paths:
  - app/Services/TaskHierarchy.php
---

# Services

## Parent status rolls up from sub task status, not only the average
`syncParentProgress()` still overrules a status typed by hand on a task that has sub tasks (TSK-17), but "started" is now read from the children's status, not from the percentage alone: `$average > 0 || anyChildStarted($parent)`.

The average alone missed the case people hit most. `TaskStatus::InProgress::forcedProgress()` is null, so a sub task moved to Dikerjakan without a number keeps `progress = 0`. The average stayed 0, the parent was pushed back to To Do on every save, and the board looked stuck — a real task (GRO-26) sat in To Do with an in-progress sub task under it.

Done is unchanged and still stricter: it needs `$average >= 100` AND `allChildrenDone()`, so a child at 100% awaiting review does not close the task above it. Review on the parent still wins over everything.

Rows written before this need one pass of `php artisan task:sync-progress`.
