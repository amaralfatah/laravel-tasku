<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Enums\ScopeType;
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
 * @property int|null $org_unit_id
 * @property ScopeType $scope_type
 * @property int|null $scope_org_unit_id
 * @property int|null $manager_id
 * @property Carbon|null $joined_at
 */
#[Fillable(['user_id', 'role', 'org_unit_id', 'scope_type', 'scope_org_unit_id', 'manager_id', 'joined_at'])]
class WorkspaceMember extends Model
{
    /** @use HasFactory<WorkspaceMemberFactory> */
    use BelongsToWorkspace, HasFactory;

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

    /** @return BelongsTo<OrgUnit, $this> */
    public function scopeOrgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'scope_org_unit_id');
    }

    /**
     * Whether this member monitors an org unit subtree beyond their own projects.
     */
    public function monitorsSubtree(): bool
    {
        return $this->scope_type === ScopeType::UnitSubtree && $this->scope_org_unit_id !== null;
    }

    /**
     * Whether an org unit sits inside this member's monitoring subtree.
     */
    public function scopeCoversUnit(?int $orgUnitId): bool
    {
        if (! $this->monitorsSubtree() || $orgUnitId === null) {
            return false;
        }

        $scopePath = OrgUnit::query()->whereKey($this->scope_org_unit_id)->value('path');
        $unitPath = OrgUnit::query()->whereKey($orgUnitId)->value('path');

        return $scopePath !== null
            && $unitPath !== null
            && str_starts_with($unitPath, $scopePath);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'scope_type' => ScopeType::class,
            'joined_at' => 'datetime',
        ];
    }
}
