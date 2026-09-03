<?php

use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceScale;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\MonthWeek;
use Database\Seeders\AmarWorkloadSeeder;

beforeEach(function () {
    $this->seed(AmarWorkloadSeeder::class);

    $this->amar = User::query()->where('email', 'amar@perkebunan.test')->firstOrFail();
});

test('it makes Amar the owner of a workspace he runs alone', function () {
    $workspace = Workspace::where('name', 'Perkebunan Nusantara')->sole();
    $membership = WorkspaceMember::where('workspace_id', $workspace->id)->sole();

    expect($membership->user_id)->toBe($this->amar->id)
        ->and($membership->role)->toBe(WorkspaceRole::Owner)
        // Nothing is written into the title column, so the tier's own name is
        // what the interface falls back to.
        ->and($membership->title)->toBeNull()
        ->and($membership->positionTitle())->toBe('Pemilik');
});

test('it seeds no hierarchy at all: one node, and the workspace runs it', function () {
    $workspace = Workspace::where('name', 'Perkebunan Nusantara')->sole();
    $membership = WorkspaceMember::where('workspace_id', $workspace->id)->sole();

    $units = OrgUnit::withoutGlobalScopes()->get();

    expect($units)->toHaveCount(1)
        ->and($units->first()->id)->toBe($workspace->root_org_unit_id)
        ->and($units->first()->depth)->toBe(0)
        ->and($units->first()->parent_id)->toBeNull()
        // The owner is placed in that one node, which is their whole scope.
        ->and($membership->org_unit_id)->toBe($workspace->root_org_unit_id);
});

test('it reads as the solo scale, so the team pages stay hidden', function () {
    $workspace = Workspace::where('name', 'Perkebunan Nusantara')->sole();

    expect(WorkspaceScale::of($workspace))->toBe(WorkspaceScale::Solo);
});

test('running it twice neither duplicates the workspace nor the backlog', function () {
    $this->seed(AmarWorkloadSeeder::class);

    expect(Workspace::where('name', 'Perkebunan Nusantara')->count())->toBe(1)
        ->and(WorkspaceMember::where('user_id', $this->amar->id)->count())->toBe(1)
        ->and(OrgUnit::withoutGlobalScopes()->count())->toBe(1)
        ->and(Task::query()->where('assignee_id', $this->amar->id)->count())->toBe(138);
});

test('the whole backlog lands on Amar across five applications', function () {
    $tasks = Task::query()->where('assignee_id', $this->amar->id)->get();

    $projects = Project::query()
        ->whereIn('id', $tasks->pluck('project_id')->unique())
        ->pluck('name')
        ->all();

    // One task was opened without an owner, so the board carries one more.
    expect(Task::query()->count())->toBe(139)
        ->and($tasks)->toHaveCount(138)
        ->and($tasks->where('status', TaskStatus::Done))->toHaveCount(132)
        // What is still running, and how far along it is.
        ->and($tasks->where('progress', '<', 100)->pluck('title')->all())->toEqualCanonicalizing([
            'Video Tutorial',
            'Video Tutorial',
            'Pemupukan Sebagian',
            'Penerimaan Internal',
            'Notifikasi Firebase',
            'Tambah Notifikasi FIrebase',
        ])
        ->and($projects)->toEqualCanonicalizing([
            'GrowMate',
            'RUP (Rencana Umum Pengadaan)',
            'Superman (Payment)',
            'Support App & Server',
            'PTPN API',
        ]);
});

test('a row states its own status where the percentage does not imply it', function () {
    // Work that was started and then reset to nothing: still in progress on
    // the board, at 0%. Deriving the status from the percentage would file it
    // back under To Do and lose that.
    $task = Task::query()
        ->where('title', 'Tambah Notifikasi FIrebase')
        ->where('progress', 0)
        ->firstOrFail();

    expect($task->status)->toBe(TaskStatus::InProgress);
});

