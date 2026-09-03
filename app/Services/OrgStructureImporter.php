<?php

namespace App\Services;

use App\Services\Sap\CdsClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mirrors the SAP org structure (CDS view `ZA_HRIS_ORGZ`) into `org_units`.
 *
 * The view is a flat edge list: one row per `reports to` link, keyed by SAP
 * object ids. Parsing (`forest()`) is kept apart from writing (`sync()`) so
 * `--dry-run` reports the real shape and tests can feed rows without touching
 * the database.
 *
 * Matching is by `external_id`, so a re-import updates the units it created
 * earlier instead of duplicating a 26k node tree. Units added by hand carry a
 * null `external_id` and are never touched.
 */
class OrgStructureImporter
{
    public const VIEW = 'ZA_HRIS_ORGZ';

    /**
     * The only relation the view carries: `B002 Top down, reports to`. Any
     * other relation is a different kind of edge and is ignored.
     */
    public const RELATION = 'B002';

    /**
     * SAP object id of the holding, `PT PERKEBUNANAN NUSANTARA I`.
     *
     * The view carries 52 roots, but 51 of them are fragments SAP never sends
     * a parent edge for — `KEBUN 2 PAN`, `DISTRIK TANDUN`, four units named
     * `-`. Only this one is a real top. The import therefore keeps its subtree
     * and drops the rest, and the holding itself is dropped too so the operating
     * companies below it stand as the roots of the master tree.
     */
    public const HOLDING = '10000000';

    /**
     * Operating companies whose subtree is left out of the import.
     *
     * These are the legacy PTPN entities that the group's restructuring folded
     * into PalmCo, SupportingCo and Sinergi Gula Nusantara. SAP still carries
     * them, but nobody in this application works in them. `PTPN III (PERSERO)`
     * is deliberately absent from this list — it is still live.
     *
     * `--all` on the import command bypasses this along with the holding trim.
     */
    protected const EXCLUDED = [
        '10100000',  // PTPN I
        '10200000',  // PTPN II
        '10430000',  // PTPN IV
        '10530000',  // PTPN V
        '10600000',  // PTPN VI
        '10700000',  // PTPN VII
        '10800000',  // PT PERKEBUNAN NUSANTARA VIII
        '10930000',  // PTPN IX
        '11100000',  // PTPN XI
        '12000000',  // PTPN XII
        '13000000',  // PTPN XIII
        '11400000',  // PT. PERKEBUNAN NUSANTARA XIV
    ];

    /**
     * SAP does not classify units, so the type is derived from the level the
     * unit sits at. Only applied when a unit is first created — a type changed
     * by hand afterwards survives the next import.
     */
    protected const TYPE_BY_DEPTH = [
        0 => 'company',
        1 => 'division',
        2 => 'division',
        3 => 'sub_division',
        4 => 'sub_division',
        5 => 'sub_division',
    ];

    public function __construct(protected CdsClient $cds) {}

    /**
     * @return array<int, array<string, string>>
     */
    public function fetch(): array
    {
        return $this->cds->rows(self::VIEW);
    }

    /**
     * Turn the flat edge list into a depth-resolved forest.
     *
     * Unless `$holdingId` is null, everything outside the holding's subtree is
     * discarded and the holding itself is removed, leaving its children as the
     * roots — see `self::HOLDING`.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{
     *     nodes: array<string, array{external_id: string, name: string, parent: string|null, depth: int}>,
     *     roots: int,
     *     max_depth: int,
     *     skipped: int,
     *     conflicts: int,
     *     cycles: int,
     *     dropped: int,
     *     excluded: int
     * }
     */
    public function forest(array $rows, ?string $holdingId = self::HOLDING): array
    {
        /** @var array<string, string> $names */
        $names = [];

        /** @var array<string, string> $parentOf */
        $parentOf = [];

        $skipped = 0;
        $conflicts = 0;

        foreach ($rows as $row) {
            if (! str_starts_with((string) ($row['relasi'] ?? ''), self::RELATION)) {
                $skipped++;

                continue;
            }

            $parent = trim((string) ($row['o1id'] ?? ''));
            $child = trim((string) ($row['o2id'] ?? ''));

            if ($parent === '' || $child === '' || $parent === $child) {
                $skipped++;

                continue;
            }

            $names[$parent] ??= trim((string) ($row['o1text'] ?? '')) ?: $parent;
            $names[$child] ??= trim((string) ($row['o2text'] ?? '')) ?: $child;

            // SAP should hand out one parent per unit. If it ever hands out
            // two, the first edge wins so the import stays deterministic.
            if (isset($parentOf[$child]) && $parentOf[$child] !== $parent) {
                $conflicts++;

                continue;
            }

            $parentOf[$child] = $parent;
        }

        if ($names === []) {
            throw new RuntimeException('Tidak ada relasi '.self::RELATION.' yang bisa dibaca dari '.self::VIEW.'.');
        }

        /** @var array<string, int> $depths */
        $depths = [];
        $cycles = 0;

        foreach (array_keys($names) as $id) {
            if (! $this->resolveDepth($id, $parentOf, $depths)) {
                $cycles++;
            }
        }

        $nodes = [];

        foreach ($names as $id => $name) {
            $nodes[$id] = [
                'external_id' => $id,
                'name' => $name,
                'parent' => $parentOf[$id] ?? null,
                'depth' => $depths[$id],
            ];
        }

        $trim = $holdingId === null
            ? ['dropped' => 0, 'excluded' => 0]
            : $this->promoteChildrenOfHolding($nodes, $parentOf, $holdingId);

        $roots = 0;
        $maxDepth = 0;

        foreach ($nodes as $node) {
            $roots += $node['parent'] === null ? 1 : 0;
            $maxDepth = max($maxDepth, $node['depth']);
        }

        return [
            'nodes' => $nodes,
            'roots' => $roots,
            'max_depth' => $maxDepth,
            'skipped' => $skipped,
            'conflicts' => $conflicts,
            'cycles' => $cycles,
            'dropped' => $trim['dropped'],
            'excluded' => $trim['excluded'],
        ];
    }

