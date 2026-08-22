---
paths:
  - 'app/**'
---

# App

## Authorization is role tier + org unit scope, never a role bypass
BOD-1, BOD-2 and BOD-3 have identical abilities. What separates them is the slice of the org tree they reach, and that slice is derived from the member's own `org_unit_id` — there is no separate scope column.

Use `WorkspaceRole::managesTeam()` (rank <= 3) for "may this role do it at all", and `WorkspaceMember::covers($orgUnitId)` for "may they do it here". `hasFullScope()` is BOD-1 only and means the whole workspace; `leadsAnyone()` gates the roster, organisation and monitoring pages. Never write `if ($role->isManager()) return true;` style bypasses — that is what made BOD-2 identical to BOD-1 before.

Role handouts go through `WorkspaceRole::mayAssign()` / `assignableRoles()`: nobody may invite or promote someone above their own rank, or a self-promotion path opens up.

When selecting a narrow column list on a `Project` relation, always include `org_unit_id` — the policies read it.

## Projects are team-managed: creator runs the project they started
Anyone with an `org_unit_id` may start a project, ODS included — the Jira team-managed model. Two guards keep it contained:

- Placement is not user input for a non-leader. `ProjectController::placementUnitId()` forces `org_unit_id` to the member's own unit and ignores the request field; `ProjectPolicy::createIn()` enforces the same server-side. A leader picks any unit their scope covers.
- Authority over an existing project is `Project::isAdministeredBy()` — a leader whose scope covers the unit, OR `created_by === $user->id`. Use it, not a role check, for project update/delete, task delete and comment delete.

Starting a project does not carve it out of the org tree: the leader above still administers it, because `covers()` is checked first.

Any narrow column select on a `Project` relation must include `workspace_id`, `org_unit_id` and `created_by` — `isAdministeredBy()` reads all three, and a missing column silently revokes rights instead of erroring.
