<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Comment access (CMT-1, CMT-2).
 *
 * Anyone who can work on the task may comment; editing and deleting are
 * limited to the author, plus BOD-3 and above for moderation.
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
            || (bool) $this->tenancy->member()?->role->managesProjects();
    }

    protected function inActiveWorkspace(Comment $comment): bool
    {
        return $comment->workspace_id === $this->tenancy->id();
    }
}
