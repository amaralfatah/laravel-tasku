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
 * @property int|null $position_id
 * @property int|null $org_unit_id
 * @property ScopeType $scope_type
 * @property int|null $scope_org_unit_id
 * @property int|null $manager_id
 * @property Carbon|null $joined_at
 */
#[Fillable(['user_id', 'role', 'position_id', 'org_unit_id', 'scope_type', 'scope_org_unit_id', 'manager_id', 'joined_at'])]
class WorkspaceMember extends Model
{
    /** @use HasFactory<WorkspaceMemberFactory> */
    use BelongsToWorkspace, HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
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
