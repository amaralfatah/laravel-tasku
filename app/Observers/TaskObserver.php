<?php

namespace App\Observers;

use App\Actions\Notify;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\TaskHierarchy;

class TaskObserver
{
    public function __construct(
        protected Notify $notify,
        protected TaskHierarchy $hierarchy,
    ) {}

    /**
     * Stamp when a task was finished, so the board can order the done column
     * newest first. Kept here rather than in the controller: a rollup from a
     * sub task closes a parent without any request touching it.
     */
    public function saving(Task $task): void
    {
        if (! $task->isDirty('status')) {
            return;
        }

        $task->completed_at = $task->status === TaskStatus::Done ? now() : null;
    }

    public function created(Task $task): void
    {
        if ($task->assignee_id !== null) {
            $this->notify->taskAssigned($task, $task->assignee_id);
        }
    }

    /**
     * A change of assignee tells the new one; work handed up for review tells
     * whoever has to decide on it. Editing other fields notifies nobody.
     */
    public function updated(Task $task): void
    {
        if ($task->wasChanged('assignee_id') && $task->assignee_id !== null) {
            $this->notify->taskAssigned($task, $task->assignee_id);
        }

        if ($task->wasChanged('status') && $task->status === TaskStatus::Review) {
            $this->notify->reviewRequested($task);
        }
    }

    /**
     * Every save re-derives the parent's progress, so a sub task turning done
     * is what moves the task above it (TSK-17).
     */
    public function saved(Task $task): void
    {
        // The task itself first: one that has sub tasks may have just been
        // handed a status by hand, and its sub tasks outrank that.
        $this->hierarchy->syncParentProgress($task);
        $this->hierarchy->syncParentProgress($task->parent);
    }

    public function deleted(Task $task): void
    {
        $this->hierarchy->syncParentProgress($task->parent);
    }
}
