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
use Database\Seeders\ArvinoWorkloadSeeder;

beforeEach(function () {
    $this->seed(AmarWorkloadSeeder::class);

    // The account is Arvino's own; the seeder finds it, it never opens it.
    $this->arvino = User::factory()->create(['name' => 'Arvino', 'email' => 'arvinoart@gmail.com']);

    $this->seed(ArvinoWorkloadSeeder::class);
});

test('Arvino joins the workspace Amar runs rather than opening a second one', function () {
    $workspace = Workspace::where('slug', 'perkebunan-nusantara')->sole();
    $membership = WorkspaceMember::where('user_id', $this->arvino->id)->sole();

    expect(Workspace::query()->count())->toBe(1)
        ->and($membership->workspace_id)->toBe($workspace->id)
        ->and($membership->role)->toBe(WorkspaceRole::Member)
        // The one node of the org tree is everybody's scope; the workbook
        // records no ladder between the two of them.
        ->and($membership->org_unit_id)->toBe($workspace->root_org_unit_id)
        ->and(OrgUnit::withoutGlobalScopes()->count())->toBe(1);
});

test('a second member tips the workspace out of the solo scale', function () {
    $workspace = Workspace::where('slug', 'perkebunan-nusantara')->sole();

    expect(WorkspaceScale::of($workspace))->toBe(WorkspaceScale::Team);
});

test('it opens no account of its own when the address is not registered', function () {
    WorkspaceMember::query()->withoutGlobalScopes()->where('user_id', $this->arvino->id)->delete();
    $this->arvino->delete();

    $before = Task::query()->withoutGlobalScopes()->count();

    $this->seed(ArvinoWorkloadSeeder::class);

    expect(User::query()->where('email', 'arvinoart@gmail.com')->exists())->toBeFalse()
        ->and(Task::query()->withoutGlobalScopes()->count())->toBe($before);
});

test('running it twice neither duplicates the membership nor the backlog', function () {
    $this->seed(ArvinoWorkloadSeeder::class);

    expect(WorkspaceMember::where('user_id', $this->arvino->id)->count())->toBe(1)
        ->and(Task::query()->where('assignee_id', $this->arvino->id)->count())->toBe(250);
});

test('the whole backlog lands on Arvino across fourteen applications', function () {
    $tasks = Task::query()->where('assignee_id', $this->arvino->id)->get();

    $projects = Project::query()
        ->whereIn('id', $tasks->pluck('project_id')->unique())
        ->pluck('name')
        ->all();

    expect($tasks)->toHaveCount(250)
        ->and($tasks->where('status', TaskStatus::Done))->toHaveCount(174)
        ->and($tasks->where('status', TaskStatus::InProgress))->toHaveCount(76)
        // Everything on this sheet is scheduled; the workbook leaves no row
        // without both a start and an end.
        ->and($tasks->whereNull('start_date'))->toHaveCount(0)
        ->and($tasks->whereNull('due_date'))->toHaveCount(0)
        ->and($projects)->toEqualCanonicalizing([
            'Google Cloud Platform',
            'Dashboard RAGAB',
            'Aplikasi Content Boardroom',
            'Aplikasi Pica Boardroom',
            'Aplikasi OnePTPN Hub',
            'Migrasi & Integrasi Data',
            'Dashboard AnLab',
            'Dashboard SGN',
            'Integrasi SAP ke Redshift',
            'Aplikasi Boardroom',
            'Update Login AGHRIS - Website Boardroom',
            'Aplikasi SSO',
            'Laporan Harian Produksi Kelapa Sawit',
            'Aplikasi AI Assistant "Sri" (Realtime Voice)',
        ]);
});

test('the workbook numbering survives, three levels deep', function () {
    // `5.7.3.1` on the sheet: the application is the project, so the task tree
    // under it starts one number shorter.
    $task = Task::query()
        ->where('title', 'Perubahan Dokumen Saat Proses Persetujuan')
        ->firstOrFail();

    expect($task->wbs_number)->toBe('7.3.1')
        ->and($task->depth)->toBe(2)
        ->and($task->progress)->toBe(90)
        ->and($task->status)->toBe(TaskStatus::InProgress)
        // The seeder widens `W3 05-26` back to a day; reading it as a week has
        // to give the label it started from.
        ->and(MonthWeek::label($task->start_date))->toBe('W3 05-26')
        ->and(MonthWeek::label($task->due_date))->toBe('W4 06-26');
});

test('the two backlogs stay apart on the board they share', function () {
    $amar = User::query()->where('email', 'amar@perkebunan.test')->firstOrFail();

    expect(Task::query()->where('assignee_id', $amar->id)->count())->toBe(138)
        ->and(Task::query()->where('assignee_id', $this->arvino->id)->count())->toBe(250);
});

test('the exported sheet reads like the workbook it came from', function () {
    $member = WorkspaceMember::query()->where('user_id', $this->arvino->id)->firstOrFail();

    $response = $this->actingAs($this->arvino)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('monitoring.person.export', $member));

    $response->assertOk();

    $rows = collect(workbook($response)->getSheet(0)->toArray(null, true, true, true))
        ->filter(fn (array $row): bool => is_string($row['B']) && $row['B'] !== '')
        ->mapWithKeys(fn (array $row): array => [$row['B'] => [$row['C'], $row['D'], $row['E']]])
        ->all();

    expect($rows)->toHaveKey('1. Google Cloud Platform')
        ->and($rows['1.1 Pembuatan VM'])->toBe(['100%', 'W1 07-25', 'W3 07-25'])
        ->and($rows['2.1.1 Identifikasi Data'])->toBe(['100%', 'W1 07-25', 'W2 07-25'])
        ->and($rows['5.7.3.1 Perubahan Dokumen Saat Proses Persetujuan'])
        ->toBe(['90%', 'W3 05-26', 'W4 06-26'])
        ->and($rows['10.7.2 Logika & Dokumentasi Kontrol Akses Registrasi Admin'])
        ->toBe(['50%', 'W4 08-26', 'W4 08-26'])
        ->and($rows['14.6.1.1 Fungsi Keuangan PTPN III Data Kumulatif s/d Juli 2026'])
        ->toBe(['100%', 'W1 09-26', 'W1 09-26']);
});
