---
paths:
  - app/Models/Requester.php
---

# Models

## The requester list is chosen from, never typed into
`tasks.requester_id` is who asked for the work; `tasks.created_by` is who filed it. They are not interchangeable, and a requester is usually not a user of the app at all (a client, a stakeholder), which is why a user picker cannot hold it.

The list is deliberately select-only: only `managesTeam()` (Owner, Manager) writes it, through `/requesters`; everyone else picks an existing row on a task form. Do not add a "create on type" combobox — free text puts "Budi", "budi" and "Pak Budi" in the same column and every report grouped by requester is then wrong. `requesters.name_normalized` (lowercased, squished) carries the per-workspace unique index, and `RequesterStoreRequest` checks it through `Requester::isListed()` so a duplicate is a 422 on `name` rather than a 500 from the index.

Retiring beats deleting: `RequesterController::destroy()` refuses a requester any task names, and `is_active = false` takes them out of the picker while the tasks keep the name. `existsAsActiveRequester()` enforces that on the way in, so a retired row cannot be handed out again.

The list is flat and workspace-wide — no `covers()` check, unlike `OrgUnitPolicy`. If one division's requesters ever need keeping out of another's picker, add `org_unit_id` to the row first; do not fake the split with a role.

`TaskPresenter::one()` reads `$task->requester`, so any query feeding it must eager load `requester:id,name,organization` (ProjectController and MemberWorkloadQuery already do; MonitoringQueryBudgetTest catches the omission).
