<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'org_unit_id' => OrgUnit::factory(),
            'name' => 'Project '.fake()->unique()->word().' '.fake()->word(),
            'key' => strtoupper(fake()->unique()->bothify('???#')),
            'description' => fake()->sentence(),
            'status' => ProjectStatus::Active,
        ];
    }

    /**
     * Place the project in a unit. Units are platform master data, so the
     * workspace is the one whose root sits above the unit in the tree.
     */
    public function in(OrgUnit $unit): static
    {
        return $this->state(fn (array $attributes): array => [
            'workspace_id' => Workspace::query()
                ->join('org_units as root', 'root.id', '=', 'workspaces.root_org_unit_id')
                ->whereRaw("? like root.path || '%'", [$unit->path])
                ->value('workspaces.id') ?? Workspace::factory(),
            'org_unit_id' => $unit->id,
        ]);
    }
}
