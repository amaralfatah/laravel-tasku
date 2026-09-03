<?php

namespace App\Enums;

enum NotificationType: string
{
    case TaskAssigned = 'task_assigned';
    case Mentioned = 'mentioned';
    case CommentAdded = 'comment_added';
    case DueSoon = 'due_soon';
    case ReviewRequested = 'review_requested';
    case ReviewDecided = 'review_decided';

    public function label(): string
    {
        return match ($this) {
            self::TaskAssigned => 'Ditugaskan',
            self::Mentioned => 'Disebut',
            self::CommentAdded => 'Komentar baru',
            self::DueSoon => 'Jatuh tempo',
            self::ReviewRequested => 'Menunggu review',
            self::ReviewDecided => 'Hasil review',
        };
    }
}
