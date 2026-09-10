<?php

namespace App\Enums;

enum StatusCategory: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To Do',
            self::InProgress => 'In Progress',
            self::Done => 'Done',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Todo => 'gray',
            self::InProgress => 'blue',
            self::Done => 'green',
        };
    }

    /**
     * Get all task statuses belonging to this category.
     *
     * @return array<int, TaskStatus>
     */
    public function statuses(): array
    {
        return array_values(array_filter(
            TaskStatus::cases(),
            fn (TaskStatus $status): bool => $status->category() === $this,
        ));
    }

    /**
     * Get all status string values belonging to this category.
     *
     * @return array<int, string>
     */
    public function statusValues(): array
    {
        return array_map(
            fn (TaskStatus $status): string => $status->value,
            $this->statuses(),
        );
    }
}
