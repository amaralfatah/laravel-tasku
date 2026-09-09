<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\WorkspaceMember;
use App\Services\Ai\PlanScope;
use App\Services\Ai\TaskPlanApplier;
use App\Services\Ai\TaskPlanner;
use App\Services\Ai\TextModels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * Task CRUD from a sentence, planned by whichever model the person picked.
 *
 * Two steps on purpose. `plan()` only asks the model what it would do and
 * answers with JSON for the dialog to render; nothing is written until the
 * person reads the list and posts it back to `apply()`. Deleting a task takes
 * its whole subtree with it, which is not a thing to hand to a guess at what a
 * sentence meant.
 */
class AiTaskController extends Controller
{
    public function __construct(
        protected TaskPlanner $planner,
        protected TaskPlanApplier $applier,
        protected TextModels $models,
    ) {}

    /**
     * Ask for a plan. Answers JSON, not an Inertia visit — the dialog reads it
     * with `useHttp` and shows it for confirmation.
     */
    public function plan(Request $request, Project $project): JsonResponse
    {
        $this->authorize('contribute', $project);

        return $this->planWithin($request, PlanScope::forProject($project));
    }

    /**
     * The same assistant on a monitoring page, where the conversation is about
     * one person's work across every project rather than about one project.
     */
    public function planForMember(Request $request, WorkspaceMember $member): JsonResponse
    {
        $this->authorize('viewMember', $member);

        return $this->planWithin($request, PlanScope::forAssignee($member->user_id));
    }

    protected function planWithin(Request $request, PlanScope $scope): JsonResponse
    {
        $this->guardEnabled();

        $validated = $request->validate([
            'instruction' => ['required', 'string', 'max:2000'],
            // The person picks the model in the chat. A model that is not on
            // offer here is not one this deployment can run.
            'model' => ['required', 'string', Rule::in(array_column($this->models->options(), 'value'))],
            // The turns before this one. The server keeps no conversation of
            // its own — the panel sends back what it is showing, so "ubah yang
            // tadi jadi Senin" has something to refer to.
            'history' => ['sometimes', 'array', 'max:10'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $plan = $this->planner->plan(
                $scope,
                $request->user(),
                $validated['instruction'],
                $this->models->make($validated['model']),
                $validated['history'] ?? [],
            );
        } catch (RuntimeException $exception) {
            // The CLI being absent, timing out or answering nonsense is not a
            // server fault the user can do anything with as a 500 — it belongs
            // in the dialog next to the sentence they typed.
            throw ValidationException::withMessages([
                'instruction' => $exception->getMessage(),
            ]);
        }

        return response()->json($plan);
    }

    /**
     * Carry out a plan the user confirmed.
     */
    public function apply(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('contribute', $project);

        return $this->applyWithin($request, PlanScope::forProject($project));
    }

    /**
     * Carry out a plan made on a monitoring page.
     *
     * Seeing somebody's work is not permission to change it: every operation
     * is still authorized task by task, and a new one only lands in a project
     * this user may contribute to.
     */
    public function applyForMember(Request $request, WorkspaceMember $member): RedirectResponse
    {
        $this->authorize('viewMember', $member);

        return $this->applyWithin($request, PlanScope::forAssignee($member->user_id));
    }

    protected function applyWithin(Request $request, PlanScope $scope): RedirectResponse
    {
        $this->guardEnabled();

        $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:50'],
            'operations.*.op' => ['required', Rule::in(['create', 'update', 'delete'])],
        ]);

        // Read the raw operations rather than `validated()`: the rules above
        // only name `op`, so the validated copy would arrive stripped of every
        // field the applier is about to check for itself.
        $counts = $this->applier->apply($scope, $request->array('operations'), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->summarise($counts),
        ]);

        return back();
    }

    /**
     * @param  array{created: int, updated: int, deleted: int}  $counts
     */
    protected function summarise(array $counts): string
    {
        $parts = array_filter([
            $counts['created'] > 0 ? "{$counts['created']} task dibuat" : null,
            $counts['updated'] > 0 ? "{$counts['updated']} task diperbarui" : null,
            $counts['deleted'] > 0 ? "{$counts['deleted']} task dihapus" : null,
        ]);

        return ucfirst(implode(', ', $parts)).'.';
    }

    /**
     * A deployment with no model on offer — the CLI absent, no Gemini key, or
     * the whole thing switched off — has no assistant at all, so the routes
     * are a 404 there rather than an error (see config/ai.php).
     */
    protected function guardEnabled(): void
    {
        abort_if($this->models->options() === [], 404);
    }
}
