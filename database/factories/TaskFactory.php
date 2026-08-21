<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 month', '+1 month');

        return [
            'project_id' => Project::factory(),
            'workspace_id' => fn (array $attributes): int => Project::withoutGlobalScopes()
                ->whereKey($attributes['project_id'])
                ->value('workspace_id'),
            'title' => ucfirst(fake()->word().' '.fake()->word().' '.fake()->word()),
            'description' => fake()->optional()->sentence(),
            'status' => TaskStatus::Todo,
            'progress' => 0,
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'start_date' => $start,
            'due_date' => fake()->dateTimeBetween($start, '+2 months'),
            'position' => 0,
            'path' => '',
            'depth' => 0,
            'wbs_number' => '',
        ];
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaskStatus::Done,
            'progress' => 100,
        ]);
    }

    public function inProgress(int $progress = 50): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaskStatus::InProgress,
            'progress' => $progress,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaskStatus::InProgress,
            'due_date' => now()->subDays(fake()->numberBetween(1, 20)),
        ]);
    }

    public function unscheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'start_date' => null,
            'due_date' => null,
        ]);
    }

    /**
     * Fill in the structural columns so a factory-made task is still a valid
     * root task. Nested trees should be built through TaskHierarchy instead.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Task $task): void {
            if ($task->path === '') {
                $task->forceFill([
                    'path' => '/'.$task->id.'/',
                    'wbs_number' => (string) ($task->position + 1),
                ])->saveQuietly();
            }
        });
    }
}
