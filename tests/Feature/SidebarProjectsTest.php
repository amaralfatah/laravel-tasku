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
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => $role, 'org_unit_id' => $unit->id]);

    return [$member, $unit];
}

test('the sidebar lists the projects a manager may open, by name', function () {
    [$member, $unit] = sidebarWorkspace(WorkspaceRole::Owner);

    // Recency runs against the alphabet here on purpose: a sidebar that
    // reshuffles as projects are touched costs the reader their bearings.
    Project::factory()->in($unit)->create([
        'name' => 'Aneka Panen',
        'updated_at' => now()->subWeek(),
    ]);
    Project::factory()->in($unit)->create([
        'name' => 'Zona Panen',
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
            ->where('tenancy.projects.0.name', 'Aneka Panen')
            ->where('tenancy.projects.1.name', 'Zona Panen')
            ->missing('tenancy.projects.0.status')
        );
});

test('the sidebar drops a project finished a month ago and keeps a fresh one', function () {
    [$member, $unit] = sidebarWorkspace(WorkspaceRole::Owner);

    Project::factory()->in($unit)->create(['name' => 'Berjalan']);

    $lastWeek = Project::factory()->in($unit)->create([
        'name' => 'Baru Selesai',
        'status' => ProjectStatus::Completed,
    ]);
    $lastYear = Project::factory()->in($unit)->create([
        'name' => 'Lama Selesai',
        'status' => ProjectStatus::Completed,
    ]);

    // The stamp is the model's to write, so the ages are backdated onto it
    // rather than passed in — a completed project is stamped `now()` on save.
    $lastWeek->forceFill(['completed_at' => now()->subWeek()])->saveQuietly();
    $lastYear->forceFill(['completed_at' => now()->subYear()])->saveQuietly();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('members.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tenancy.projects', 2)
            ->where('tenancy.projects.0.name', 'Baru Selesai')
            ->where('tenancy.projects.1.name', 'Berjalan')
        );
});

test('the sidebar still carries a long finished project while it is open', function () {
    [$member, $unit] = sidebarWorkspace(WorkspaceRole::Owner);

    $project = Project::factory()->in($unit)->create([
        'name' => 'Lama Selesai',
        'status' => ProjectStatus::Completed,
    ]);
    $project->forceFill(['completed_at' => now()->subYear()])->saveQuietly();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tenancy.projects', 1)
            ->where('tenancy.projects.0.id', $project->id)
        );
});

test('finishing a project stamps it, and reopening clears the stamp', function () {
    [$member, $unit] = sidebarWorkspace(WorkspaceRole::Owner);

    $project = Project::factory()->in($unit)->create(['name' => 'Panen']);

    expect($project->completed_at)->toBeNull();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('projects.update', $project), [
            'status' => ProjectStatus::Completed->value,
        ])
        ->assertRedirect();

    expect($project->refresh()->completed_at)->not->toBeNull();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('projects.update', $project), [
            'status' => ProjectStatus::Active->value,
        ])
        ->assertRedirect();

    expect($project->refresh()->completed_at)->toBeNull();
});

test('the sidebar carries the open project even when the limit left it out', function () {
    [$member, $unit] = sidebarWorkspace(WorkspaceRole::Owner);

    foreach (['Alfa', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'] as $name) {
        Project::factory()->in($unit)->create(['name' => $name]);
    }

    $current = Project::factory()->in($unit)->create(['name' => 'Zulu']);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.show', $current))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tenancy.projects', 7)
            ->where('tenancy.projects.6.id', $current->id)
        );
});

test('the sidebar does not smuggle in a project the member may not open', function () {
    [$member, $unit] = sidebarWorkspace(WorkspaceRole::Member);

    $mine = Project::factory()->in($unit)->create(['name' => 'Punya Saya']);
    $mine->members()->attach($member->user_id);

    $theirs = Project::factory()->in($unit)->create(['name' => 'Punya Orang Lain']);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.show', $theirs))
        ->assertForbidden();
});

test('the sidebar hides projects the member does not belong to', function () {
    [$member, $unit] = sidebarWorkspace(WorkspaceRole::Member);

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
