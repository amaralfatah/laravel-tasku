<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Task;
use App\Support\MentionParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Task comments with mentions (6.13).
 */
class CommentController extends Controller
{
    /**
     * Thread for a task, oldest first (CMT-5).
     *
     * Returned as JSON because the detail sheet loads it on demand rather than
     * shipping every thread with the board.
     */
    public function index(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $allowed = $this->mentionableIds($task);

        $comments = Comment::query()
            ->where('task_id', $task->id)
            ->with('author:id,name,avatar_path')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'mentions' => MentionParser::names($comment->body, $allowed),
                'author' => [
                    'id' => $comment->author->id,
                    'name' => $comment->author->name,
                    'avatar' => $comment->author->avatar,
                ],
                'created_at' => $comment->created_at->toIso8601String(),
                'edited' => $comment->updated_at->gt($comment->created_at),
                'can_edit' => $request->user()->can('update', $comment),
                'can_delete' => $request->user()->can('delete', $comment),
            ])
            ->all();

        return response()->json([
            'comments' => $comments,
            'mentionables' => $this->mentionables($task),
        ]);
    }

    public function store(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = new Comment([
            'body' => MentionParser::sanitize($validated['body'], $this->mentionableIds($task)),
        ]);

        $comment->task_id = $task->id;
        $comment->user_id = $request->user()->id;
        $comment->workspace_id = $task->workspace_id;
        $comment->save();

        return back();
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment->update([
            'body' => MentionParser::sanitize(
                $validated['body'],
                $this->mentionableIds($comment->task),
            ),
        ]);

        return back();
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Komentar dihapus.']);

        return back();
    }

    /**
     * People who can be mentioned on a task: the project's members (CMT-3).
     *
     * @return array<int, array{id: int, name: string, avatar: string|null}>
     */
    protected function mentionables(Task $task): array
    {
        return $task->project
            ->members()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.avatar_path'])
            ->map(fn ($user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
            ])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    protected function mentionableIds(Task $task): array
    {
        return $task->project->members()->pluck('users.id')->all();
    }
}
