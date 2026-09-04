---
paths:
  - app/Services/TaskHierarchy.php
  - app/Observers/TaskObserver.php
---

# Services

## A parent's percentage comes from its sub tasks; its status never does
The two halves of a task with sub tasks have different owners, and conflating them is the trap.

`syncParentProgress()` writes **`progress` only**. It is called from `TaskObserver::saved()` and `deleted()` for `$task->parent` — never for the task itself — and from `TaskHierarchy::delete()`, where a mass delete fires no model events. Saving the parent fires its own `saved`, which is what carries the figure up to the grandparent.

The **status is never derived**. It used to be, and it broke the board: `TaskController::move()` wrote the status a card was dropped on, the observer recomputed it from the children on `saved`, and the card snapped back with no error and no message. GRO-25 (`Optimasi #1`, three finished sub tasks) is the report it was found on. So a parent may sit at 100% and still be dragged to To Do, and one whose sub tasks are all done is not closed until somebody closes it — the full bar is the prompt, not the act.

Do not re-derive the status from `allChildrenDone()` or `anyChildStarted()`, in any guarded form. Every variant — only for Done, only on drag, only until a child changes — puts the same silent revert back somewhere else.

Two consequences worth knowing:

- `syncProgress()` returns early for a task that has children, dropping `progress` from the attributes. TSK-15's forced percentages (`TaskStatus::forcedProgress()`: Todo 0, Review/Done 100) would otherwise be written and then overwritten by the rollup a moment later, flashing the wrong figure — and forcing 100 on a Done parent is exactly what would pin it to that column again. TSK-15 and TSK-16 still govern a **leaf**, whose percentage really is its own.
- `php artisan task:sync-progress` backfills percentages for rows written before the rule. It moves no statuses; keep it that way.

There is no control anywhere for typing a percentage on a task that has sub tasks, which is the whole reason the rollup exists. A leaf's percentage is driven by its status alone, so it is effectively 0 or 100 — `TaskStatus::InProgress::forcedProgress()` is null and leaves the number where it was.

Covered by tests/Feature/TaskParentIndependenceTest.php.

## In Progress steps a leaf off 100, or the parent's bar lies
`TaskStatus::InProgress->forcedProgress()` is null on purpose — how far along the work is, is the worker's to say. But that left a leaf carried back off Done or Review sitting at 100, and since `syncParentProgress()` averages `progress`, the parent read 100% over "11 dari 12 sub task selesai". Reported bug.

So `syncProgress()` writes `TaskHierarchy::UNFINISHED_PROGRESS` (90, the figure a returned review has always used) when *all* of: the status actually changed, it changed to In Progress, the request sent no `progress` of its own, and the task is at 100. A percentage the user typed is theirs and falls through to the TSK-16 contradiction rules instead.

The guard sits *after* the `children()->exists()` early return, so a parent is never stepped back — its percentage belongs to its children, and writing 90 over their rollup would be the GRO-25 revert again, in the percentage instead of the status.

`TaskController::review()` bypasses `syncProgress()` and now reads the same constant.

Covered by tests/Feature/TaskParentIndependenceTest.php.
