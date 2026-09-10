<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Review = 'review';
    case OnHold = 'on_hold';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To Do',
            self::InProgress => 'In Progress',
            self::Review => 'In Review',
            self::OnHold => 'On Hold',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Category representing the higher-level lifecycle stage.
     */
    public function category(): StatusCategory
    {
        return match ($this) {
            self::Todo => StatusCategory::Todo,
            self::InProgress, self::Review, self::OnHold => StatusCategory::InProgress,
            self::Done, self::Cancelled => StatusCategory::Done,
        };
    }

    /**
     * Whether this status belongs to the Done category.
     */
    public function isDone(): bool
    {
        return $this->category() === StatusCategory::Done;
    }

    /**
     * Whether this status belongs to the Todo category.
     */
    public function isTodo(): bool
    {
        return $this->category() === StatusCategory::Todo;
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
            self::InProgress, self::OnHold, self::Cancelled => null,
        };
    }

    /**
     * Whether the work itself is finished, whether or not anyone has accepted
     * it yet. Used where 100% must not be forced back to Done.
     */
    public function isFinishedWork(): bool
    {
        return $this === self::Done || $this === self::Review || $this === self::Cancelled;
    }
}
