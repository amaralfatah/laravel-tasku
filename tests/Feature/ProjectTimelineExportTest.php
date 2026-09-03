<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\TaskHierarchy;

/**
 * @return array{0: WorkspaceMember, 1: Project}
 */
function timelineExportProject(): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create(['name' => 'GrowMate']);
    $project->members()->attach($member->user_id);

    return [$member, $project];
}

test('the project timeline exports its own workbook', function () {
    [$member, $project] = timelineExportProject();

    $parent = Task::factory()->for($project)->done()->create([
        'title' => 'Auth dan hak akses',
        'assignee_id' => $member->user_id,
        'start_date' => '2026-06-03',
        'due_date' => '2026-08-20',
    ]);

    app(TaskHierarchy::class)->create($project, [
        'title' => 'Login',
        'start_date' => '2026-06-03',
        'due_date' => '2026-06-30',
    ], $parent);

    $response = $this->actingAs($member->user)
        ->withSession(['workspace_id' => $project->workspace_id])
        ->get(route('projects.timeline.export', $project));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $sheet = workbook($response)->getSheet(0);
    $flat = collect($sheet->toArray())->flatten()->filter()->values()->all();

    // The sheet is headed by the project, not by a person, and a sub task
    // still follows its parent.
    expect($sheet->getTitle())->toBe('GrowMate')
        ->and($flat)->toContain('1. GrowMate')
        ->and($flat)->toContain('1.1 Auth dan hak akses')
        ->and($flat)->toContain('1.1.1 Login')
        ->and($flat)->toContain('W1 06-26');
});

test('the project export follows the zoom and the page filters', function () {
    [$member, $project] = timelineExportProject();

    Task::factory()->for($project)->done()->create([
        'title' => 'Auth dan hak akses',
        'assignee_id' => $member->user_id,
        'start_date' => '2026-06-03',
        'due_date' => '2026-08-20',
    ]);

    Task::factory()->for($project)->create([
        'title' => 'Tugas orang lain',
        'assignee_id' => null,
        'start_date' => '2026-06-03',
        'due_date' => '2026-08-20',
    ]);

    $response = $this->actingAs($member->user)
        ->withSession(['workspace_id' => $project->workspace_id])
        ->get(route('projects.timeline.export', [
            'project' => $project,
            'zoom' => 'quarter',
            'assignee_id' => $member->user_id,
        ]));

    $flat = collect(workbook($response)->getSheet(0)->toArray())->flatten()->filter()->values()->all();

    expect($flat)->toContain('Q2 26')
        ->and($flat)->toContain('Q3 26')
        ->and($flat)->not->toContain('1.2 Tugas orang lain');
});

test('someone who may not see the project may not export it', function () {
    [, $project] = timelineExportProject();

    $project->load('orgUnit');
    $sibling = OrgUnit::factory()->childOf($project->orgUnit)->create();

    $stranger = WorkspaceMember::factory()
        ->for(Workspace::find($project->workspace_id))
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $sibling->id]);

    $this->actingAs($stranger->user)
        ->withSession(['workspace_id' => $project->workspace_id])
        ->get(route('projects.timeline.export', $project))
        ->assertForbidden();
});
