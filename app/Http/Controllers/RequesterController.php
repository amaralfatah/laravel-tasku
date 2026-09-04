<?php

namespace App\Http\Controllers;

use App\Http\Requests\Requester\RequesterStoreRequest;
use App\Http\Requests\Requester\RequesterUpdateRequest;
use App\Models\Requester;
use App\Policies\RequesterPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace's list of people work is requested for.
 *
 * A leader maintains it here; everybody else only ever picks from it on a task
 * form. See {@see RequesterPolicy} for why that split is where
 * it is.
 */
class RequesterController extends Controller
{
    /**
     * Manage the list.
     */
    public function index(Request $request): Response
    {
        $this->authorize('manage', Requester::class);

        return Inertia::render('requesters/index', [
            'requesters' => Requester::query()
                ->withCount('tasks')
                ->orderBy('name')
                ->get()
                ->map(fn (Requester $requester): array => [
                    'id' => $requester->id,
                    'name' => $requester->name,
                    'organization' => $requester->organization,
                    'email' => $requester->email,
                    'is_active' => $requester->is_active,
                    // Drives the interface's own answer to "why can't I delete
                    // this one": a requester tasks point at is retired, never
                    // removed.
                    'tasks_count' => $requester->tasks_count,
                ])
                ->all(),
        ]);
    }

    public function store(RequesterStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', Requester::class);

        $requester = new Requester($request->validated());
        $requester->created_by = $request->user()->id;
        $requester->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pemohon {$requester->name} ditambahkan."]);

        return back();
    }

    public function update(RequesterUpdateRequest $request, Requester $requester): RedirectResponse
    {
        $this->authorize('update', $requester);

        $requester->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pemohon diperbarui.']);

        return back();
    }

    /**
     * Remove a requester who was never used.
     *
     * Anyone already named by a task is kept: deleting them would rewrite what
     * those tasks say about who asked for the work, and the point of a managed
     * list is that history stays readable. Deactivating takes them out of the
     * picker without touching a thing.
     */
    public function destroy(Requester $requester): RedirectResponse
    {
        $this->authorize('delete', $requester);

        if ($requester->tasks()->exists()) {
            throw ValidationException::withMessages([
                'requester' => 'Pemohon ini sudah dipakai di task. Nonaktifkan saja agar riwayatnya tetap utuh.',
            ]);
        }

        $requester->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pemohon dihapus.']);

        return back();
    }
}
