<?php

namespace App\Models;

use App\Models\Scopes\WorkspaceOrgUnitScope;
use Database\Factories\OrgUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $external_id SAP object id when the unit came from the CDS import
 * @property int|null $parent_id
 * @property string $name
 * @property string $type
 * @property string $path materialized path, e.g. /1/5/12/
 * @property int $depth
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['parent_id', 'name', 'type'])]
class OrgUnit extends Model
{
    /** @use HasFactory<OrgUnitFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new WorkspaceOrgUnitScope);
    }

    /**
     * Root unit is depth 0, so this allows depth 0 through 11.
     *
     * The imported SAP structure reaches depth 10 once the holding is dropped
     * and the operating companies become the roots; the extra level is the
     * headroom a leader needs to add a team under the deepest imported unit.
     */
    public const MAX_DEPTH = 11;

    /** @return BelongsTo<OrgUnit, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'parent_id');
    }

    /** @return HasMany<OrgUnit, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(OrgUnit::class, 'parent_id');
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<WorkspaceMember, $this> */
    public function assignedMembers(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /**
     * The unit itself plus every descendant.
     *
     * `path` includes the unit's own id, so `/1/5/` matches both `/1/5/` and
     * every deeper path below it.
     *
     * @param  Builder<OrgUnit>  $query
     */
    public function scopeInSubtree(Builder $query, OrgUnit $root): void
    {
        $query->where('path', 'like', $root->path.'%');
    }

    /**
     * Ids of this unit and all of its descendants.
     *
     * @return array<int, int>
     */
    public function subtreeIds(): array
    {
        return static::query()->inSubtree($this)->pluck('id')->all();
    }
}
