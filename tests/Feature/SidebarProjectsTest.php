<?php

use App\Enums\ProjectStatus;
use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{0: WorkspaceMember, 1: OrgUnit}
 */
function sidebarWorkspace(WorkspaceRole $role): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->for($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => $role, 'org_unit_id' => $unit->id]);

    return [$member, $unit];
}

test('the sidebar lists the projects a manager may open, newest first', function () {
    [$member, $unit] = sidebarWorkspace(WorkspaceRole::Bod1);

    Project::factory()->in($unit)->create([
        'name' => 'Panen Lama',
        'updated_at' => now()->subWeek(),
    ]);
    Project::factory()->in($unit)->create([
        'name' => 'Panen Baru',
        'updated_at' => now(),
    ]);
    Project::factory()->in($unit)->create([
        'name' => 'Panen Arsip',
        'status' => ProjectStatus::Archived,
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('members.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tenancy.projects', 2)
            ->where('tenancy.projects.0.name', 'Panen Baru')
            ->where('tenancy.projects.1.name', 'Panen Lama')
        );
});

test('the sidebar hides projects the member does not belong to', function () {
    [$member, $unit] = sidebarWorkspace(WorkspaceRole::Bod4);

    $mine = Project::factory()->in($unit)->create(['name' => 'Punya Saya']);
    $mine->members()->attach($member->user_id);

    Project::factory()->in($unit)->create(['name' => 'Punya Orang Lain']);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('monitoring.me'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tenancy.projects', 1)
            ->where('tenancy.projects.0.name', 'Punya Saya')
        );
});
