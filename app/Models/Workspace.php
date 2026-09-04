<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $parent_id the holding this workspace operates under
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property-read string|null $logo public URL derived from logo_path
 * @property int|null $root_org_unit_id node of the platform org tree this workspace runs
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OrgUnit|null $rootOrgUnit null until an operator or a self
 *   serve owner places the workspace in the tree
 */
#[Appends(['logo'])]
#[Fillable(['parent_id', 'name', 'slug', 'root_org_unit_id', 'is_active'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * Public URL of the uploaded logo, exposed to the frontend as `logo`.
     *
     * Not fillable: the file is written by
     * `App\Http\Controllers\Settings\WorkspaceController`, which also deletes
     * the one it replaces.
     *
     * @return Attribute<string|null, never>
     */
    protected function logo(): Attribute
    {
        return new Attribute(
            get: fn (): ?string => $this->logo_path === null
                ? null
                : Storage::disk('public')->url($this->logo_path),
        );
    }

    /**
     * Materialized path of the root unit, resolved once per instance.
     */
    protected ?string $resolvedRootPath = null;

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace): void {
            $workspace->slug ??= static::uniqueSlug($workspace->name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $suffix = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The node of the platform-wide org tree this workspace runs. Everything
     * inside its subtree belongs to the workspace; everything outside does not.
     *
     * @return BelongsTo<OrgUnit, $this>
     */
    public function rootOrgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'root_org_unit_id');
    }

    /**
     * Materialized path of that node, resolved once per instance. Null while
     * no operator has placed the workspace in the tree yet.
     */
    public function orgUnitRootPath(): ?string
    {
        if ($this->resolvedRootPath === null && $this->root_org_unit_id !== null) {
            $this->resolvedRootPath = $this->relationLoaded('rootOrgUnit')
                ? $this->rootOrgUnit?->path
                : OrgUnit::withoutGlobalScopes()->whereKey($this->root_org_unit_id)->value('path');
        }

        return $this->resolvedRootPath;
    }

    /**
     * Every unit of the workspace: its root and everything below it.
     *
     * @return Builder<OrgUnit>
     */
    public function orgUnits(): Builder
    {
        $path = $this->orgUnitRootPath();

        return OrgUnit::withoutGlobalScopes()
            ->when($path === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($path !== null, fn (Builder $query) => $query->where('path', 'like', $path.'%'));
    }

    /**
     * How deep a group of companies may nest. A holding of holdings is real,
     * but a cycle in the data must not be able to hang a page.
     */
    public const MAX_GROUP_DEPTH = 5;

    /**
     * The holding this workspace operates under, if any.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'parent_id');
    }

    /**
     * The operating companies directly under this one.
     *
     * @return HasMany<Workspace, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Workspace::class, 'parent_id')->orderBy('name');
    }

    /**
     * Whether this workspace runs other companies, which is the only thing
     * that makes it a holding — there is no flag to set.
     */
    public function isHolding(): bool
    {
        return $this->relationLoaded('children')
            ? $this->children->isNotEmpty()
            : $this->children()->exists();
    }

    /**
     * Every company under this one, breadth first and depth capped.
     *
     * @return Collection<int, Workspace>
     */
    public function descendants(): Collection
    {
        /** @var Collection<int, Workspace> $found */
        $found = new Collection;
        $frontier = [$this->id];

        for ($depth = 0; $depth < self::MAX_GROUP_DEPTH && $frontier !== []; $depth++) {
            $children = static::query()
                ->whereIn('parent_id', $frontier)
                ->whereNotIn('id', $found->pluck('id')->push($this->id)->all())
                ->orderBy('name')
                ->get();

            if ($children->isEmpty()) {
                break;
            }

            $found = $found->concat($children);
            $frontier = $children->pluck('id')->all();
        }

        return $found;
    }

    /** @return HasMany<WorkspaceMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')->withPivot('role');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