test('work nobody has scheduled carries no dates at all', function () {
    $unscheduled = Task::query()->whereNull('start_date')->whereNull('due_date')->get();

    expect($unscheduled)->toHaveCount(22)
        // Never half a schedule: the timeline reads a task with one date as a
        // bar it cannot draw.
        ->and(Task::query()->whereNull('start_date')->whereNotNull('due_date')->count())->toBe(0)
        ->and(Task::query()->whereNotNull('start_date')->whereNull('due_date')->count())->toBe(0);
});

test('the tree is numbered in the order the board holds it', function () {
    $task = Task::query()->where('title', 'Sinkronisasi Data IPS')->firstOrFail();

    expect($task->wbs_number)->toBe('3.1')
        ->and($task->depth)->toBe(1)
        // The seeder widens `W3 06-25` back to a day; reading it as a week has
        // to give the label it started from.
        ->and(MonthWeek::label($task->start_date))->toBe('W3 06-25')
        ->and(MonthWeek::label($task->due_date))->toBe('W4 12-25');
});

test('a sub task keeps its place under its parent', function () {
    $task = Task::query()->where('title', 'Rollout GrowMate di PT SGN')->firstOrFail();

    expect($task->depth)->toBe(1)
        ->and($task->wbs_number)->toBe('7.7')
        ->and(MonthWeek::label($task->due_date))->toBe('W2 07-26');
});

test('the sheet lists every task in tree order, earliest work first', function () {
    $member = WorkspaceMember::query()->where('user_id', $this->amar->id)->firstOrFail();

    $response = $this->actingAs($this->amar)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('monitoring.person.export', $member));

    $titles = collect(workbook($response)->getSheet(0)->toArray(null, true, true, true))
        ->pluck('B')
        ->filter(fn (mixed $title): bool => is_string($title) && preg_match('/^1\.\d+ /', $title) === 1)
        ->values()
        ->all();

    // GrowMate's own tasks, in the order the board holds them — `1.10` after
    // `1.9`, and the branches sitting between their parents rather than after
    // the whole list.
    expect(array_slice($titles, 0, 6))->toBe([
        '1.1 Auth & Hak Akses',
        '1.2 Pengajuan Transaksi & HPS',
        '1.3 Integrasi IPS',
        '1.4 Deployment',
        '1.5 Dashboard, Laporan & Monitoring',
        '1.6 Integrasi SAP/CDS',
    ])
        ->and(array_slice($titles, -3))->toBe([
            '1.25 Perbaikan pasca rollout PALM',
            '1.26 Tambah Notifikasi FIrebase',
            '1.27 Video Tutorial',
        ]);
});

test('the exported sheet reads like the workbook it came from', function () {
    $member = WorkspaceMember::query()->where('user_id', $this->amar->id)->firstOrFail();

    $response = $this->actingAs($this->amar)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('monitoring.person.export', $member));

    $response->assertOk();

    $rows = collect(workbook($response)->getSheet(0)->toArray(null, true, true, true))
        ->filter(fn (array $row): bool => is_string($row['B']) && $row['B'] !== '')
        ->mapWithKeys(fn (array $row): array => [$row['B'] => [$row['C'], $row['D'], $row['E']]])
        ->all();

    expect($rows)->toHaveKey('1. GrowMate')
        ->and($rows['1.1 Auth & Hak Akses'])->toBe(['100%', 'W1 06-25', 'W4 06-25'])
        ->and($rows['1.3.1 Sinkronisasi Data IPS'])->toBe(['100%', 'W3 06-25', 'W4 12-25'])
        ->and($rows['1.7.3 Implementasi Modul Pre Reservasi dan Pemupukan pada Komoditi Sawit'])
        ->toBe(['100%', 'W2 02-26', 'W2 02-26'])
        ->and($rows['1.21 Offline Mode'])->toBe(['100%', 'W4 07-26', 'W3 08-26'])
        ->and($rows['2. RUP (Rencana Umum Pengadaan)'][1])->toBe('W2 01-26')
        ->and($rows['4.5.9 IHCMIS Core - Filebrowser & Permission Volume'])
        ->toBe(['100%', 'W2 07-26', 'W4 07-26'])
        ->and($rows['5.7 Ekspor XLSX Master Data'])->toBe(['100%', 'W4 07-26', 'W4 07-26']);
});
