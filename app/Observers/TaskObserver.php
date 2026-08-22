<?php

namespace App\Observers;

use App\Actions\Notify;
use App\Models\Task;
use App\Services\TaskHierarchy;

class TaskObserver
{
    public function __construct(
        protected Notify $notify,
        protected TaskHierarchy $hierarchy,
    ) {}

    public function created(Task $task): void
    {
        if ($task->assignee_id !== null) {
            $this->notify->taskAssigned($task, $task->assignee_id);
        }
    }

    /**
     * Only a change of assignee fires; editing other fields does not.
     */
    public function updated(Task $task): void
    {
        if (! $task->wasChanged('assignee_id') || $task->assignee_id === null) {
            return;
        }

        $this->notify->taskAssigned($task, $task->assignee_id);
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
