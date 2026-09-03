<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property WorkspaceRole $role
 * @property string|null $title formal position, e.g. Kepala Divisi
 * @property int|null $org_unit_id
 * @property int|null $manager_id
 * @property Carbon|null $joined_at
 */
#[Fillable(['user_id', 'role', 'title', 'org_unit_id', 'manager_id', 'joined_at'])]
class WorkspaceMember extends Model
{
    /** @use HasFactory<WorkspaceMemberFactory> */
    use BelongsToWorkspace, HasFactory;

    /**
     * Materialized path of the member's own unit, resolved once per instance.
     */
    protected ?string $resolvedScopePath = null;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<OrgUnit, $this> */
    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    /**
     * Whether this member leads a team at all — the gate on the organisation,
     * membership and monitoring pages.
     */
    public function managesTeam(): bool
    {
        return $this->role->managesTeam();
    }

    /**
     * The formal position this person holds, as the customer wrote it. Falls
     * back to the tier's own name so a row is never nameless.
     */
    public function positionTitle(): string
    {
        return $this->title ?: $this->role->defaultTitle();
    }

    /**
     * Whether this member may change anything at all. A Viewer never may.
     */
    public function canWrite(): bool
    {
        return $this->role->canWrite();
    }

    /**
     * An Owner runs the entity, so its scope is the workspace itself rather
     * than one branch of the tree.
     */
    public function hasFullScope(): bool
    {
        return $this->role->isTop();
    }

    /**
     * Whether this member reads the whole workspace: the Owner, and a Viewer
     * nobody pinned to a branch — the commissioner or auditor who watches the
     * entity end to end.
     */
    public function readsEverything(): bool
    {
        return $this->hasFullScope()
            || ($this->role === WorkspaceRole::Viewer && $this->org_unit_id === null);
    }

    /**
     * Root of the subtree this member may read, as a materialized path. Null
     * when they read everything, or nothing beyond their own work.
     */
    public function readScopePath(): ?string
    {
        if ($this->readsEverything()) {
            return null;
        }

        return $this->managesTeam() || $this->role === WorkspaceRole::Viewer
            ? $this->scopePath()
            : null;
    }

    /**
     * Whether the reporting pages are open to this member: everyone who leads
     * a slice of the tree, plus a Viewer, whose whole purpose is to read them.
     */
    public function canObserve(): bool
    {
        return $this->leadsAnyone()
            || ($this->role === WorkspaceRole::Viewer && ($this->readsEverything() || $this->scopePath() !== null));
    }

    /**
     * Whether this member reaches past their own row: everything below hangs
     * off the unit they are placed in, so a leader without a unit leads nobody.
     */
    public function leadsAnyone(): bool
    {
        return $this->hasFullScope()
            || ($this->managesTeam() && $this->scopePath() !== null);
    }

    /**
     * Root of the subtree this member covers, as a materialized path.
     */
    public function scopePath(): ?string
    {
        if ($this->resolvedScopePath === null && $this->org_unit_id !== null) {
            // A loaded relation may have been fetched with a column subset that
            // leaves `path` out, so read it only when it is actually there and
            // fall back to the query otherwise. Treating a missing column as
            // "no scope" silently demotes a leader to a plain member.
            $loaded = $this->relationLoaded('orgUnit') ? $this->orgUnit : null;

            $this->resolvedScopePath = $loaded->path
                ?? OrgUnit::query()->whereKey($this->org_unit_id)->value('path');
        }

        return $this->resolvedScopePath;
    }

    /**
     * Whether an org unit falls inside this member's scope.
     *
     * A null unit is workspace level: only an Owner covers it, which is what
     * keeps a fresh workspace — where nobody has a unit yet — manageable.
     */
    public function covers(?int $orgUnitId): bool
    {
        if (! $this->managesTeam()) {
            return false;
        }

        // Full scope means the workspace, not the platform: the org tree is
        // master data shared by every company, so even an Owner only reaches
        // the subtree their workspace was placed on.
        if ($this->hasFullScope()) {
            return $orgUnitId === null || $this->unitPath($orgUnitId) !== null;
        }

        if ($orgUnitId === null) {
            return false;
        }

        $scopePath = $this->scopePath();
        $unitPath = $scopePath === null ? null : $this->unitPath($orgUnitId);

        return $unitPath !== null && str_starts_with($unitPath, $scopePath);
    }

    /**
     * Path of a unit, looked up inside this member's workspace so a unit from
     * another company's branch never resolves.
     */
    protected function unitPath(int $orgUnitId): ?string
    {
        return $this->workspace->orgUnits()->whereKey($orgUnitId)->value('path');
    }

    /**
     * Whether this member may act on another one: their own row is always
     * theirs, everyone else has to sit inside the scope.
     */
    public function coversMember(self $target): bool
    {
        return $this->user_id === $target->user_id || $this->covers($target->org_unit_id);
    }

    /**
     * Whether an org unit falls inside what this member may read. Wider than
     * {@see covers()}, which answers the same question for writing.
     */
    public function readsUnit(?int $orgUnitId): bool
    {
        if ($this->readsEverything()) {
            return $orgUnitId === null || $this->unitPath($orgUnitId) !== null;
        }

        $scopePath = $this->readScopePath();

        if ($scopePath === null || $orgUnitId === null) {
            return false;
        }

        $unitPath = $this->unitPath($orgUnitId);

        return $unitPath !== null && str_starts_with($unitPath, $scopePath);
    }

    /**
     * Whether this member may read another one's workload: their own row
     * always, everyone else inside the read scope.
     */
    public function readsMember(self $target): bool
    {
        return $this->user_id === $target->user_id || $this->readsUnit($target->org_unit_id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'joined_at' => 'datetime',
        ];
    }
}
