<?php

namespace App\Services\Ai;

use App\Concerns\ScopesValidationToWorkspace;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskHierarchy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Executes a plan {@see TaskPlanner} produced.
 *
 * Nothing here trusts the plan. Every operation is validated against the same
 * rules the task forms use and authorized against the same policies, so a
 * hallucinated assignee or a tampered payload can do no more than a hand
 * written request could — it is rejected. The whole plan runs in one
 * transaction: the preview promised a set of changes, so a rejected operation
 * takes the rest of them back rather than leaving the project half changed.
 */
class TaskPlanApplier
{
    use ScopesValidationToWorkspace;

    public function __construct(protected TaskHierarchy $hierarchy) {}

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @return array{created: int, updated: int, deleted: int}
     */
    public function apply(PlanScope $scope, array $operations, User $user): array
    {
        return DB::transaction(function () use ($scope, $operations, $user): array {
            $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];

            /** @var array<string, Task> $byRef tasks created earlier in this plan, so a sub task can name its new parent */
            $byRef = [];

            foreach (array_values($operations) as $index => $operation) {
                $number = $index + 1;

                match ($operation['op'] ?? null) {
                    'create' => $this->create($scope, $operation, $user, $number, $byRef, $counts),
                    'update' => $this->update($scope, $operation, $user, $number, $counts),
                    'delete' => $this->delete($scope, $operation, $user, $number, $counts),
                    default => $this->reject($number, 'Jenis operasi tidak dikenal.'),
                };
            }

            return $counts;
        });
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, Task>  $byRef
     * @param  array{created: int, updated: int, deleted: int}  $counts
     */
    protected function create(PlanScope $scope, array $operation, User $user, int $number, array &$byRef, array &$counts): void
    {
        $project = $this->projectFor($scope, $operation, $user, $number);

        // Mirrors App\Http\Requests\Task\TaskStoreRequest. Kept apart because a
        // form request is bound to an HTTP request and this one arrives as a
        // row of a plan; keep the two in step when either changes.
        $attributes = $this->validate($number, $operation, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'parent_task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->where('project_id', $project->id)],
            'assignee_id' => ['nullable', 'integer', $this->existsAsProjectMember($project->id)],
            'requester_id' => ['nullable', 'integer', $this->existsAsActiveRequester()],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'ref' => ['nullable', 'string', 'max:60'],
            'parent_ref' => ['nullable', 'string', 'max:60'],
        ]);

        $ref = $attributes['ref'] ?? null;
        $parentRef = $attributes['parent_ref'] ?? null;
        $parentId = $attributes['parent_task_id'] ?? null;
        unset($attributes['ref'], $attributes['parent_ref'], $attributes['parent_task_id']);

        $parent = match (true) {
            is_string($parentRef) && $parentRef !== '' => $byRef[$parentRef]
                ?? $this->reject($number, 'Task induk yang dirujuk tidak ada di rencana ini.'),
            default => $this->taskInProject($project, $parentId, $number),
        };

        $attributes = $this->hierarchy->syncProgress(
            $attributes,
            new Task(['status' => TaskStatus::Todo->value, 'progress' => 0]),
        );

        $attributes['created_by'] = $user->id;

        $task = $this->hierarchy->create($project, $attributes, $parent);

        if (is_string($ref) && $ref !== '') {
            $byRef[$ref] = $task;
        }

