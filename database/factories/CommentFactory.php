<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'workspace_id' => fn (array $attributes): int => Task::withoutGlobalScopes()
                ->whereKey($attributes['task_id'])
                ->value('workspace_id'),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    /**
     * A comment that mentions someone, stored in the `@[user:id]` form.
     */
    public function mentioning(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'body' => "@[user:{$user->id}] tolong cek bagian ini ya.",
        ]);
    }
}
