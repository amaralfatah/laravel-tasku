<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\MonthWeek;
use Database\Seeders\AmarWorkloadSeeder;
use Database\Seeders\DemoWorkspaceSeeder;

beforeEach(function () {
    $this->seed(DemoWorkspaceSeeder::class);
    $this->seed(AmarWorkloadSeeder::class);

    $this->amar = User::query()->where('email', 'amar@perkebunan.test')->firstOrFail();
});

test('the whole workbook lands on Amar across five applications', function () {
    $tasks = Task::query()->where('assignee_id', $this->amar->id)->get();

    $projects = Project::query()
        ->whereIn('id', $tasks->pluck('project_id')->unique())
        ->pluck('name')
        ->all();

    expect($tasks)->toHaveCount(111)
        ->and($tasks->where('status', TaskStatus::Done))->toHaveCount(110)
        // Offline Mode is the one piece of work still running.
        ->and($tasks->where('progress', 87)->pluck('title')->all())->toBe(['Offline Mode'])
        ->and($projects)->toEqualCanonicalizing([
            'GrowMate',
            'RUP (Rencana Umum Pengadaan)',
            'Superman (Payment)',
            'Support App & Server',
            'PTPN API',
        ]);
});

test('the tree is numbered the way the workbook numbers it', function () {
    $task = Task::query()->where('title', 'Sinkronisasi Data IPS')->firstOrFail();

    expect($task->wbs_number)->toBe('4.1')
        ->and($task->depth)->toBe(1)
        // The seeder widens `W3 06-25` back to a day; reading it as a week has
        // to give the label the workbook started from.
        ->and(MonthWeek::label($task->start_date))->toBe('W3 06-25')
        ->and(MonthWeek::label($task->due_date))->toBe('W4 12-25');
});

test('a third level task keeps its place under two parents', function () {
    $task = Task::query()->where('title', 'Rollout GrowMate di PT SGN')->firstOrFail();

    expect($task->depth)->toBe(1)
        ->and($task->wbs_number)->toBe('8.7')
        ->and(MonthWeek::label($task->due_date))->toBe('W2 07-26');
});

test('running the seeder twice does not duplicate the backlog', function () {
    $this->seed(AmarWorkloadSeeder::class);

    expect(Task::query()->where('assignee_id', $this->amar->id)->count())->toBe(111);
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

    // GrowMate's own tasks, in the order they were started — `1.10` after
    // `1.9`, and the branches sitting between their parents rather than after
    // the whole list.
    expect(array_slice($titles, 0, 6))->toBe([
        '1.1 Auth & Hak Akses',
        '1.2 Data Master',
        '1.3 Pengajuan Transaksi & HPS',
        '1.4 Integrasi IPS',
        '1.5 Deployment',
        '1.6 Dashboard, Laporan & Monitoring',
    ])
        ->and(array_slice($titles, -3))->toBe([
            '1.20 Offline Mode',
            '1.21 Maintenance',
            '1.22 Panduan',
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
        ->and($rows['1.4.1 Sinkronisasi Data IPS'])->toBe(['100%', 'W3 06-25', 'W4 12-25'])
        ->and($rows['1.8.3 Implementasi Modul Pre Reservasi dan Pemupukan pada Komoditi Sawit'])
        ->toBe(['100%', 'W2 02-26', 'W2 02-26'])
        ->and($rows['1.20 Offline Mode'])->toBe(['87%', 'W4 07-26', 'W3 08-26'])
        ->and($rows['2. RUP (Rencana Umum Pengadaan)'][1])->toBe('W2 01-26')
        ->and($rows['4.5.9 IHCMIS Core - Filebrowser & Permission Volume'])
        ->toBe(['100%', 'W2 07-26', 'W4 07-26'])
        ->and($rows['5.7 Ekspor XLSX Master Data'])->toBe(['100%', 'W4 07-26', 'W4 07-26']);
});
