<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceMember>
 */
class WorkspaceMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'role' => WorkspaceRole::Bod4,
            'joined_at' => now(),
        ];
    }

    public function kepalaDivisi(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => WorkspaceRole::Bod1,
        ]);
    }

    public function kepalaSubDivisi(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => WorkspaceRole::Bod2,
        ]);
    }

    public function asisten(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => WorkspaceRole::Bod3,
        ]);
    }

    /**
     * Place the member in a unit. Scope follows placement, so this is what
     * decides how much of the tree a leader reaches.
     */
    public function in(OrgUnit $unit): static
    {
        return $this->state(fn (array $attributes): array => [
            'org_unit_id' => $unit->id,
        ]);
    }

    /**
     * A leader over the given unit and everything below it.
     */
    public function leading(OrgUnit $unit, WorkspaceRole $role = WorkspaceRole::Bod3): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => $role,
            'org_unit_id' => $unit->id,
        ]);
    }
}
