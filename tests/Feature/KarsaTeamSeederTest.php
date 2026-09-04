<?php

use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceScale;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Requester;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Database\Seeders\KarsaTeamSeeder;

beforeEach(function () {
    $this->seed(KarsaTeamSeeder::class);

    $this->workspace = Workspace::where('name', 'Karsa Studio')->sole();
    $this->members = WorkspaceMember::where('workspace_id', $this->workspace->id)
        ->with('user')
        ->get()
        ->keyBy('user.email');
});

test('it seeds a studio of six, leaders first and everyone reporting to somebody', function () {
    expect($this->members)->toHaveCount(6)
        ->and($this->members['rani@karsa.test']->role)->toBe(WorkspaceRole::Owner)
        ->and($this->members['bagas@karsa.test']->role)->toBe(WorkspaceRole::Manager)
        ->and($this->members['yoga@karsa.test']->role)->toBe(WorkspaceRole::Viewer)
        // A roster of six needs the job title the solo workspace does without.
        ->and($this->members['bagas@karsa.test']->positionTitle())->toBe('Engineering Lead')
        // The reporting line is `manager_id`, not the org chart.
        ->and($this->members['rani@karsa.test']->manager_id)->toBeNull()
        ->and($this->members['dewi@karsa.test']->manager_id)->toBe($this->members['bagas@karsa.test']->id)
        ->and($this->members['bagas@karsa.test']->manager_id)->toBe($this->members['rani@karsa.test']->id);
});

test('the tree stays one node, so the workspace reads as the team scale', function () {
    $units = OrgUnit::withoutGlobalScopes()->get();

    expect($units)->toHaveCount(1)
        ->and($units->first()->id)->toBe($this->workspace->root_org_unit_id)
        ->and($units->first()->depth)->toBe(0)
        // Everyone sits in that one node, the Manager included: their scope is
        // the whole studio, which is what keeps this off the company scale.
        ->and($this->members->pluck('org_unit_id')->unique()->all())->toBe([$this->workspace->root_org_unit_id])
        ->and(WorkspaceScale::of($this->workspace))->toBe(WorkspaceScale::Team);
});

test('the requester list is the leader\'s, and a retired one keeps their tasks', function () {
    $requesters = Requester::withoutGlobalScopes()->get()->keyBy('name');

    expect($requesters)->toHaveCount(5)
        ->and($requesters->where('is_active', true))->toHaveCount(4)
        // Who added the row, which is never who asked for the work.
        ->and($requesters->pluck('created_by')->unique()->all())
        ->toBe([$this->members['rani@karsa.test']->user_id])
        // Three of the four active ones are outside the studio, which is why a
        // user picker could not hold this column.
        ->and($requesters['Larasati Widodo']->organization)->toBe('Dinas Pariwisata Kota Malang')
        ->and($requesters['Danu Kurniawan']->is_active)->toBeFalse()
        ->and(Task::query()->where('requester_id', $requesters['Danu Kurniawan']->id)->count())->toBe(1);
});

test('the backlog is shared out over three projects', function () {
    $tasks = Task::query()->get();

    $projects = Project::query()->pluck('name')->all();

    $perAssignee = $tasks->groupBy('assignee_id')->map->count();

    expect($tasks)->toHaveCount(33)
        ->and($projects)->toEqualCanonicalizing([
            'Sinar Rejeki Commerce',
            'Wisata Malang',
            'Karsa Internal',
        ])
        // Five people carry work; the client's Viewer carries none.
        ->and($perAssignee[$this->members['nadia@karsa.test']->user_id])->toBe(8)
        ->and($perAssignee[$this->members['faisal@karsa.test']->user_id])->toBe(6)
        ->and($perAssignee[$this->members['dewi@karsa.test']->user_id])->toBe(5)
        ->and($perAssignee[$this->members['bagas@karsa.test']->user_id])->toBe(4)
        ->and($perAssignee[$this->members['rani@karsa.test']->user_id])->toBe(3)
        ->and($perAssignee->has($this->members['yoga@karsa.test']->user_id))->toBeFalse()
        // The client sees the board and is on it as a member, changing nothing.
        ->and($this->members['yoga@karsa.test']->canWrite())->toBeFalse();
});

test('a parent carries the percentage of its sub tasks and the status somebody set', function () {
    $parents = Task::query()->whereNull('parent_task_id')->get()->keyBy('title');

    $keranjang = $parents['Keranjang & Checkout'];

    expect($parents['Riset & Alur Belanja']->progress)->toBe(100)
        ->and($parents['Desain Antarmuka']->progress)->toBe(80)
        // 100, 55 and 0, rounded the way the rollup rounds.
        ->and($keranjang->progress)->toBe(52)
        ->and($parents['Peta & Titik Wisata']->progress)->toBe(77)
        ->and($parents['Situs Profil Studio']->progress)->toBe(50)
        // Never derived: a branch at 100% is still whatever column it was put
        // in, so the seeder states it.
        ->and($parents['Riset & Alur Belanja']->status)->toBe(TaskStatus::Done)
        ->and($keranjang->status)->toBe(TaskStatus::InProgress);
});

test('the board has the rows the team pages are there to surface', function () {
    $branches = Task::query()->whereNull('assignee_id')->whereHas('children')->count();

    expect(Task::query()->whereNull('assignee_id')->doesntHave('children')->pluck('title')->all())
        // Five of the seven unowned rows are branches, which nobody works
        // directly; two are real work nobody has picked up.
        ->toEqualCanonicalizing(['Integrasi kurir & ongkir', 'Terjemahan Bahasa Inggris'])
        ->and($branches)->toBe(5)
        ->and(Task::query()->where('status', TaskStatus::Review)->pluck('title')->all())
        ->toEqualCanonicalizing(['Desain checkout & pembayaran tempo', 'Kalender Acara Kota'])
        // Late work: past its due date and still open.
        ->and(
            Task::query()
                ->whereDate('due_date', '<', now())
                ->whereNotIn('status', [TaskStatus::Done, TaskStatus::Review])
                ->pluck('title')
                ->all()
        )->toEqualCanonicalizing([
            'Keranjang & Checkout',
            'Pembayaran tempo 30 hari',
            'Panel Admin Dinas',
            // Internal work that slipped: the studio's own case study is the
            // half of this branch nobody has scheduled.
            'Situs Profil Studio',
        ]);
});

test('work nobody has scheduled carries no dates at all', function () {
    $unscheduled = Task::query()->whereNull('start_date')->whereNull('due_date')->pluck('title')->all();

    expect($unscheduled)->toEqualCanonicalizing([
        'Integrasi kurir & ongkir',
        'Terjemahan Bahasa Inggris',
        'Studi kasus Sinar Rejeki',
    ])
        // Never half a schedule: the timeline reads a task with one date as a
        // bar it cannot draw.
        ->and(Task::query()->whereNull('start_date')->whereNotNull('due_date')->count())->toBe(0)
        ->and(Task::query()->whereNotNull('start_date')->whereNull('due_date')->count())->toBe(0);
});

test('running it twice neither duplicates the studio nor the backlog', function () {
    $this->seed(KarsaTeamSeeder::class);

    expect(Workspace::where('name', 'Karsa Studio')->count())->toBe(1)
        ->and(User::where('email', 'like', '%@karsa.test')->count())->toBe(6)
        ->and(WorkspaceMember::where('workspace_id', $this->workspace->id)->count())->toBe(6)
        ->and(OrgUnit::withoutGlobalScopes()->count())->toBe(1)
        ->and(Requester::withoutGlobalScopes()->count())->toBe(5)
        ->and(Task::query()->count())->toBe(33);
});
