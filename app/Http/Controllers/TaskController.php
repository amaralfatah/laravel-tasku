<?php

namespace App\Http\Controllers;

use App\Http\Requests\Task\TaskStoreRequest;
use App\Http\Requests\Task\TaskUpdateRequest;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskHierarchy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TaskController extends Controller
{
    public function __construct(protected TaskHierarchy $hierarchy) {}

    public function store(TaskStoreRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('contribute', $project);

        $parent = $this->resolveParent($project, $request->validated('parent_task_id'));

        $attributes = $this->hierarchy->syncProgress(
            $request->safe()->except('parent_task_id'),
            new Task(['status' => 'todo', 'progress' => 0]),
        );

        $attributes['created_by'] = $request->user()->id;

        $task = $this->hierarchy->create($project, $attributes, $parent);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Task {$task->wbs_number} dibuat."]);

        return back();
    }

    public function update(TaskUpdateRequest $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $task->update($this->hierarchy->syncProgress($request->validated(), $task));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Task diperbarui.']);

        return back();
    }

    /**
     * Move a task to another parent and/or position (TSK-13, BRD-2, BRD-3).
     */
    public function move(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $validated = $request->validate([
            'parent_task_id' => ['nullable', 'integer'],
            'position' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'string'],
        ]);

        if (isset($validated['status'])) {
            $task->update($this->hierarchy->syncProgress(
                ['status' => $validated['status']],
                $task,
            ));
            $task->refresh();
        }

        $parent = $this->resolveParent($task->project, $validated['parent_task_id'] ?? null);

        if ($parent?->id !== $task->parent_task_id) {
            $this->hierarchy->move($task, $parent, $validated['position'] ?? null);
        } elseif (isset($validated['position'])) {
            $this->hierarchy->reorder($task, $validated['position']);
        }

        return back();
    }

    /**
     * Soft delete the task and everything below it (TSK-3).
     */
    public function destroy(Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);

        $this->hierarchy->delete($task);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Task dihapus beserta sub task-nya.']);

        return back();
    }

    /**
     * Resolve a parent id to a task inside the same project.
     */
    protected function resolveParent(Project $project, ?int $parentId): ?Task
    {
        if ($parentId === null) {
            return null;
        }

        return Task::query()
            ->where('project_id', $project->id)
            ->findOrFail($parentId);
    }
}
