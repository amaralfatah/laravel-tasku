<?php

namespace Database\Factories;

use App\Models\OrgUnit;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrgUnit>
 */
class OrgUnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => fake()->unique()->words(2, true),
            'type' => 'division',
            'path' => '',
            'depth' => 0,
        ];
    }

    /**
     * Create the unit and hand it to a workspace as the slice it runs.
     */
    public function rootOf(Workspace $workspace): static
    {
        return $this->afterCreating(function (OrgUnit $unit) use ($workspace): void {
            $workspace->forceFill(['root_org_unit_id' => $unit->id])->save();
        });
    }

    /**
     * Nest the unit under an existing parent.
     */
    public function childOf(OrgUnit $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'parent_id' => $parent->id,
            'type' => 'sub_division',
            'depth' => $parent->depth + 1,
        ]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (OrgUnit $unit): void {
            $prefix = $unit->parent_id === null
                ? '/'
                : OrgUnit::withoutGlobalScopes()->find($unit->parent_id)->path;

            $unit->forceFill(['path' => $prefix.$unit->id.'/'])->saveQuietly();
        });
    }
}
