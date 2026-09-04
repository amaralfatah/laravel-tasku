<?php

namespace App\Http\Controllers;

use App\Actions\Notify;
use App\Enums\TaskStatus;
use App\Http\Requests\Task\TaskStoreRequest;
use App\Http\Requests\Task\TaskUpdateRequest;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskHierarchy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        Inertia::flash('toast', ['type' => 'success', 'message' => "Task {$task->reference} dibuat."]);

        return back();
    }

    public function update(TaskUpdateRequest $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $attributes = $this->hierarchy->syncProgress($request->validated(), $task);

        $task->fill($attributes);
        // The review trail is stamped by the system, so it is not fillable.
        $task->forceFill($this->reviewTrail($attributes, $task));
        $task->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Task diperbarui.']);

        return back();
    }

    /**
     * Accept the work on a task, or send it back for more (TSK-18).
     *
     * Approving finishes the task; returning it puts it back in progress with
     * the reason recorded as a comment, so the worker reads why rather than
     * finding the card moved and guessing.
     *
     * The route is offered, never imposed: anyone who may edit a task may also
     * mark it Done outright. Handing work up is for teams that want a second
     * pair of eyes, not a toll gate every task has to pass.
     */
    public function review(Request $request, Task $task, Notify $notify): RedirectResponse
    {
        $this->authorize('review', $task);

        abort_unless($task->awaitsReview(), 422, 'Task ini tidak sedang menunggu review.');

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approve,return'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $approved = $validated['decision'] === 'approve';

        $task->forceFill([
            'status' => $approved ? TaskStatus::Done : TaskStatus::InProgress,
            // Returned work is not finished work, so its percentage steps back
            // off 100 rather than sitting there contradicting the status.
            'progress' => $approved ? 100 : TaskHierarchy::UNFINISHED_PROGRESS,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ])->save();

        if (($validated['note'] ?? '') !== '') {
            $comment = new Comment(['body' => $validated['note']]);
            $comment->task_id = $task->id;
            $comment->user_id = $request->user()->id;
            $comment->workspace_id = $task->workspace_id;
            $comment->save();

            $notify->commentAdded($comment);
        }

        $notify->reviewDecided($task->refresh(), $approved);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $approved ? 'Task disetujui dan ditandai selesai.' : 'Task dikembalikan untuk diperbaiki.',
        ]);

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
            // The column a card was dropped in. Checked against the enum here
            // because `syncProgress()` reads it with `TaskStatus::from()`,
            // which answers anything else with a 500 rather than a 422.
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
        ]);

        if (isset($validated['status'])) {
            $attributes = $this->hierarchy->syncProgress(
                ['status' => $validated['status']],
                $task,
            );

            $task->fill($attributes);
            $task->forceFill($this->reviewTrail($attributes, $task));
            $task->save();
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
     * The review trail a status change implies, empty when it implies none.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function reviewTrail(array $attributes, Task $task): array
    {
        $status = isset($attributes['status']) ? TaskStatus::from((string) $attributes['status']) : null;

        if ($status !== TaskStatus::Review || $task->getOriginal('status') === TaskStatus::Review->value) {
            return [];
        }

        return [
            'submitted_at' => now(),
            // A fresh submission is undecided again, so last time's verdict
            // must not linger next to it.
            'reviewed_at' => null,
            'reviewed_by' => null,
        ];
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
