<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To Do',
            self::InProgress => 'Dikerjakan',
            self::Done => 'Selesai',
        };
    }

    /**
     * Progress forced by a status change, or null when the user decides (TSK-15).
     */
    public function forcedProgress(): ?int
    {
        return match ($this) {
            self::Done => 100,
            self::Todo => 0,
            self::InProgress => null,
        };
    }
}
