<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Comment access (CMT-1, CMT-2).
 *
 * Anyone who can work on the task may comment; editing is limited to the
 * author, and deleting to the author plus whoever runs the project.
 */
class CommentPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function update(User $user, Comment $comment): bool
    {
        return $this->inActiveWorkspace($comment) && $comment->user_id === $user->id;
    }

    public function delete(User $user, Comment $comment): bool
    {
        if (! $this->inActiveWorkspace($comment)) {
            return false;
        }

        return $comment->user_id === $user->id
            || $comment->task->project->isAdministeredBy($user);
    }

    protected function inActiveWorkspace(Comment $comment): bool
    {
        return $comment->workspace_id === $this->tenancy->id();
    }
}