        $counts['created']++;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array{created: int, updated: int, deleted: int}  $counts
     */
    protected function update(PlanScope $scope, array $operation, User $user, int $number, array &$counts): void
    {
        $task = $this->taskInScope($scope, $operation['id'] ?? null, $number)
            ?? $this->reject($number, 'Task yang mau diubah tidak disebut.');

        $this->authorize($user, 'update', $task, $number);

        $project = $task->project;

        // Mirrors App\Http\Requests\Task\TaskUpdateRequest.
        $attributes = $this->validate($number, $operation, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', $this->existsAsProjectMember($project->id)],
            'requester_id' => ['sometimes', 'nullable', 'integer', $this->existsAsActiveRequester()],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        // TSK-8 against the stored values, since a plan may send one date only.
        $start = array_key_exists('start_date', $attributes)
            ? $this->asDate($attributes['start_date'])
            : $task->start_date;
        $due = array_key_exists('due_date', $attributes)
            ? $this->asDate($attributes['due_date'])
            : $task->due_date;

        if ($start !== null && $due !== null && $due->lt($start)) {
            $this->reject($number, 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.');
        }

        $task->fill($this->hierarchy->syncProgress($attributes, $task))->save();

        $counts['updated']++;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array{created: int, updated: int, deleted: int}  $counts
     */
    protected function delete(PlanScope $scope, array $operation, User $user, int $number, array &$counts): void
    {
        $task = $this->taskInScope($scope, $operation['id'] ?? null, $number)
            ?? $this->reject($number, 'Task yang mau dihapus tidak disebut.');

        $this->authorize($user, 'delete', $task, $number);

        $this->hierarchy->delete($task);

        $counts['deleted']++;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, array<mixed>>  $rules
     * @return array<string, mixed>
     */
    protected function validate(int $number, array $operation, array $rules): array
    {
        unset($operation['op'], $operation['id']);

        // A field the model left out means "unchanged". An explicit null means
        // "clear it", which only the optional fields of an update accept — on
        // the create side a null is dropped so the column default applies.
        $payload = array_filter(
            array_intersect_key($operation, $rules),
            fn (mixed $value, string $key): bool => $value !== null
                || in_array('sometimes', $rules[$key], true),
            ARRAY_FILTER_USE_BOTH,
        );

        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            $this->reject($number, (string) $validator->errors()->first());
        }

        return $validator->validated();
    }

    /**
     * The project a new task belongs in.
     *
     * A project view settles it; a monitoring view crosses projects, so the
     * plan has to name one — and it may only name a project this user could
     * have created the task in by hand.
     *
     * @param  array<string, mixed>  $operation
     */
    protected function projectFor(PlanScope $scope, array $operation, User $user, int $number): Project
    {
        if ($scope->project !== null) {
            return $scope->project;
        }

        $id = $operation['project_id'] ?? null;

        if ($id === null) {
            $this->reject($number, 'Task baru harus menyebut project-nya.');
        }

        $project = $scope->projects($user)->firstWhere('id', (int) $id);

        return $project ?? $this->reject(
            $number,
            "Kamu tidak bisa membuat task di project #{$id}.",
        );
    }

    /**
     * A task the plan named, checked against the slice the conversation is
     * about — an id outside it, or in another workspace, is not found here.
     */
    protected function taskInScope(PlanScope $scope, mixed $id, int $number): ?Task
    {
        if ($id === null) {
            return null;
        }

        $task = $scope->tasks()->find((int) $id);

        return $task ?? $this->reject($number, "Task #{$id} tidak ada di daftar ini.");
    }

    /**
     * A task inside one project, for the parent of a task being created.
     */
    protected function taskInProject(Project $project, mixed $id, int $number): ?Task
    {
        if ($id === null) {
            return null;
        }

        $task = Task::query()
            ->where('project_id', $project->id)
            ->find((int) $id);

        return $task ?? $this->reject($number, "Task #{$id} tidak ada di project ini.");
    }

    protected function authorize(User $user, string $ability, Task $task, int $number): void
    {
        if (Gate::forUser($user)->denies($ability, $task)) {
            $this->reject($number, "Kamu tidak berhak mengubah task {$task->title}.");
        }
    }

    protected function asDate(mixed $value): ?CarbonInterface
    {
        return $value === null ? null : Date::parse((string) $value);
    }

    /**
     * @throws ValidationException
     */
    protected function reject(int $number, string $message): never
    {
        throw ValidationException::withMessages([
            'operations' => "Operasi ke-{$number}: {$message}",
        ]);
    }
}
