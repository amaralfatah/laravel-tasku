<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_super_admin
 * @property string|null $avatar_path
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Appends(['avatar'])]
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Public URL of the uploaded avatar, exposed to the frontend as `avatar`.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->avatar_path === null
            ? null
            : Storage::disk('public')->url($this->avatar_path),
        );
    }

    /**
     * Workspace memberships, one row per workspace this user belongs to.
     *
     * @return HasMany<WorkspaceMember, $this>
     */
    public function workspaceMembers(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')->withPivot('role');
    }

    /**
     * The membership row for a workspace, or null when the user is not a member.
     */
    public function membershipIn(Workspace $workspace): ?WorkspaceMember
    {
        return WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $this->id)
            ->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_super_admin' => 'boolean',
        ];
    }
}
