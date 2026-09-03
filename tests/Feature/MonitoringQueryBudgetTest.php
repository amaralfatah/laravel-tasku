<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

/**
 * Count the queries one request to the given route takes.
 */
function queriesFor(WorkspaceMember $member, string $url): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get($url)
        ->assertOk();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

test('the person page costs the same whether it shows one task or many', function () {
    // NFR: the page used to ask the database for the same org unit path and the
    // same parent task once per row, so a full workload timed the request out.
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    // A leader, because their permission runs through the org tree: that is the
    // check that used to read the same unit path once per task.
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($unit, WorkspaceRole::Manager)
        ->create();

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $parent = Task::factory()->for($project)->create(['assignee_id' => $member->user_id]);

    $baseline = queriesFor($member, route('monitoring.me'));

    foreach (range(1, 12) as $ignored) {
        Task::factory()->for($project)->create([
            'assignee_id' => $member->user_id,
            'parent_task_id' => $parent->id,
        ]);
    }

    expect(queriesFor($member, route('monitoring.me')))->toBe($baseline);
});

test('the project board costs the same whether it shows one task or many', function () {
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $parent = Task::factory()->for($project)->create(['assignee_id' => $member->user_id]);

    $baseline = queriesFor($member, route('projects.show', $project));

    foreach (range(1, 12) as $ignored) {
        Task::factory()->for($project)->create([
            'assignee_id' => $member->user_id,
            'parent_task_id' => $parent->id,
        ]);
    }

    expect(queriesFor($member, route('projects.show', $project)))->toBe($baseline);
});
