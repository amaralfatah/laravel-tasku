<?php

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id recipient
 * @property int $workspace_id
 * @property NotificationType $type
 * @property string $entity_type
 * @property int $entity_id
 * @property int|null $actor_id
 * @property string $message
 * @property bool $is_read
 * @property Carbon|null $created_at
 */
#[Table('app_notifications')]
#[Fillable(['user_id', 'workspace_id', 'type', 'entity_type', 'entity_id', 'actor_id', 'message', 'is_read'])]
class Notification extends Model
{
    /**
     * Notifications are written once and never updated except for `is_read`,
     * so a single timestamp is enough.
     */
    public const UPDATED_AT = null;

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'is_read' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
