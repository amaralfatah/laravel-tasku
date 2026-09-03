<?php

namespace App\Actions;

use App\Enums\NotificationType;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Support\MentionParser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writes in-app notifications (6.14).
 *
 * Notifications are deliberately not tenant-scoped through the global scope:
 * they are looked up by recipient, and each row records the workspace it came
 * from so the bell can link back to the right place.
 */
class Notify
{
    /**
     * Someone was assigned to a task. The actor never notifies themselves.
     */
    public function taskAssigned(Task $task, int $assigneeId): void
    {
        $actorId = Auth::id() === null ? null : (int) Auth::id();

        if ($assigneeId === $actorId) {
            return;
        }

        $this->write(
            userId: $assigneeId,
            workspaceId: $task->workspace_id,
            type: NotificationType::TaskAssigned,
            entityType: 'task',
            entityId: $task->id,
            actorId: $actorId,
            message: Str::limit($task->title, 80).' ditugaskan kepada Anda',
        );
    }

    /**
     * A new comment: the task's assignee is told, and every mentioned user.
     */
    public function commentAdded(Comment $comment): void
    {
        $task = $comment->task;
        $actorId = $comment->user_id;

        $mentionedIds = MentionParser::ids($comment->body);

        foreach ($mentionedIds as $userId) {
            if ($userId === $actorId) {
                continue;
            }

            $this->write(
                userId: $userId,
                workspaceId: $comment->workspace_id,
                type: NotificationType::Mentioned,
                entityType: 'task',
                entityId: $task->id,
                actorId: $actorId,
                message: 'Anda disebut di '.Str::limit($task->title, 60),
            );
        }

        $assigneeId = $task->assignee_id;

        if ($assigneeId === null || $assigneeId === $actorId || in_array($assigneeId, $mentionedIds, true)) {
            return;
        }

        $this->write(
            userId: $assigneeId,
            workspaceId: $comment->workspace_id,
            type: NotificationType::CommentAdded,
            entityType: 'task',
            entityId: $task->id,
            actorId: $actorId,
            message: 'Komentar baru di '.Str::limit($task->title, 60),
        );
    }

    /**
     * Work has been handed up: whoever owns the task above it is told, and the
     * project's own leader when there is no task above.
     */
    public function reviewRequested(Task $task): void
    {
        $actorId = Auth::id() === null ? null : (int) Auth::id();
        $reviewerId = $task->parent?->assignee_id;

        if ($reviewerId === null || $reviewerId === $actorId) {
            return;
        }

        $this->write(
            userId: $reviewerId,
            workspaceId: $task->workspace_id,
            type: NotificationType::ReviewRequested,
            entityType: 'task',
            entityId: $task->id,
            actorId: $actorId,
            message: Str::limit($task->title, 60).' menunggu review Anda',
        );
    }

    /**
     * A decision was made: the person who did the work is told either way.
     */
    public function reviewDecided(Task $task, bool $approved): void
    {
        $actorId = Auth::id() === null ? null : (int) Auth::id();

        if ($task->assignee_id === null || $task->assignee_id === $actorId) {
            return;
        }

        $this->write(
            userId: $task->assignee_id,
            workspaceId: $task->workspace_id,
            type: NotificationType::ReviewDecided,
            entityType: 'task',
            entityId: $task->id,
            actorId: $actorId,
            message: $approved
                ? Str::limit($task->title, 60).' disetujui'
                : Str::limit($task->title, 60).' dikembalikan untuk diperbaiki',
        );
    }

    /**
     * Daily due reminder, at most once per task per day.
     */
    public function dueSoon(Task $task): bool
    {
        if ($task->assignee_id === null) {
            return false;
        }

        $alreadySentToday = Notification::query()
            ->where('type', NotificationType::DueSoon)
            ->where('entity_id', $task->id)
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        if ($alreadySentToday) {
            return false;
        }

        $this->write(
            userId: $task->assignee_id,
            workspaceId: $task->workspace_id,
            type: NotificationType::DueSoon,
            entityType: 'task',
            entityId: $task->id,
            actorId: null,
            message: $task->isOverdue()
                ? Str::limit($task->title, 60).' sudah terlewat tenggatnya'
                : Str::limit($task->title, 60).' jatuh tempo hari ini',
        );

        return true;
    }

    protected function write(
        int $userId,
        int $workspaceId,
        NotificationType $type,
        string $entityType,
        int $entityId,
        ?int $actorId,
        string $message,
    ): void {
        if (! User::whereKey($userId)->exists()) {
            return;
        }

        Notification::create([
            'user_id' => $userId,
            'workspace_id' => $workspaceId,
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_id' => $actorId,
            'message' => $message,
            'is_read' => false,
        ]);
    }
}
