<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Task;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * In-app notification bell (6.14).
 *
 * The frontend polls `index` every 45 seconds (NTF-5); real-time delivery is
 * explicitly out of scope for the MVP.
 */
class NotificationController extends Controller
{
    public function __construct(protected Tenancy $tenancy) {}

    /**
     * Unread count plus the 20 most recent notifications (NTF-1, NTF-2).
     */
    public function index(Request $request): JsonResponse
    {
        $base = Notification::query()
            ->where('user_id', $request->user()->id)
            ->where('workspace_id', $this->tenancy->id());

        return response()->json([
            'unread' => (clone $base)->where('is_read', false)->count(),
            'items' => (clone $base)
                ->with('actor:id,name,avatar_path')
                ->latest('created_at')
                ->limit(20)
                ->get()
                ->map(fn (Notification $notification): array => [
                    'id' => $notification->id,
                    'type' => $notification->type->value,
                    'type_label' => $notification->type->label(),
                    'message' => $notification->message,
                    'is_read' => $notification->is_read,
                    'created_at' => $notification->created_at?->toIso8601String(),
                    'actor' => $notification->actor?->name,
                ])
                ->all(),
        ]);
    }

    /**
     * Mark one notification read and open the task it is about (NTF-3).
     *
     * The list view is the target rather than the board, because only root
     * tasks appear on a board (BRD-4) — a notification about a sub task would
     * otherwise land on a page that does not show it. The `task` parameter
     * makes the list open that task's detail panel straight away.
     */
    public function read(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->update(['is_read' => true]);

        $task = $notification->entity_type === 'task'
            ? Task::query()->with('project')->find($notification->entity_id)
            : null;

        if ($task === null || $request->user()->cannot('view', $task)) {
            return back();
        }

        return redirect()->route('projects.list', [
            'project' => $task->project_id,
            'task' => $task->id,
        ]);
    }

    /**
     * Mark everything read (NTF-4).
     */
    public function readAll(Request $request): RedirectResponse
    {
        Notification::query()
            ->where('user_id', $request->user()->id)
            ->where('workspace_id', $this->tenancy->id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return back();
    }
}
