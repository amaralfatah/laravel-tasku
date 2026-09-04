<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Observers\TaskObserver;
use App\Support\TaskPresenter;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $project_id
 * @property-read string $reference project key plus WBS number, e.g. GROWMATE-1.2
 * @property int|null $parent_task_id
 * @property string $path materialized path, e.g. /12/45/78/
 * @property int $depth 0 is a root task
 * @property string $wbs_number computed, e.g. 1.1.1
 * @property string $title
 * @property string|null $description
 * @property int|null $assignee_id
 * @property TaskStatus $status
 * @property int $progress
 * @property CarbonImmutable|null $completed_at stamped when the status turns done
 * @property CarbonImmutable|null $submitted_at when the worker handed it up for review
 * @property CarbonImmutable|null $reviewed_at when the decision was made
 * @property int|null $reviewed_by who accepted or returned the work
 * @property TaskPriority $priority
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property int $position
 * @property int|null $created_by who filed the task
 * @property int|null $requester_id who asked for the work, off the workspace list
 * @property CarbonImmutable|null $deleted_at
 */
#[ObservedBy(TaskObserver::class)]
#[Fillable([
    'title',
    'description',
    'assignee_id',
    'status',
    'progress',
    'priority',
    'start_date',
    'due_date',
    'requester_id',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    /**
     * Nesting limit (TSK-9). Depth is zero based, so depth 3 is the fourth
     * level and may not take children (TSK-10). Relaxing the limit later is a
     * one-constant change (5.3 note 3).
     */
    public const MAX_DEPTH = 4;

    /**
     * The reference people read and say out loud, e.g. `GROWMATE-1.2`.
     *
     * The project key gives it the Jira shape; the number is the WBS number,
     * so the reference also states where the task sits in the tree. That makes
     * it a position rather than an identity: renumbering a branch (TSK-13)
     * changes it, so never store one as a link to a task — use the id.
     *
     * Reads the project relation, so callers that render many tasks should
     * either eager load it or hand the key straight to {@see TaskPresenter}.
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function reference(): Attribute
    {
        return Attribute::get(fn (): string => $this->project->key.'-'.$this->wbs_number);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    /** @return HasMany<Task, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id')->orderBy('position');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->oldest();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whoever asked for the work, as opposed to {@see creator()}, who filed
     * it. Picked off the workspace's managed list, and often not a user of
     * the application at all.
     *
     * @return BelongsTo<Requester, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Requester::class);
    }

    /**
     * Whoever accepted or returned the work.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Waiting on somebody's decision.
     */
    public function awaitsReview(): bool
    {
        return $this->status === TaskStatus::Review;
    }

    /**
     * The task itself plus every descendant.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeInSubtree(Builder $query, Task $root): void
    {
        $query->where('path', 'like', $root->path.'%');
    }

    /**
     * Descendants only, excluding the task itself.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeDescendantsOf(Builder $query, Task $root): void
    {
        $query->where('path', 'like', $root->path.'_%');
    }

    /**
     * Tasks that are late: past due and not finished (DIV-4).
     *
     * Tasks without a due date are never late; they are reported separately
     * as unscheduled (DIV-5).
     *
     * @param  Builder<Task>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->where('status', '!=', TaskStatus::Done);
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->status !== TaskStatus::Done
            && $this->due_date->isBefore(now()->startOfDay());
    }

    public function canHaveChildren(): bool
    {
        return $this->depth + 1 < self::MAX_DEPTH;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'start_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
