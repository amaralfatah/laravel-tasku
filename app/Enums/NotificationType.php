<?php

namespace App\Enums;

enum NotificationType: string
{
    case TaskAssigned = 'task_assigned';
    case Mentioned = 'mentioned';
    case CommentAdded = 'comment_added';
    case DueSoon = 'due_soon';

    public function label(): string
    {
        return match ($this) {
            self::TaskAssigned => 'Ditugaskan',
            self::Mentioned => 'Disebut',
            self::CommentAdded => 'Komentar baru',
            self::DueSoon => 'Jatuh tempo',
        };
    }
}
