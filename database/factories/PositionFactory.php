<?php

namespace Database\Factories;

use App\Models\Position;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->randomElement(['Kepala Divisi', 'Manager', 'Lead', 'Programmer', 'QA']),
            'level' => fake()->numberBetween(1, 5),
        ];
    }
}
