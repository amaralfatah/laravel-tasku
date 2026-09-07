<?php

namespace App\Support;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * Task filters shared by the board, list and timeline (6.12).
 *
 * Values come straight from the query string so a filtered view is a
 * shareable URL (FLT-4).
 */
class TaskFilters
{
    public function __construct(
        public readonly ?int $assigneeId = null,
        public readonly ?TaskStatus $status = null,
        public readonly ?TaskPriority $priority = null,
        public readonly ?string $search = null,
        public readonly string $sort = 'wbs',
        public readonly bool $overdue = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            assigneeId: $request->integer('assignee_id') ?: null,
            status: TaskStatus::tryFrom((string) $request->query('status')),
            priority: TaskPriority::tryFrom((string) $request->query('priority')),
            search: trim((string) $request->query('search')) ?: null,
            sort: in_array($request->query('sort'), ['wbs', 'due_date', 'priority', 'created_at'], true)
                ? (string) $request->query('sort')
                : 'wbs',
            overdue: $request->boolean('overdue'),
        );
    }

    /**
     * @param  Builder<Task>  $query
     */
    public function apply(Builder $query): void
    {
        $query
            ->when($this->assigneeId, fn (Builder $q, int $id) => $q->where('assignee_id', $id))
            ->when($this->status, fn (Builder $q, TaskStatus $status) => $q->where('status', $status))
            ->when($this->priority, fn (Builder $q, TaskPriority $priority) => $q->where('priority', $priority))
            ->when($this->search, fn (Builder $q, string $term) => $this->applySearch($q, $term))
            // Work that has run past its date and is not finished: the one
            // filter a leader reaches for, since a healthy task needs no
            // attention and an overdue one always does.
            ->when($this->overdue, fn (Builder $q) => $q
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())
                ->where('status', '!=', TaskStatus::Done));
    }

    /**
     * Keep every task on a branch a match sits on: the task whose own title
     * matches, the ancestors above a matching sub task, and the sub tasks
     * under a matching parent.
     *
     * Matching the title alone loses the hit whenever it is not a root task.
     * The board only draws depth 0, so searching for a sub task emptied it,
     * and the list drew the row with nothing above it. Searching the branch is
     * what makes the box reach sub tasks as well as tasks.
     *
     * `path` carries the task's own id — `/12/45/78/` — so one path being a
     * prefix of the other is exactly "same branch". `lower(...) like` rather
     * than `ilike`, since the test suite runs on SQLite, which has no `ilike`.
     *
     * @param  Builder<Task>  $query
     */
    protected function applySearch(Builder $query, string $term): void
    {
        $needle = '%'.mb_strtolower($term).'%';

        $query->whereExists(function (QueryBuilder $matches) use ($needle): void {
            $matches
                ->selectRaw('1')
                ->from('tasks as matched')
                ->whereColumn('matched.project_id', 'tasks.project_id')
                ->whereNull('matched.deleted_at')
                ->whereRaw('lower(matched.title) like ?', [$needle])
                ->where(fn (QueryBuilder $branch) => $branch
                    ->whereRaw("tasks.path like matched.path || '%'")
                    ->orWhereRaw("matched.path like tasks.path || '%'"));
        });
    }

    /**
     * Apply the chosen ordering (LST-3).
     *
     * `wbs` keeps the hierarchy readable by ordering on the materialized path;
     * the other options are flat orderings for scanning.
     *
     * @param  Builder<Task>  $query
     */
    public function applySort(Builder $query): void
    {
        match ($this->sort) {
            'due_date' => $query->orderByRaw('due_date is null')->orderBy('due_date'),
            'priority' => $query->orderByRaw(
                "case priority when 'urgent' then 0 when 'high' then 1 when 'medium' then 2 else 3 end",
            ),
            'created_at' => $query->orderByDesc('created_at'),
            default => $query->orderBy('path'),
        };
    }

    public function isActive(): bool
    {
        return $this->assigneeId !== null
            || $this->status !== null
            || $this->priority !== null
            || $this->search !== null
            || $this->overdue;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'assignee_id' => $this->assigneeId,
            'status' => $this->status?->value,
            'priority' => $this->priority?->value,
            'search' => $this->search,
            'sort' => $this->sort,
            'overdue' => $this->overdue,
        ];
    }
}
