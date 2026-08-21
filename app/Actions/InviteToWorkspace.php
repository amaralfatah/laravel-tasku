<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Creates (or refreshes) a workspace invitation and emails the link.
 *
 * Re-inviting the same address reuses the pending row with a fresh token and
 * expiry, so an address never accumulates several live invitations.
 */
class InviteToWorkspace
{
    public function __construct(protected Tenancy $tenancy) {}

    public function handle(
        Workspace $workspace,
        string $email,
        WorkspaceRole $role,
        ?User $inviter = null,
    ): Invitation {
        $invitation = $this->tenancy->forWorkspace($workspace, function () use ($workspace, $email, $role, $inviter): Invitation {
            $existing = Invitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->first();

            $attributes = [
                'email' => $email,
                'role' => $role,
                'token' => Str::random(48),
                'invited_by' => $inviter?->id,
                'expires_at' => now()->addDays(Invitation::VALID_DAYS),
            ];

            if ($existing !== null) {
                $existing->update($attributes);

                return $existing;
            }

            return Invitation::create([...$attributes, 'workspace_id' => $workspace->id]);
        });

        Mail::to($email)->send(new WorkspaceInvitationMail($invitation->fresh(), $workspace));

        return $invitation;
    }
}
