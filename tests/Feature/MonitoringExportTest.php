<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\MonthWeek;
use Illuminate\Support\Carbon;

test('a person export carries their tasks with a week based timeline', function () {
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create(['name' => 'GrowMate']);
    Task::factory()->for($project)->done()->create([
        'title' => 'Auth dan hak akses',
        'assignee_id' => $member->user_id,
        'start_date' => '2026-06-03',
        'due_date' => '2026-08-20',
    ]);

    $response = $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.person.export', $member));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $sheet = workbook($response)->getSheet(0);
    $flat = collect($sheet->toArray())->flatten()->filter()->values()->all();

    expect($sheet->getTitle())->toBe($member->user->name)
        ->and($flat)->toContain($member->user->name)
        ->and($flat)->toContain('1. GrowMate')
        // The task line keeps its WBS number in front of the title, and the
        // start and end columns speak weeks-of-month, not raw dates.
        ->and($flat)->toContain('1.1 Auth dan hak akses')
        ->and($flat)->toContain('W1 06-26')
        ->and($flat)->toContain('W3 08-26');
});

test('the sheet is painted the way the reference workbook is', function () {
    // Grid: Juni 2026 (first scheduled month) through Desember 2026, four
    // columns a month, so F is W1 Juni and AG the last week of Desember.
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create(['name' => 'GrowMate']);
    Task::factory()->for($project)->done()->create([
        'title' => 'Auth dan hak akses',
        'assignee_id' => $member->user_id,
        'start_date' => '2026-06-03',
        'due_date' => '2026-08-20',
    ]);

    $response = $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.person.export', $member));

    $sheet = workbook($response)->getSheet(0);

    $fill = fn (string $ref): string => $sheet->getStyle($ref)->getFill()->getStartColor()->getARGB();

    $taskRow = null;

    foreach ($sheet->toArray(null, true, true, true) as $number => $row) {
        if ($row['B'] === '1.1 Auth dan hak akses') {
            $taskRow = $number;
        }
    }

    expect($taskRow)->not->toBeNull()
        // Green banner over the app list and the summary box beside it.
        ->and($fill('B5'))->toBe('FF00B050')
        ->and($fill('E5'))->toBe('FF00B050')
        // The project line above the task carries the shaded band.
        ->and($fill('B'.($taskRow - 1)))->toBe('FFE8E8E8')
        // Pale bar from W1 Juni, solid cap on W3 Agustus because it is done,
        // then the grey tail past the last month with work in it.
        ->and($fill('F'.$taskRow))->toBe('FFDAF2D0')
        ->and($fill('P'.$taskRow))->toBe('FF4EA72E')
        ->and($fill('R'.$taskRow))->toBe('FFD0D0D0')
        ->and($fill('AG'.$taskRow))->toBe('FFD0D0D0');
});

test('the workspace export gives every visible member a sheet behind a cover', function () {
    $workspace = Workspace::factory()->create(['name' => 'Divisi TI']);
    $root = OrgUnit::factory()->rootOf($workspace)->create();

    $leader = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($root, WorkspaceRole::Bod2)
        ->create();

    $worker = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $root->id]);

    $project = Project::factory()->in($root)->create();
    Task::factory()->for($project)->create(['assignee_id' => $worker->user_id]);

    $response = $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.people.export'));

    $response->assertOk();

    $titles = workbook($response)->getSheetNames();

    expect($titles)->toHaveCount(3)
        ->and($titles[0])->toBe('Cover')
        ->and($titles)->toContain($leader->user->name)
        ->and($titles)->toContain($worker->user->name);
});

test('someone outside the viewer scope cannot be exported', function () {
    $workspace = Workspace::factory()->create();
    $root = OrgUnit::factory()->rootOf($workspace)->create();
    $sibling = OrgUnit::factory()->childOf($root)->create();

    $viewer = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $root->id]);

    $stranger = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $sibling->id]);

    $this->actingAs($viewer->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.person.export', $stranger))
        ->assertForbidden();
});

test('weeks are counted inside their month and capped at four', function () {
    expect(MonthWeek::of(Carbon::parse('2026-06-01')))->toBe(1)
        ->and(MonthWeek::of(Carbon::parse('2026-06-22')))->toBe(4)
        // A fifth week would need a fifth column the grid does not draw.
        ->and(MonthWeek::of(Carbon::parse('2026-06-30')))->toBe(4)
        ->and(MonthWeek::label(Carbon::parse('2026-08-20')))->toBe('W3 08-26')
        ->and(MonthWeek::slot(Carbon::parse('2026-08-20'), Carbon::parse('2026-06-01')))->toBe(10);
});
