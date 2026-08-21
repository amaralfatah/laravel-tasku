<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkspaceInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invitation $invitation,
        public Workspace $workspace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Undangan bergabung ke {$this->workspace->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.workspace-invitation',
            with: [
                'acceptUrl' => route('invitation.show', $this->invitation->token),
                'roleLabel' => $this->invitation->role->label(),
                'expiresAt' => $this->invitation->expires_at->timezone('Asia/Jakarta')->format('d M Y H:i'),
            ],
        );
    }
}
