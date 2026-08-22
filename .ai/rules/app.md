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

## Super admin never enters a workspace (SA-4)
A super admin is a platform operator with no membership anywhere — no row, and no virtual one either. `EnsureWorkspaceAccess` redirects them to `workspaces.index` for any `workspace` route, so `Tenancy::member()` is never a super admin and no policy needs a super-admin branch. Their only pages are `workspaces.*` (which carry no tenant context and scope their own queries with `withoutGlobalScopes()`) plus settings, which run `workspace:optional`.

Keep the invariant closed on both ends: `InvitationAcceptController::store()` refuses a super admin, and `tasku:super-admin` refuses to promote an account that still holds a membership.

Never reintroduce a virtual membership to give them access — it handed them BOD-1, and `hasFullScope()` then opened every project and task in the platform.

## Org units come from SAP and are too many to list
`tasku:import-org-structure --workspace=<id> [--prune]` mirrors the SAP CDS view `ZA_HRIS_ORGZ` into `org_units`.

The view carries 52 roots, but 51 are fragments SAP sends no parent edge for (`KEBUN 2 PAN`, `DISTRIK TANDUN`, four units named `-`). The import keeps only the subtree of `OrgStructureImporter::HOLDING` ('10000000', PT PERKEBUNANAN NUSANTARA I), drops the holding itself so its operating companies become the roots, and then drops the `EXCLUDED` list — the retired PTPN I/II/IV-XIV entities the restructuring folded into PalmCo, SupportingCo and SGN. `PTPN III (PERSERO)` is deliberately not excluded. Result: 9 roots, 13,863 units, 11 levels (depth 0-10). `--all` imports the raw forest and skips both the trim and the exclusions; `--root=` picks another holding. `OrgUnit::MAX_DEPTH` is 11 — the imported depth plus one level of headroom.

Rows are matched on `external_id` (the SAP object id), so a re-import updates in place. Units created by hand keep `external_id` null and the importer never touches them; `rebuildPaths()` likewise only rewrites paths where `external_id is not null`. `--prune` deletes units an earlier import created that the view no longer carries, deepest first, and skips any that still hold a project, a member placement, or a sub unit — which is also how a newly excluded entity gets removed from a workspace that already had it.

Because of the size, no page may ship the unit list. Serve the tree one level at a time (`OrgUnitController::level()`), and give every unit picker the `PicksOrgUnits` trait payload — `{default, can_choose}` — with the actual choosing done through `org-units.search`. `can_choose` deliberately mirrors `leadsAnyone()`, which is what the search endpoint authorizes.

## Org units are platform master data, scoped by path prefix
One SAP tree (`ZA_HRIS_ORGZ`) serves the whole platform, so `org_units` has no `workspace_id`. A workspace points at the node it runs (`workspaces.root_org_unit_id`) and owns that subtree; `WorkspaceOrgUnitScope` turns that into `path like <root path>%` on every OrgUnit query, and returns nothing when the workspace has no root yet. With no tenant context (console, super admin) the scope is off and the whole tree is visible — those callers must scope themselves.

Consequences to keep in mind:
- `WorkspaceMember::hasFullScope()` is no longer "anything": BOD-1 still has to pass `covers()`, which looks the unit up through the workspace subtree.
- Validation of a unit id from the browser goes through `ScopesValidationToWorkspace::existsAsOrgUnit()`, never a bare `exists:org_units,id` (the operator's own requests are the exception).
- Shaping the structure — `/organization` plus every `org-units` write — is super-admin only and lives in `routes/organization.php`, outside the `workspace` middleware. The one action a leader keeps is `org-units.search`, scoped to their branch, which feeds the member and project unit pickers.
- `tasku:import-org-structure` and `orgunit:rebuild-path` take no `--workspace`; they write the one tree.
