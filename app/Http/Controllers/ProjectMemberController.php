<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Project membership (PRJ-3). Membership is what grants edit rights; a subtree
 * monitoring scope only grants read access.
 */
class ProjectMemberController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $isWorkspaceMember = WorkspaceMember::query()
            ->where('user_id', $validated['user_id'])
            ->exists();

        if (! $isWorkspaceMember) {
            throw ValidationException::withMessages([
                'user_id' => 'Pengguna bukan anggota workspace ini.',
            ]);
        }

        $project->members()->syncWithoutDetaching([$validated['user_id']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Anggota ditambahkan ke project.']);

        return back();
    }

    public function destroy(Project $project, User $user): RedirectResponse
    {
        $this->authorize('update', $project);

        $project->members()->detach($user->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Anggota dikeluarkan dari project.']);

        return back();
    }
}
