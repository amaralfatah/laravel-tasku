<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The slice of work one conversation is about.
 *
 * A project view talks about a project: every task in it, and anything created
 * lands there. A monitoring view talks about a person: their tasks across
 * every project, so a new task has to name the project it belongs to, and only
 * the projects that person's work already crosses are on offer.
 *
 * Everything the model is allowed to see, and everything the applier is
 * allowed to touch, is derived from here — never from the request.
 */
class PlanScope
{
    /**
     * @param  Project|null  $project  the one project this scope is about, when it is about one
     * @param  int|null  $assigneeId  the person whose work this scope is about
     */
    protected function __construct(
        public readonly ?Project $project = null,
        public readonly ?int $assigneeId = null,
    ) {}

    public static function forProject(Project $project): self
    {
        return new self(project: $project);
    }

    public static function forAssignee(int $userId): self
    {
        return new self(assigneeId: $userId);
    }

    /**
     * Whether a new task must say which project it belongs to.
     */
    public function spansProjects(): bool
    {
        return $this->project === null;
    }

    /**
     * The tasks this conversation may name.
     *
     * The workspace boundary is the global scope's, so a scope without a
     * project is still one tenant's work and never the platform's.
     *
     * @return Builder<Task>
     */
    public function tasks(): Builder
    {
        $query = Task::query();

        if ($this->project !== null) {
            return $query->where('project_id', $this->project->id);
        }

        return $query->where('assignee_id', $this->assigneeId);
    }

    /**
     * Projects a new task may be created in: the one this scope is about, or —
     * when it spans projects — those the person's work already touches and
     * this user may contribute to.
     *
     * @return Collection<int, Project>
     */
    public function projects(User $user): Collection
    {
        if ($this->project !== null) {
            return collect([$this->project]);
        }

        return Project::query()
            ->whereIn('id', $this->tasks()->select('project_id'))
            ->with('members')
            ->get()
            ->filter(fn (Project $project): bool => $user->can('contribute', $project))
            ->values();
    }
}
