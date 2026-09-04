<?php

namespace App\Support;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
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
            ->when($this->search, fn (Builder $q, string $term) => $q->where('title', 'ilike', "%{$term}%"))
            // Work that has run past its date and is not finished: the one
            // filter a leader reaches for, since a healthy task needs no
            // attention and an overdue one always does.
            ->when($this->overdue, fn (Builder $q) => $q
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())
                ->where('status', '!=', TaskStatus::Done));
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
