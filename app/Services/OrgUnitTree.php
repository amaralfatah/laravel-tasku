<?php

namespace App\Services;

use App\Models\OrgUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintains the materialized path and depth of the org unit tree.
 *
 * `path` contains the unit's own id, e.g. `/1/5/12/`, so a subtree is
 * `where path like '/1/5/%'` with no recursive query. Every write that can
 * touch descendants runs in a transaction (R-4).
 */
class OrgUnitTree
{
    /**
     * Create a unit under an optional parent, filling path and depth.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?OrgUnit $parent = null): OrgUnit
    {
        $this->guardDepth($parent);

        return DB::transaction(function () use ($attributes, $parent): OrgUnit {
            $unit = new OrgUnit($attributes);

            // depth and path are structural, not mass assignable.
            $unit->parent_id = $parent?->id;
            $unit->depth = $parent === null ? 0 : $parent->depth + 1;
            $unit->save();

            $unit->forceFill([
                'path' => ($parent->path ?? '/').$unit->id.'/',
            ])->save();

            return $unit;
        });
    }

    /**
     * Move a unit to another parent (or to the root) and rewrite descendants.
     */
    public function move(OrgUnit $unit, ?OrgUnit $parent): OrgUnit
    {
        if ($parent !== null && $this->isSelfOrDescendant($unit, $parent)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Unit tidak bisa dipindahkan ke dalam dirinya sendiri.',
            ]);
        }

        $oldPath = $unit->path;
        $newPath = ($parent->path ?? '/').$unit->id.'/';

        if ($oldPath === $newPath) {
            return $unit;
        }

        $depthShift = (($parent->depth ?? -1) + 1) - $unit->depth;
        $this->guardSubtreeDepth($unit, $depthShift);

        return DB::transaction(function () use ($unit, $parent, $oldPath, $newPath, $depthShift): OrgUnit {
            // Swap the path prefix on every descendant in one statement. Fully
            // bound, so no path value is ever interpolated into SQL.
            DB::update(
                'update org_units
                    set path = ? || substring(path from ?),
                        depth = depth + ?
                  where workspace_id = ?
                    and path like ?',
                [$newPath, strlen($oldPath) + 1, $depthShift, $unit->workspace_id, $oldPath.'_%'],
            );

            $unit->forceFill([
                'parent_id' => $parent?->id,
                'path' => $newPath,
                'depth' => $unit->depth + $depthShift,
            ])->save();

            return $unit->refresh();
        });
    }

    /**
     * Rebuild path and depth for a whole workspace, used by the repair command.
     */
    public function rebuild(int $workspaceId): int
    {
        return DB::transaction(function () use ($workspaceId): int {
            $units = OrgUnit::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            $touched = 0;

            foreach ($units as $unit) {
                $path = '/'.$unit->id.'/';
                $depth = 0;
                $cursor = $unit;

                while ($cursor->parent_id !== null && $units->has($cursor->parent_id)) {
                    $cursor = $units[$cursor->parent_id];
                    $path = '/'.$cursor->id.$path;
                    $depth++;
                }

                if ($unit->path !== $path || $unit->depth !== $depth) {
                    $unit->forceFill(['path' => $path, 'depth' => $depth])->saveQuietly();
                    $touched++;
                }
            }

            return $touched;
        });
    }

    /**
     * A unit can only be deleted when nothing hangs off it (ORG-4).
     */
    public function guardDeletable(OrgUnit $unit): void
    {
        if ($unit->children()->exists()) {
            throw ValidationException::withMessages([
                'unit' => 'Unit masih punya sub unit. Pindahkan atau hapus sub unit terlebih dahulu.',
            ]);
        }

        if ($unit->projects()->exists()) {
            throw ValidationException::withMessages([
                'unit' => 'Unit masih punya project. Pindahkan project ke unit lain terlebih dahulu.',
            ]);
        }
    }

    protected function isSelfOrDescendant(OrgUnit $unit, OrgUnit $candidate): bool
    {
        return $candidate->id === $unit->id || str_starts_with($candidate->path, $unit->path);
    }

    protected function guardDepth(?OrgUnit $parent): void
    {
        if ($parent !== null && $parent->depth + 1 > OrgUnit::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => 'Kedalaman maksimal '.(OrgUnit::MAX_DEPTH + 1).' tingkat sudah tercapai.',
            ]);
        }
    }

    /**
     * Reject a move that would push any descendant past the depth limit.
     */
    protected function guardSubtreeDepth(OrgUnit $unit, int $depthShift): void
    {
        $deepest = (int) OrgUnit::query()
            ->where('path', 'like', $unit->path.'%')
            ->max('depth');

        if ($deepest + $depthShift > OrgUnit::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => 'Pemindahan ini melebihi kedalaman maksimal '.(OrgUnit::MAX_DEPTH + 1).' tingkat.',
            ]);
        }
    }
}
