<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Enums\ProjectStatus;
use App\Enums\ScopeType;
use App\Support\Tenancy;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $org_unit_id
 * @property string $name
 * @property string|null $description
 * @property ProjectStatus $status
 * @property int|null $created_by
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_unit_id', 'name', 'description', 'status'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    /** @return BelongsTo<OrgUnit, $this> */
    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withTimestamps();
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Projects the user may see in the active workspace (7.2).
     *
     * Owner and Admin see everything. A member sees the projects they belong
     * to, plus — when granted a subtree scope — every project hanging off that
     * org unit and its descendants. Subtree access is read-only; editing still
     * requires project membership.
     *
     * @param  Builder<Project>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $member = app(Tenancy::class)->member();

        if ($member?->role->isManager()) {
            return;
        }

        $query->where(function (Builder $query) use ($user, $member): void {
            $query->whereHas('members', fn (Builder $members) => $members->whereKey($user->id));

            if ($member?->scope_type === ScopeType::UnitSubtree && $member->scope_org_unit_id !== null) {
                $scopePath = OrgUnit::query()->whereKey($member->scope_org_unit_id)->value('path');

                if ($scopePath !== null) {
                    $query->orWhereHas(
                        'orgUnit',
                        fn (Builder $unit) => $unit->where('path', 'like', $scopePath.'%'),
                    );
                }
            }
        });
    }

    /**
     * Whether the user may change this project's tasks (subtree scope is read-only).
     */
    public function isEditableBy(User $user): bool
    {
        $member = app(Tenancy::class)->member();

        if ($member === null || $member->workspace_id !== $this->workspace_id) {
            return false;
        }

        if ($member->role->isManager()) {
            return true;
        }

        // Read the loaded relation when there is one; the monitoring pages ask
        // this for every project on the page.
        return $this->relationLoaded('members')
            ? $this->members->contains('id', $user->id)
            : $this->members()->whereKey($user->id)->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
        ];
    }
}
