<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Enums\WorkspaceRole;
use Carbon\CarbonInterface;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $email
 * @property WorkspaceRole $role
 * @property string $token
 * @property int|null $invited_by
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 */
#[Fillable(['email', 'role', 'token', 'invited_by', 'expires_at', 'accepted_at'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use BelongsToWorkspace, HasFactory;

    public const VALID_DAYS = 7;

    protected static function booted(): void
    {
        static::creating(function (Invitation $invitation): void {
            $invitation->token ??= Str::random(48);
            $invitation->expires_at ??= now()->addDays(self::VALID_DAYS);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
