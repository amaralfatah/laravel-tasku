<?php

namespace App\Observers;

use App\Actions\Notify;
use App\Models\Task;

class TaskObserver
{
    public function __construct(protected Notify $notify) {}

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
}
