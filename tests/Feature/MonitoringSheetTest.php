<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

/**
 * The person page draws the workbook rather than a view of its own, so the
 * numbering on screen has to be the numbering in the file. Both read the same
 * collection and number each block by its position, which only holds while the
 * page keeps the order the query put the blocks in.
 */
test('the person page numbers its projects the way the export does', function () {
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    // Named so that alphabetical order is the reverse of creation order: a
    // page sorted by name would renumber both blocks against the file.
    $first = Project::factory()->in($unit)->create(['name' => 'Zebra']);
    $second = Project::factory()->in($unit)->create(['name' => 'Alpha']);

    foreach ([$first, $second] as $project) {
        Task::factory()->for($project)->create([
            'title' => 'Tugas '.$project->name,
            'assignee_id' => $member->user_id,
            'start_date' => '2026-06-03',
            'due_date' => '2026-08-20',
        ]);
    }

    $this->actingAs($member->user)->withSession(['workspace_id' => $workspace->id]);

    $this->get(route('monitoring.person', $member))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('monitoring/person')
            ->where('tasks.0.project.name', 'Zebra')
            ->where('tasks.1.project.name', 'Alpha'));

    $response = $this->get(route('monitoring.person.export', $member));
    $flat = collect(workbook($response)->getSheet(0)->toArray())->flatten()->filter()->values()->all();

    expect($flat)->toContain('1. Zebra')
        ->and($flat)->toContain('2. Alpha')
        ->and($flat)->toContain('1.1 Tugas Zebra')
        ->and($flat)->toContain('2.1 Tugas Alpha');
});
