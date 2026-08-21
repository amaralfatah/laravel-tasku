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
            'workspace_id' => Workspace::factory(),
            'parent_id' => null,
            'name' => fake()->unique()->words(2, true),
            'type' => 'division',
            'path' => '',
            'depth' => 0,
        ];
    }

    /**
     * Nest the unit under an existing parent.
     */
    public function childOf(OrgUnit $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'workspace_id' => $parent->workspace_id,
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
