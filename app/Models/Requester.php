<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use Database\Factories\RequesterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Whoever asked for the work, kept as a list a leader maintains.
 *
 * This is not `tasks.created_by`: that is the person who filed the task, which
 * the system stamps by itself. A requester is the person the work is for, and
 * is often not a user of the application at all — a client, a stakeholder, a
 * head of another division — which is why a plain user picker cannot hold it.
 *
 * The list is chosen from, never typed into: anyone filing a task picks an
 * existing row, and only a leader adds one. Free text would put "Budi",
 * "budi", "Pak Budi" and "Budi Santosa" in the same column within a month and
 * make every report that groups by requester wrong.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $name_normalized
 * @property string|null $organization
 * @property string|null $email
 * @property bool $is_active
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'organization', 'email', 'is_active'])]
class Requester extends Model
{
    /** @use HasFactory<RequesterFactory> */
    use BelongsToWorkspace, HasFactory;

    /**
     * Keeps the comparison column in step with the name it is derived from,
     * so the unique index does its job whichever route wrote the row.
     */
    protected static function booted(): void
    {
        static::saving(function (Requester $requester): void {
            $requester->name_normalized = static::normalize($requester->name);
        });
    }

    /**
     * The form two names are compared by: case folded, with runs of
     * whitespace collapsed to one space.
     */
    public static function normalize(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    /**
     * Whether the active workspace already lists this person, comparing the
     * way the unique index does. `$ignoreId` is the row being renamed, which
     * is allowed to collide with itself.
     *
     * An inactive requester still counts: they hold the name, and adding a
     * second row for them is exactly the duplicate the list exists to avoid.
     */
    public static function isListed(string $name, ?int $ignoreId = null): bool
    {
        return static::query()
            ->where('name_normalized', static::normalize($name))
            ->when($ignoreId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * The rows a task form may offer. A retired requester stays on the tasks
     * that already name them but is no longer handed out.
     *
     * @param  Builder<Requester>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
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
