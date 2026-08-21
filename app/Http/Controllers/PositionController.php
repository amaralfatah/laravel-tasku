<?php

namespace App\Http\Controllers;

use App\Http\Requests\Position\PositionRequest;
use App\Models\OrgUnit;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Positions (ORG-10) describe where someone sits in the organisation; the
 * workspace role decides what they may do. They are managed by the same people
 * who manage the org tree, so they reuse OrgUnitPolicy.
 */
class PositionController extends Controller
{
    public function store(PositionRequest $request): RedirectResponse
    {
        $this->authorize('create', OrgUnit::class);

        Position::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Jabatan ditambahkan.']);

        return back();
    }

    public function update(PositionRequest $request, Position $position): RedirectResponse
    {
        $this->authorize('create', OrgUnit::class);

        $position->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Jabatan diperbarui.']);

        return back();
    }

    public function destroy(Position $position): RedirectResponse
    {
        $this->authorize('create', OrgUnit::class);

        if ($position->members()->exists()) {
            throw ValidationException::withMessages([
                'position' => 'Jabatan masih dipakai anggota. Ubah jabatan mereka terlebih dahulu.',
            ]);
        }

        $position->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Jabatan dihapus.']);

        return back();
    }
}
