<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * Renaming the workspace one runs.
 *
 * The operator keeps where a workspace sits — its root unit, its holding, its
 * activation. What it is called is the customer's, so an Owner never files a
 * ticket to fix their own company name.
 */
test('the owner of a solo workspace renames it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('workspace.start'), ['name' => 'Studio Rekayasa']);

    $this->actingAs($user)
        ->get(route('workspace.settings.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/workspace')
            ->where('workspace.name', 'Studio Rekayasa')
            ->where('tenancy.workspace.scale', 'solo')
        );

    $this->actingAs($user)
        ->patch(route('workspace.settings.update'), ['name' => 'Studio Rekayasa Nusantara'])
        ->assertRedirect(route('workspace.settings.edit'));

    $workspace = Workspace::query()->where('slug', 'studio-rekayasa')->firstOrFail();

    expect($workspace->name)->toBe('Studio Rekayasa Nusantara')
        // The self-serve root was named after the workspace, so it follows it.
        ->and(OrgUnit::withoutGlobalScopes()->whereKey($workspace->root_org_unit_id)->value('name'))
        ->toBe('Studio Rekayasa Nusantara');
});

test('a root the operator imported from SAP keeps its own name', function () {
    $workspace = Workspace::factory()->create(['name' => 'PTPN III']);
    $root = OrgUnit::factory()->rootOf($workspace)->create([
        'name' => 'PTPN III',
        'external_id' => '10000001',
    ]);

    $owner = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($root, WorkspaceRole::Owner)
        ->create();

    $this->actingAs($owner->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(route('workspace.settings.update'), ['name' => 'PTPN III Persero'])
        ->assertRedirect(route('workspace.settings.edit'));

    expect($workspace->refresh()->name)->toBe('PTPN III Persero')
        ->and(OrgUnit::withoutGlobalScopes()->whereKey($root->id)->value('name'))->toBe('PTPN III');
});

test('a manager runs a branch, not the entity, so cannot rename it', function () {
    $workspace = Workspace::factory()->create(['name' => 'Perkebunan']);
    $root = OrgUnit::factory()->rootOf($workspace)->create(['name' => 'Perkebunan']);
    $division = OrgUnit::factory()->childOf($root)->create(['name' => 'Divisi Tanaman']);

    $manager = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($division)
        ->create();

    $this->actingAs($manager->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('workspace.settings.edit'))
        ->assertForbidden();

    $this->actingAs($manager->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(route('workspace.settings.update'), ['name' => 'Perkebunan Baru'])
        ->assertForbidden();

    expect($workspace->refresh()->name)->toBe('Perkebunan');
});

test('the rename form carries the name alone, never the operator fields', function () {
    $holding = Workspace::factory()->create(['name' => 'Induk']);
    $workspace = Workspace::factory()->create(['name' => 'Anak', 'is_active' => true]);
    $root = OrgUnit::factory()->rootOf($workspace)->create(['name' => 'Anak']);
    $elsewhere = OrgUnit::factory()->create(['name' => 'Unit Lain']);

    $owner = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($root, WorkspaceRole::Owner)
        ->create();

    $this->actingAs($owner->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(route('workspace.settings.update'), [
            'name' => 'Anak Sejahtera',
            'parent_id' => $holding->id,
            'root_org_unit_id' => $elsewhere->id,
            'is_active' => false,
        ])
        ->assertRedirect(route('workspace.settings.edit'));

    $workspace->refresh();

    expect($workspace->name)->toBe('Anak Sejahtera')
        ->and($workspace->parent_id)->toBeNull()
        ->and($workspace->root_org_unit_id)->toBe($root->id)
        ->and($workspace->is_active)->toBeTrue();
});

test('the owner uploads a logo, and replacing it clears the file it replaced', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user)->post(route('workspace.start'), ['name' => 'Studio Rekayasa']);

    $this->actingAs($user)->patch(route('workspace.settings.update'), [
        'name' => 'Studio Rekayasa',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ])->assertRedirect(route('workspace.settings.edit'));

    $workspace = Workspace::query()->where('slug', 'studio-rekayasa')->firstOrFail();
    $first = $workspace->logo_path;

    expect($first)->not->toBeNull();
    Storage::disk('public')->assertExists($first);

    $this->actingAs($user)->patch(route('workspace.settings.update'), [
        'name' => 'Studio Rekayasa',
        'logo' => UploadedFile::fake()->image('logo-baru.png'),
    ]);

    $second = $workspace->refresh()->logo_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    // The URL the sidebar reads comes off the same column.
    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.workspace.logo', Storage::disk('public')->url($second))
        );
});

test('removing the logo drops the file and the workspace goes by its name again', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user)->post(route('workspace.start'), ['name' => 'Studio Rekayasa']);

    $this->actingAs($user)->patch(route('workspace.settings.update'), [
        'name' => 'Studio Rekayasa',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $workspace = Workspace::query()->where('slug', 'studio-rekayasa')->firstOrFail();
    $path = $workspace->logo_path;

    $this->actingAs($user)->patch(route('workspace.settings.update'), [
        'name' => 'Studio Rekayasa',
        'remove_logo' => '1',
    ])->assertRedirect(route('workspace.settings.edit'));

    expect($workspace->refresh()->logo_path)->toBeNull()
        ->and($workspace->logo)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('a solo owner still reaches the roster, which is where the second person comes from', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('workspace.start'), ['name' => 'Freelance Saya']);

    $this->actingAs($user)->get(route('members.index'))->assertOk();
});
