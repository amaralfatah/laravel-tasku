<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Enums\ProjectStatus;
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
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $org_unit_id
 * @property string $name
 * @property string $key
 * @property string|null $description
 * @property ProjectStatus $status
 * @property int|null $created_by
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_unit_id', 'name', 'key', 'description', 'status'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    /**
     * Longest key a project may carry, mirrored by the column and the rules.
     */
    public const KEY_MAX_LENGTH = 10;

    /**
     * A free key for a project of this name, the way Jira prefills one.
     *
     * The base comes from the name's initials; a number is appended until the
     * key is free inside the workspace. Soft deleted projects still hold their
     * key, because the unique index does.
     */
    public static function generateKey(string $name): string
    {
        $base = static::keyBase($name);
        $key = $base;
        $suffix = 1;

        while (static::query()->withTrashed()->where('key', $key)->exists()) {
            $suffix++;
            $digits = (string) $suffix;
            $key = substr($base, 0, self::KEY_MAX_LENGTH - strlen($digits)).$digits;
        }

        return $key;
    }

    /**
     * Initials for a multi word name, the first three letters otherwise.
     */
    protected static function keyBase(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', Str::ascii($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn (string $word): bool => ctype_alpha($word[0])));

        if ($words === []) {
            return 'PRJ';
        }

        $base = count($words) > 1
            ? implode('', array_map(fn (string $word): string => $word[0], $words))
            : substr($words[0], 0, 3);

        return str_pad(strtoupper(substr($base, 0, self::KEY_MAX_LENGTH)), 2, 'X');
    }

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
     * An Owner sees the whole workspace, and so does a Viewer nobody pinned to
     * a branch. A leader or a branch Viewer sees every project hanging off
     * their own org unit and its descendants. Everyone else sees the projects
     * they are a member of.
     *
     * @param  Builder<Project>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $member = app(Tenancy::class)->member();

        if ($member?->readsEverything()) {
            return;
        }

        $scopePath = $member?->readScopePath();

        $query->where(function (Builder $query) use ($user, $scopePath): void {
            $query->whereHas('members', fn (Builder $members) => $members->whereKey($user->id));

            if ($scopePath !== null) {
                $query->orWhereHas(
                    'orgUnit',
                    fn (Builder $unit) => $unit->where('path', 'like', $scopePath.'%'),
                );
            }
        });
    }

    /**
     * Whether the user runs this project.
     *
     * Two ways in: a leader whose scope covers the project's unit, or the
     * person who created it. The second is what makes a project someone
     * started themselves theirs to run, the way a team-managed project in
     * Jira belongs to whoever opened it.
     */
    public function isAdministeredBy(User $user): bool
    {
        $member = app(Tenancy::class)->member();

        if ($member === null || $member->workspace_id !== $this->workspace_id || ! $member->canWrite()) {
            return false;
        }

        return $member->covers($this->org_unit_id) || $this->created_by === $user->id;
    }

    /**
     * Whether the user may change this project's tasks.
     *
     * Whoever runs the project always may; anyone else has to be a project
     * member.
     */
    public function isEditableBy(User $user): bool
    {
        $member = app(Tenancy::class)->member();

        if ($member === null || $member->workspace_id !== $this->workspace_id || ! $member->canWrite()) {
            return false;
        }

        if ($this->isAdministeredBy($user)) {
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
