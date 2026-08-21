<?php

namespace Database\Factories;

use App\Enums\ScopeType;
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
            'scope_type' => ScopeType::ProjectOnly,
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

    /**
     * Grant read-only visibility over an org unit and everything below it.
     */
    public function monitoring(OrgUnit $unit): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope_type' => ScopeType::UnitSubtree,
            'scope_org_unit_id' => $unit->id,
            'org_unit_id' => $unit->id,
        ]);
    }
}
