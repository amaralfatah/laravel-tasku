<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Database\Seeders\DemoWorkspaceSeeder;

it('seeds the demo workspace with people only', function () {
    $this->seed(DemoWorkspaceSeeder::class);

    $workspace = Workspace::where('name', 'Perkebunan Nusantara')->sole();

    expect(WorkspaceMember::where('workspace_id', $workspace->id)->count())->toBe(3)
        ->and(Project::where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(Task::where('workspace_id', $workspace->id)->count())->toBe(0);
});

it('leaves Amar as the only programmer', function () {
    $this->seed(DemoWorkspaceSeeder::class);

    $amar = User::where('email', 'amar@perkebunan.test')->sole();

    expect(WorkspaceMember::where('user_id', $amar->id)->sole()->role)->toBe(WorkspaceRole::Member)
        ->and(WorkspaceMember::where('role', WorkspaceRole::Member)->count())->toBe(1);
});
