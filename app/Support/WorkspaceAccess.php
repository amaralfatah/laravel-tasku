<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Collection;

/**
 * Which workspaces a user may enter, and as what.
 *
 * Two ways in. The ordinary one is a stored membership. The second is a group:
 * someone who is an Owner or a Viewer of a holding reaches the operating
 * companies beneath it, and their membership is projected down into each of
 * them ({@see WorkspaceMember::projectInto()}).
 *
 * A stored membership always wins over a projected one, so a holding director
 * who is also a Manager inside one subsidiary keeps that narrower, real role
 * there rather than being handed the whole company.
 */
class WorkspaceAccess
{
    /**
     * Memberships the user holds, real and projected, keyed by workspace id.
     *
     * Inactive workspaces are left out entirely: they are not enterable, and a
     * holding being switched off must not keep opening doors downwards.
     *
     * @return Collection<int, WorkspaceMember>
     */
    public function memberships(User $user): Collection
    {
        if ($user->is_super_admin) {
            // A super admin operates the platform and never a workspace (SA-4).
            return new Collection;
        }

        $stored = WorkspaceMember::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->with('workspace')
            ->get()
            ->filter(fn (WorkspaceMember $member): bool => (bool) $member->workspace?->is_active);

        /** @var Collection<int, WorkspaceMember> $memberships */
        $memberships = $stored->keyBy('workspace_id');

        foreach ($stored as $member) {
            if (! $member->role->reachesSubsidiaries()) {
                continue;
            }

            foreach ($member->workspace->descendants() as $company) {
                if (! $company->is_active || $memberships->has($company->id)) {
                    continue;
                }

                $memberships->put($company->id, $member->projectInto($company));
            }
        }

        return $memberships;
    }

    /**
     * The membership to run the request with: the one for the workspace asked
     * for, or the most sensible default when that is gone or was never given.
     *
     * Defaults prefer a stored membership over a projected one, so a group
     * director lands in the holding they belong to rather than in whichever
     * subsidiary sorts first.
     */
    public function resolve(User $user, ?int $workspaceId): ?WorkspaceMember
    {
        $memberships = $this->memberships($user);

        if ($workspaceId !== null && $memberships->has($workspaceId)) {
            return $memberships->get($workspaceId);
        }

        return $memberships
            ->sortBy([
                fn (WorkspaceMember $member): int => $member->projected ? 1 : 0,
                fn (WorkspaceMember $member): string => (string) $member->workspace?->name,
            ])
            ->first();
    }

    /**
     * Workspaces the switcher may offer, in the order it lists them: the
     * holding above a company sits before it, and a group's companies follow
     * their parent.
     *
     * @return array<int, array{id: int, name: string, slug: string, logo: string|null, parent_id: int|null, is_group_parent: bool, via_group: bool}>
     */
    public function switchable(User $user): array
    {
        $memberships = $this->memberships($user);

        $rows = $memberships
            ->map(function (WorkspaceMember $member): ?array {
                $workspace = $member->workspace;

                return $workspace === null ? null : [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'logo' => $workspace->logo,
                    'parent_id' => $workspace->parent_id,
                    // Whether this row is itself a holding the user reaches.
                    'is_group_parent' => false,
                    // True when the user only reaches it through the group.
                    'via_group' => $member->projected,
                ];
            })
            ->filter()
            ->values();

        $parentIds = $rows->pluck('parent_id')->filter()->unique()->all();

        return $rows
            ->map(function (array $row) use ($parentIds): array {
                $row['is_group_parent'] = in_array($row['id'], $parentIds, true);

                return $row;
            })
            ->sortBy([
                // Group the companies of a holding under it, then sort by name.
                fn (array $row): int => $row['parent_id'] ?? $row['id'],
                fn (array $row): int => $row['parent_id'] === null ? 0 : 1,
                fn (array $row): string => $row['name'],
            ])
            ->values()
            ->all();
    }

    /**
     * The companies of a group, as the consolidated dashboard reads them:
     * every active workspace under this one.
     *
     * @return Collection<int, Workspace>
     */
    public function subsidiaries(Workspace $holding): Collection
    {
        return $holding->descendants()
            ->filter(fn (Workspace $workspace): bool => $workspace->is_active)
            ->values();
    }
}
