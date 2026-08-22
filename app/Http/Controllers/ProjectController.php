<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Http\Requests\Project\ProjectStoreRequest;
use App\Http\Requests\Project\ProjectUpdateRequest;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkspaceMember;
use App\Support\TaskFilters;
use App\Support\TaskPresenter;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Project list, filtered by org unit subtree (PRJ-5).
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $unitFilter = $request->integer('org_unit_id') ?: null;
        $statusFilter = $request->string('status')->toString();

        $projects = Project::query()
            ->visibleTo($request->user())
            ->with(['orgUnit:id,name'])
            ->withCount('members')
            ->when($unitFilter, function ($query, int $unitId): void {
                $path = OrgUnit::query()->whereKey($unitId)->value('path');

                $query->whereHas(
                    'orgUnit',
                    fn ($unit) => $unit->where('path', 'like', $path.'%'),
                );
            })
            ->when($statusFilter !== '', fn ($query) => $query->where('status', $statusFilter))
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'key' => $project->key,
                'description' => $project->description,
                'status' => $project->status->value,
                'status_label' => $project->status->label(),
                'org_unit' => $project->orgUnit->only(['id', 'name']),
                'members_count' => $project->members_count,
                'can_edit' => $request->user()->can('update', $project),
            ])
            ->all();

        return Inertia::render('projects/index', [
            'projects' => $projects,
            'orgUnits' => $this->orgUnitOptions(),
            'statuses' => $this->statusOptions(),
            'filters' => [
                'org_unit_id' => $unitFilter,
                'status' => $statusFilter,
            ],
            'can' => ['create' => $request->user()->can('create', Project::class)],
        ]);
    }

    /**
     * Kanban board of the project's root tasks (6.7).
     */
    public function show(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('projects/board', $this->taskWorkspaceProps($request, $project));
    }

    /**
     * Hierarchical list view of the project's tasks (6.8).
     */
    public function list(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('projects/list', $this->taskWorkspaceProps($request, $project));
    }

    /**
     * Weekly gantt timeline of the project's tasks (6.9).
     */
    public function timeline(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('projects/timeline', $this->taskWorkspaceProps($request, $project));
    }

    /**
     * Project settings and membership.
     */
    public function settings(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $project->load(['orgUnit:id,name', 'members:id,name,email,avatar_path', 'creator:id,name']);

        return Inertia::render('projects/settings', [
            'project' => [
                ...$this->projectSummary($project),
                'created_by' => $project->creator?->name,
                'members' => $project->members
                    ->map(fn ($user): array => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'avatar' => $user->avatar,
                    ])
                    ->all(),
            ],
            'orgUnits' => $this->orgUnitOptions(),
            'statuses' => $this->statusOptions(),
            'candidates' => $this->memberCandidates($project),
            'can' => [
                'edit' => $request->user()->can('update', $project),
                'contribute' => $request->user()->can('contribute', $project),
            ],
        ]);
    }

    public function store(ProjectStoreRequest $request): RedirectResponse
    {
        $orgUnitId = $this->placementUnitId($request);

        $this->authorize('createIn', [Project::class, $orgUnitId]);

        $project = DB::transaction(function () use ($request, $orgUnitId): Project {
            $project = new Project($request->safe()->only(['name', 'description', 'status']));
            $project->key = $request->string('key')->toString() ?: Project::generateKey($request->string('name')->toString());
            $project->org_unit_id = $orgUnitId;
            $project->created_by = $request->user()->id;
            $project->save();

            // The creator joins their own project. Without this an Asisten
            // (BOD-3) may create a project and then be unable to put a single
            // task in it, since contributing requires project membership.
            $memberIds = $this->workspaceUserIds($request->input('member_ids', []));
            $memberIds[] = $request->user()->id;

            $project->members()->sync(array_values(array_unique($memberIds)));

            return $project;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "Project {$project->name} dibuat."]);

        return to_route('projects.show', $project);
    }

    public function update(ProjectUpdateRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        // Moving a project into another unit must not push it out of reach.
        if ($request->has('org_unit_id')) {
            $this->authorize('createIn', [Project::class, $request->integer('org_unit_id')]);
        }

        $project->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Project diperbarui.']);

        return back();
    }

    /**
     * Soft delete so history and tasks can be recovered (PRJ-6).
     */
    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Project dihapus.']);

        return to_route('projects.index');
    }

    /**
     * Props every task view of a project shares: the task set, the filters
     * applied to it, and the options its controls need.
     *
     * @return array<string, mixed>
     */
    protected function taskWorkspaceProps(Request $request, Project $project): array
    {
        $project->load('orgUnit:id,name');

        $filters = TaskFilters::fromRequest($request);
        $canEdit = $request->user()->can('contribute', $project);

        $query = Task::query()
            ->where('project_id', $project->id)
            ->with('assignee:id,name,avatar_path');

        $filters->apply($query);
        $filters->applySort($query);

        $tasks = $query->get();

        return [
            'project' => $this->projectSummary($project),
            'tasks' => TaskPresenter::collection($tasks, $request->user(), $canEdit),
            'filters' => $filters->toArray(),
            'statuses' => TaskPresenter::statusOptions(),
            'priorities' => TaskPresenter::priorityOptions(),
            'assignees' => $this->assigneeOptions($project),
            'maxDepth' => Task::MAX_DEPTH,
            // Deep link from a notification: the view opens this task's panel.
            'focusTaskId' => $request->integer('task') ?: null,
            'can' => [
                'contribute' => $canEdit,
                'edit_project' => $request->user()->can('update', $project),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function projectSummary(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'key' => $project->key,
            'description' => $project->description,
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
            'org_unit' => $project->orgUnit->only(['id', 'name']),
        ];
    }

    /**
     * People a task may be assigned to: the project's own members (TSK-4).
     *
     * @return array<int, array{id: int, name: string, avatar: string|null}>
     */
    protected function assigneeOptions(Project $project): array
    {
        return $project->members()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.avatar_path'])
            ->map(fn ($user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
            ])
            ->all();
    }

    /**
     * Restrict a list of user ids to actual members of the active workspace.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    protected function workspaceUserIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return WorkspaceMember::query()
            ->whereIn('user_id', $ids)
            ->pluck('user_id')
            ->all();
    }

    /**
     * Where a new project lands.
     *
     * A leader picks any unit inside their subtree. Anyone else gets exactly
     * one place — the unit they sit in — so starting a project never reaches
     * outside their own corner of the tree.
     */
    protected function placementUnitId(ProjectStoreRequest $request): ?int
    {
        $member = app(Tenancy::class)->member();

        if ($member === null) {
            return null;
        }

        return $member->managesTeam()
            ? $request->integer('org_unit_id')
            : $member->org_unit_id;
    }

    /**
     * @return array<int, array{id: int, name: string, depth: int}>
     */
    protected function orgUnitOptions(): array
    {
        $viewer = app(Tenancy::class)->member();
        $scopePath = $viewer?->hasFullScope() ? null : $viewer?->scopePath();

        return OrgUnit::query()
            // Someone who leads nobody gets a single entry: their own unit,
            // which is the only place they may start a project.
            ->when(
                $viewer !== null && ! $viewer->managesTeam(),
                fn ($query) => $query->whereKey($viewer->org_unit_id),
                fn ($query) => $query->when(
                    $scopePath !== null,
                    fn ($inner) => $inner->where('path', 'like', $scopePath.'%'),
                ),
            )
            ->orderBy('path')
            ->get(['id', 'name', 'depth'])
            ->map(fn (OrgUnit $unit): array => [
                'id' => $unit->id,
                'name' => $unit->name,
                'depth' => $unit->depth,
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function statusOptions(): array
    {
        return array_map(
            fn (ProjectStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
            ProjectStatus::cases(),
        );
    }

    /**
     * Workspace members who are not on the project yet (PRJ-3).
     *
     * @return array<int, array{id: int, name: string, email: string}>
     */
    protected function memberCandidates(Project $project): array
    {
        $existing = $project->members->pluck('id')->all();

        return WorkspaceMember::query()
            ->with('user:id,name,email')
            ->whereNotIn('user_id', $existing)
            ->get()
            ->map(fn (WorkspaceMember $member): array => [
                'id' => $member->user->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }
}
