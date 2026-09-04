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

## Workspace identity is the Owner's, placement is the operator's
Two controllers write the `workspaces` row and they are not interchangeable.

`App\Http\Controllers\WorkspaceController` (routes/workspaces.php, `super-admin`) is the operator console: it hands entities out and owns `parent_id`, `root_org_unit_id` and `is_active` through `WorkspaceUpdateRequest`.

`App\Http\Controllers\Settings\WorkspaceController` (routes/settings.php, `workspace` middleware, `settings/workspace`) is the customer's, and owns the name and the logo alone through `WorkspaceIdentityRequest` + `WorkspacePolicy::manageIdentity()` (`hasFullScope()`, i.e. Owner). Never reuse `WorkspaceUpdateRequest` there — it would hand a customer the group structure and their own activation switch through fields the form never renders. The policy has no `update()` for the same reason.

A rename also renames the root org unit when that node is the customer's own (`external_id` null), because a self-serve workspace named it after itself; a SAP-mirrored root keeps its name.

Slug is set on `creating` only, so renaming never moves the workspace's URL.

## Review is offered, never required, to finish a task
Anyone who may edit a task may set it to Done directly — assignee included. `TaskController::update()` and `move()` used to run a `guardApproval()` that refused Done unless the caller could `review` the task, which turned a two-tier flow into a toll gate on every task; it was removed on purpose, so do not reintroduce it.

The `review` status and `tasks.review` still exist for teams that want a second pair of eyes: submitting stamps `submitted_at`, and `TaskPolicy::review()` still keeps a worker from approving their own submission. Covered by tests/Feature/TaskReviewTest.php.
