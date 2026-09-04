<?php

namespace Database\Factories;

use App\Models\Requester;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Requester>
 */
class RequesterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->name(),
            'organization' => fake()->optional()->company(),
            'email' => fake()->optional()->safeEmail(),
            'is_active' => true,
        ];
    }

    /**
     * Retired: still named by old tasks, no longer offered by the picker.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