    /**
     * Write the forest into the master tree and rebuild the materialized paths.
     *
     * @param  array<string, array{external_id: string, name: string, parent: string|null, depth: int}>  $nodes
     * @return array{created: int, updated: int, unchanged: int, stale: int}
     */
    public function sync(array $nodes): array
    {
        return DB::transaction(function () use ($nodes): array {
            $existing = DB::table('org_units')
                ->whereNotNull('external_id')
                ->get(['id', 'external_id', 'parent_id', 'name', 'depth'])
                ->keyBy('external_id');

            /** @var array<string, int> $localId SAP object id -> org_units.id */
            $localId = $existing->map(fn (object $unit): int => $unit->id)->all();

            $created = 0;
            $updated = 0;
            $unchanged = 0;
            $maxDepth = 0;
            $now = now();

            foreach ($this->groupByDepth($nodes) as $depth => $level) {
                $maxDepth = max($maxDepth, $depth);
                $insert = [];

                foreach ($level as $node) {
                    $parentId = $node['parent'] === null ? null : ($localId[$node['parent']] ?? null);

                    if ($node['parent'] !== null && $parentId === null) {
                        throw new RuntimeException("Induk {$node['parent']} belum ada saat menulis unit {$node['external_id']}.");
                    }

                    $current = $existing->get($node['external_id']);

                    if ($current === null) {
                        $insert[] = [
                            'external_id' => $node['external_id'],
                            'parent_id' => $parentId,
                            'name' => $node['name'],
                            'type' => self::TYPE_BY_DEPTH[$depth] ?? 'team',
                            'path' => '',
                            'depth' => $depth,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        continue;
                    }

                    if ($current->name === $node['name'] && $current->parent_id === $parentId && $current->depth === $depth) {
                        $unchanged++;

                        continue;
                    }

                    DB::table('org_units')->where('id', $current->id)->update([
                        'name' => $node['name'],
                        'parent_id' => $parentId,
                        'depth' => $depth,
                        'updated_at' => $now,
                    ]);

                    $updated++;
                }

                foreach (array_chunk($insert, 500) as $chunk) {
                    DB::table('org_units')->insert($chunk);
                }

                $created += count($insert);

                $this->mapNewIds(array_column($insert, 'external_id'), $localId);
            }

            $this->rebuildPaths($maxDepth);

            return [
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'stale' => $existing->keys()->diff(array_keys($nodes))->count(),
            ];
        });
    }

    /**
     * Delete units an earlier import created that the view no longer carries.
     *
     * Deepest first, and never a unit anything still hangs off — a project, a
     * member placement, or a sub unit added by hand. Those are reported back
     * instead, because dropping them would silently take work with them.
     *
     * @param  array<string, array{external_id: string, name: string, parent: string|null, depth: int}>  $nodes
     * @return array{deleted: int, kept: int}
     */
    public function prune(array $nodes): array
    {
        return DB::transaction(function () use ($nodes): array {
            $stale = DB::table('org_units')
                ->whereNotNull('external_id')
                ->orderByDesc('depth')
                ->get(['id', 'external_id'])
                ->reject(fn (object $unit): bool => isset($nodes[$unit->external_id]));

            $deleted = 0;
            $kept = 0;

            foreach ($stale as $unit) {
                $inUse = DB::table('org_units')->where('parent_id', $unit->id)->exists()
                    || DB::table('projects')->where('org_unit_id', $unit->id)->exists()
                    || DB::table('workspace_members')->where('org_unit_id', $unit->id)->exists();

                if ($inUse) {
                    $kept++;

                    continue;
                }

                DB::table('org_units')->where('id', $unit->id)->delete();
                $deleted++;
            }

            return ['deleted' => $deleted, 'kept' => $kept];
        });
    }

    /**
     * Keep only the holding's subtree minus the retired entities, then remove
     * the holding itself so the remaining operating companies become the roots.
     *
     * Everything else in the view is a fragment SAP sends no parent edge for,
     * and hanging those next to the real structure makes the top level read as
     * fifty-odd unrelated entries instead of the company.
     *
     * @param  array<array-key, array{external_id: string, name: string, parent: string|null, depth: int}>  $nodes
     * @param  array<string, string>  $parentOf
     * @return array{dropped: int, excluded: int}
     */
    protected function promoteChildrenOfHolding(array &$nodes, array $parentOf, string $holdingId): array
    {
        if (! isset($nodes[$holdingId])) {
            throw new RuntimeException("Unit induk {$holdingId} tidak ada di ".self::VIEW.'.');
        }

        $childrenOf = [];

        foreach ($parentOf as $child => $parent) {
            $childrenOf[$parent][] = $child;
        }

        $excluded = array_values(array_filter(
            self::EXCLUDED,
            fn (string $id): bool => isset($nodes[$id]),
        ));

        $keep = array_diff_key(
            $this->subtree($childrenOf, $childrenOf[$holdingId] ?? []),
            $this->subtree($childrenOf, $excluded),
        );

        $dropped = count($nodes) - count($keep);
        $nodes = array_intersect_key($nodes, $keep);

        foreach ($nodes as $id => $node) {
            $nodes[$id]['parent'] = $node['parent'] === $holdingId ? null : $node['parent'];
            $nodes[$id]['depth'] = $node['depth'] - 1;
        }

        return ['dropped' => $dropped, 'excluded' => count($excluded)];
    }

    /**
     * Every unit reachable from the seeds, seeds included.
     *
     * @param  array<string, array<int, string>>  $childrenOf
     * @param  array<int, string>  $seeds
     * @return array<string, true>
     */
    protected function subtree(array $childrenOf, array $seeds): array
    {
        $found = [];
        $stack = $seeds;

        while ($stack !== []) {
            $id = array_pop($stack);

            if (isset($found[$id])) {
                continue;
            }

            $found[$id] = true;

            foreach ($childrenOf[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $found;
    }

    /**
     * Depth of one unit, memoized by walking up to a root.
     *
     * Returns false when the chain loops back on itself; the loop is then cut
     * by dropping the offending edge, so a bad export cannot hang the import.
     *
     * @param  array<string, string>  $parentOf
     * @param  array<string, int>  $depths
     */
    protected function resolveDepth(string $id, array &$parentOf, array &$depths): bool
    {
        $chain = [];
        $cursor = $id;

        while (! isset($depths[$cursor]) && isset($parentOf[$cursor])) {
            if (isset($chain[$cursor])) {
                // Cut the loop at the unit we came back to and re-resolve from
                // there, now that it is a root.
                unset($parentOf[$cursor]);
                $this->resolveDepth($id, $parentOf, $depths);

                return false;
            }

            $chain[$cursor] = true;
            $cursor = $parentOf[$cursor];
        }

        $depth = $depths[$cursor] ?? 0;
        $depths[$cursor] = $depth;

        foreach (array_reverse(array_keys($chain)) as $step) {
            $depths[$step] = ++$depth;
        }

        return true;
    }

    /**
     * Shallow levels first, so a parent always has a local id before its
     * children are written.
     *
     * @param  array<string, array{external_id: string, name: string, parent: string|null, depth: int}>  $nodes
     * @return array<int, array<int, array{external_id: string, name: string, parent: string|null, depth: int}>>
     */
    protected function groupByDepth(array $nodes): array
    {
        $levels = [];

        foreach ($nodes as $node) {
            $levels[$node['depth']][] = $node;
        }

        ksort($levels);

        return $levels;
    }

    /**
     * Read back the ids the database handed the rows just inserted.
     *
     * @param  array<int, string>  $externalIds
     * @param  array<string, int>  $localId
     */
    protected function mapNewIds(array $externalIds, array &$localId): void
    {
        foreach (array_chunk($externalIds, 1000) as $chunk) {
            DB::table('org_units')
                ->whereIn('external_id', $chunk)
                ->get(['id', 'external_id'])
                ->each(function (object $unit) use (&$localId): void {
                    $localId[$unit->external_id] = $unit->id;
                });
        }
    }

    /**
     * Rewrite `path` one level at a time, so every level reads a parent path
     * that is already correct. Far cheaper than 26k round trips through the
     * model, and it leaves hand-made units alone.
     */
    protected function rebuildPaths(int $maxDepth): void
    {
        for ($depth = 0; $depth <= $maxDepth; $depth++) {
            DB::update(
                "update org_units
                    set path = coalesce((select p.path from org_units p where p.id = org_units.parent_id), '/') || org_units.id || '/'
                  where depth = ?
                    and external_id is not null",
                [$depth],
            );
        }
    }
}
