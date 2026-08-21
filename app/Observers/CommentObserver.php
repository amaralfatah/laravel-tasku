<?php

namespace App\Observers;

use App\Actions\Notify;
use App\Models\Comment;

class CommentObserver
{
    public function __construct(protected Notify $notify) {}

    public function created(Comment $comment): void
    {
        $this->notify->commentAdded($comment);
    }
}
