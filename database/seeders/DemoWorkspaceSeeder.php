<?php

namespace Database\Seeders;

use App\Enums\ScopeType;
use App\Enums\TaskPriority;
use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Position;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\OrgUnitTree;
use App\Services\TaskHierarchy;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A worked example of the whole model: nested org units, positions, members
 * with different scopes, projects, and a four level task tree.
 *
 * Every account uses the password `password`.
 */
class DemoWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $tenancy = app(Tenancy::class);
        $tree = app(OrgUnitTree::class);
        $hierarchy = app(TaskHierarchy::class);

        $workspace = Workspace::create(['name' => 'PT Nusantara Digital']);
        $tenancy->set($workspace);

        $units = $this->seedUnits($tree);
        $positions = $this->seedPositions();
        $people = $this->seedMembers($workspace, $units, $positions, $tenancy);

        $this->seedProjects($units, $people, $hierarchy);

        $tenancy->forget();

        $this->command->info("Workspace {$workspace->name} dibuat. Login dengan salah satu email di bawah, kata sandi: password");
        $this->command->table(
            ['Nama', 'Email', 'Role', 'Cakupan'],
            [
                ['Owner', 'owner@nusantara.test', 'owner', 'seluruh workspace'],
                ['Kepala Engineering', 'kepala.eng@nusantara.test', 'member', 'subtree Engineering'],
                ['Lead Backend', 'lead.be@nusantara.test', 'member', 'project yang diikuti'],
                ['Programmer', 'dev1@nusantara.test', 'member', 'project yang diikuti'],
                ['Programmer', 'dev2@nusantara.test', 'member', 'project yang diikuti'],
            ],
        );
    }

    /**
     * @return array<string, OrgUnit>
     */
    protected function seedUnits(OrgUnitTree $tree): array
    {
        $engineering = $tree->create(['name' => 'Engineering', 'type' => 'division']);
        $backend = $tree->create(['name' => 'Backend', 'type' => 'sub_division'], $engineering);
        $frontend = $tree->create(['name' => 'Frontend', 'type' => 'sub_division'], $engineering);
        $qa = $tree->create(['name' => 'QA', 'type' => 'sub_division'], $engineering);
        $marketing = $tree->create(['name' => 'Marketing', 'type' => 'division']);

        return compact('engineering', 'backend', 'frontend', 'qa', 'marketing');
    }

    /**
     * @return array<string, Position>
     */
    protected function seedPositions(): array
    {
        return [
            'head' => Position::create(['name' => 'Kepala Divisi', 'level' => 1]),
            'lead' => Position::create(['name' => 'Lead', 'level' => 2]),
            'programmer' => Position::create(['name' => 'Programmer', 'level' => 3]),
        ];
    }

    /**
     * @param  array<string, OrgUnit>  $units
     * @param  array<string, Position>  $positions
     * @return array<string, User>
     */
    protected function seedMembers(Workspace $workspace, array $units, array $positions, Tenancy $tenancy): array
    {
        $owner = $this->seedMember($workspace, 'Sari Owner', 'owner@nusantara.test', WorkspaceRole::Owner);

        $people = [
            'owner' => $owner['user'],
            // Given the whole Engineering subtree to monitor, read-only.
            'head' => $this->seedMember(
                $workspace,
                'Bagas Kepala Engineering',
                'kepala.eng@nusantara.test',
                WorkspaceRole::Member,
                $positions['head'],
                $units['engineering'],
                $units['engineering'],
            )['user'],
            'lead' => $this->seedMember(
                $workspace,
                'Rina Lead Backend',
                'lead.be@nusantara.test',
                WorkspaceRole::Member,
                $positions['lead'],
                $units['backend'],
            )['user'],
            'dev1' => $this->seedMember(
                $workspace,
                'Andi Pratama',
                'dev1@nusantara.test',
                WorkspaceRole::Member,
                $positions['programmer'],
                $units['backend'],
            )['user'],
            'dev2' => $this->seedMember(
                $workspace,
                'Maya Sari',
                'dev2@nusantara.test',
                WorkspaceRole::Member,
                $positions['programmer'],
                $units['frontend'],
            )['user'],
        ];

        // The owner's membership drives creator attribution below.
        $tenancy->set($workspace, $owner['membership']);

        return $people;
    }

    /**
     * @return array{user: User, membership: WorkspaceMember}
     */
    protected function seedMember(
        Workspace $workspace,
        string $name,
        string $email,
        WorkspaceRole $role,
        ?Position $position = null,
        ?OrgUnit $unit = null,
        ?OrgUnit $scopeUnit = null,
    ): array {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'position_id' => $position?->id,
            'org_unit_id' => $unit?->id,
            'scope_type' => $scopeUnit === null ? ScopeType::ProjectOnly : ScopeType::UnitSubtree,
            'scope_org_unit_id' => $scopeUnit?->id,
            'joined_at' => now(),
        ]);

        return ['user' => $user, 'membership' => $membership];
    }

    /**
     * @param  array<string, OrgUnit>  $units
     * @param  array<string, User>  $people
     */
    protected function seedProjects(array $units, array $people, TaskHierarchy $hierarchy): void
    {
        $api = Project::create([
            'org_unit_id' => $units['backend']->id,
            'name' => 'API Absensi',
            'description' => 'Layanan absensi karyawan berbasis REST.',
        ]);
        $api->members()->sync([$people['lead']->id, $people['dev1']->id]);

        $portal = Project::create([
            'org_unit_id' => $units['frontend']->id,
            'name' => 'Portal Karyawan',
            'description' => 'Portal internal untuk karyawan.',
        ]);
        $portal->members()->sync([$people['dev2']->id]);

        Project::create([
            'org_unit_id' => $units['marketing']->id,
            'name' => 'Kampanye Rekrutmen',
            'description' => 'Kampanye rekrutmen kuartal ini.',
        ]);

        $this->seedApiTasks($api, $people, $hierarchy);
        $this->seedPortalTasks($portal, $people, $hierarchy);
    }

    /**
     * A four level tree, so WBS numbering and the depth limit are both visible.
     *
     * @param  array<string, User>  $people
     */
    protected function seedApiTasks(Project $project, array $people, TaskHierarchy $hierarchy): void
    {
        $analysis = $hierarchy->create($project, [
            'title' => 'Analisis kebutuhan',
            'assignee_id' => $people['lead']->id,
            'priority' => TaskPriority::High,
            'start_date' => now()->subWeeks(3)->startOfWeek()->toDateString(),
            'due_date' => now()->subWeeks(2)->startOfWeek()->addDays(4)->toDateString(),
            'status' => 'done',
            'progress' => 100,
        ]);

        $hierarchy->create($project, [
            'title' => 'Wawancara HRD',
            'assignee_id' => $people['lead']->id,
            'start_date' => now()->subWeeks(3)->startOfWeek()->toDateString(),
            'due_date' => now()->subWeeks(3)->startOfWeek()->addDays(4)->toDateString(),
            'status' => 'done',
            'progress' => 100,
        ], $analysis);

        $build = $hierarchy->create($project, [
            'title' => 'Implementasi',
            'assignee_id' => $people['dev1']->id,
            'priority' => TaskPriority::Urgent,
            'start_date' => now()->subWeek()->startOfWeek()->toDateString(),
            'due_date' => now()->addWeeks(3)->startOfWeek()->addDays(4)->toDateString(),
            'status' => 'in_progress',
            'progress' => 45,
        ]);

        $schema = $hierarchy->create($project, [
            'title' => 'Skema database',
            'assignee_id' => $people['dev1']->id,
            'start_date' => now()->subWeek()->startOfWeek()->toDateString(),
            'due_date' => now()->subWeek()->startOfWeek()->addDays(4)->toDateString(),
            'status' => 'done',
            'progress' => 100,
        ], $build);

        $endpoints = $hierarchy->create($project, [
            'title' => 'Endpoint REST',
            'assignee_id' => $people['dev1']->id,
            'priority' => TaskPriority::High,
            'start_date' => now()->startOfWeek()->toDateString(),
            'due_date' => now()->addWeeks(2)->startOfWeek()->addDays(4)->toDateString(),
            'status' => 'in_progress',
            'progress' => 30,
        ], $build);

        $hierarchy->create($project, [
            'title' => 'Validasi input',
            'assignee_id' => $people['dev1']->id,
            'start_date' => now()->startOfWeek()->toDateString(),
            'due_date' => now()->startOfWeek()->addDays(4)->toDateString(),
            'status' => 'in_progress',
            'progress' => 60,
        ], $endpoints);

        // An overdue task, so the red flags on the board have something to show.
        $hierarchy->create($project, [
            'title' => 'Dokumentasi API',
            'assignee_id' => $people['lead']->id,
            'priority' => TaskPriority::Low,
            'start_date' => now()->subWeeks(2)->startOfWeek()->toDateString(),
            'due_date' => now()->subWeek()->startOfWeek()->addDays(4)->toDateString(),
            'status' => 'in_progress',
            'progress' => 20,
        ]);

        // An unscheduled task for the "Belum dijadwalkan" sections.
        $hierarchy->create($project, [
            'title' => 'Riset rate limiting',
            'assignee_id' => $people['dev1']->id,
        ]);
    }

    /**
     * @param  array<string, User>  $people
     */
    protected function seedPortalTasks(Project $project, array $people, TaskHierarchy $hierarchy): void
    {
        $ui = $hierarchy->create($project, [
            'title' => 'Rancang antarmuka',
            'assignee_id' => $people['dev2']->id,
            'priority' => TaskPriority::Medium,
            'start_date' => now()->startOfWeek()->toDateString(),
            'due_date' => now()->addWeek()->startOfWeek()->addDays(4)->toDateString(),
            'status' => 'in_progress',
            'progress' => 50,
        ]);

        $hierarchy->create($project, [
            'title' => 'Halaman profil',
            'assignee_id' => $people['dev2']->id,
            'start_date' => now()->startOfWeek()->toDateString(),
            'due_date' => now()->startOfWeek()->addDays(4)->toDateString(),
            'status' => 'in_progress',
            'progress' => 70,
        ], $ui);

        $hierarchy->create($project, [
            'title' => 'Integrasi API absensi',
            'assignee_id' => $people['dev2']->id,
            'priority' => TaskPriority::High,
            'start_date' => now()->addWeeks(2)->startOfWeek()->toDateString(),
            'due_date' => now()->addWeeks(4)->startOfWeek()->addDays(4)->toDateString(),
        ]);
    }
}
