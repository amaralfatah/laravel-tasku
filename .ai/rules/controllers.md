---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Every hierarchy view sorts through TaskOrder, never through SQL
`path` and `wbs_number` are text columns, so Postgres puts `/10/` before `/2/` and `1.10` before `1.9`. `TaskFilters::applySort()` only pre-sorts; for the `wbs` sort the collection must be handed to `App\Support\TaskOrder::tree()` after `->get()`, which compares with `strnatcmp`.

`ProjectController::taskWorkspaceProps()` (board, list, timeline), `ProjectExportController` and `MemberWorkloadQuery::inTreeOrder()` all do this. Dropping it does not error — it silently shuffles a parent's sub tasks through the list, e.g. `1.10` landing second.

The flat sorts (`due_date`, `priority`, `created_at`) are correct as the database returns them and must not be re-sorted.

## Inline validation of an enum field needs Rule::enum
`TaskHierarchy::syncProgress()` reads the status with `TaskStatus::from()`, which throws a `ValueError` — a 500, not a 422 — on anything the enum does not carry.

`TaskController::move()` validates the dropped column with `Rule::enum(TaskStatus::class)` for that reason. Any inline `$request->validate()` that feeds an enum cast or an `Enum::from()` call needs the same; `'string'` alone is not enough.
