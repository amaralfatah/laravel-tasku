<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To Do',
            self::InProgress => 'Dikerjakan',
            self::Review => 'Menunggu review',
            self::Done => 'Selesai',
        };
    }

    /**
     * Progress forced by a status change, or null when the user decides (TSK-15).
     *
     * Handing work up for review forces 100: the doing is finished, and what
     * is left is somebody else's decision, not more of the work.
     */
    public function forcedProgress(): ?int
    {
        return match ($this) {
            self::Done, self::Review => 100,
            self::Todo => 0,
            self::InProgress => null,
        };
    }

    /**
     * Whether the work itself is finished, whether or not anyone has accepted
     * it yet. Used where 100% must not be forced back to Done.
     */
    public function isFinishedWork(): bool
    {
        return $this === self::Done || $this === self::Review;
    }
}
