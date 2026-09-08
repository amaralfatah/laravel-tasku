---
paths:
  - 'app/Http/Controllers/UserController.php, app/Http/Controllers/UserMembershipController.php, app/Actions/ChangeMemberRole.php, app/Actions/AddUserToWorkspace.php'
---

# Actions

## The operator user console reaches every tenant without breaking SA-4
`routes/users.php` (`super-admin`, no `workspace` middleware) lets the operator manage every account and every membership on the platform — including handing a workspace to a new Owner. SA-4 still holds: `Tenancy::member()` is never a super admin, no policy grew a super-admin branch, and `AddUserToWorkspace` refuses to give a super admin a membership.

Consequences to keep:
- `WorkspaceScope` is a no-op without a tenant, so every `WorkspaceMember` query here passes `withoutGlobalScopes()` and names its own `workspace_id`. Route model binding still resolves, which is why `UserMembershipController::guardOwnership()` checks `member.user_id` against the URL's user — otherwise `/users/1/memberships/9` would reach someone else's row.
- The "workspace keeps at least one Owner" rule lives in `App\Actions\ChangeMemberRole`, shared by `MemberController` (behind `WorkspaceMemberPolicy`) and the operator console (no policy at all). `WorkspaceMemberPolicy::isLastTopRole()` delegates to it. Do not re-inline that query anywhere — the tenant-scoped version returns nothing for the operator.
- Replacing an Owner is appoint-then-demote. The reverse order is refused by design.
- `users.is_active` is enforced twice and needs both: `EnsureActiveAccount` in the `web` group ends sessions already open, `Fortify::authenticateUsing` refuses new logins. `UserFactory` states `is_active` explicitly because `actingAs()` uses the model the factory returns, and a missing attribute reads as deactivated.
- `is_super_admin` is deliberately not editable in the UI; promotion stays with `tasku:super-admin`.
