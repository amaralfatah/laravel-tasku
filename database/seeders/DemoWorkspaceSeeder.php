<?php

namespace Database\Seeders;

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\OrgUnitTree;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Worked example based on a plantation company's in-house software team.
 *
 * Perkebunan Nusantara
 *   └── Divisi Transformasi Digital   ← Kepala Divisi (Owner)
 *         └── Pengembangan Digital    ← Kepala Sub Divisi (Manager) and one
 *                                        ODS / programmer (Member)
 *
 * Only the people are seeded here — no projects, no tasks, no comments.
 * {@see AmarWorkloadSeeder} brings the applications and the backlog with it.
 *
 * Every account uses the password `password`, and every address is on a
 * `.test` domain so a stray email can never reach a real inbox.
 */
class DemoWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        $workspace = Workspace::create(['name' => 'Perkebunan Nusantara']);

        // Org units are platform master data, so they are seeded before any
        // tenant context exists; the workspace then adopts the top one as the
        // slice of the tree it runs.
        $units = $this->seedUnits(app(OrgUnitTree::class));
        $workspace->update(['root_org_unit_id' => $units['transformasi']->id]);

        $tenancy->set($workspace);
        $this->seedMembers($workspace, $units);
        $tenancy->forget();

        $this->report($workspace);
    }

    /**
     * Divisi Transformasi Digital, with Pengembangan Digital beneath it.
     *
     * @return array<string, OrgUnit>
     */
    protected function seedUnits(OrgUnitTree $tree): array
    {
        $transformasi = $tree->create([
            'name' => 'Divisi Transformasi Digital',
            'type' => 'division',
        ]);

        $pengembangan = $tree->create([
            'name' => 'Pengembangan Digital',
            'type' => 'sub_division',
        ], $transformasi);

        return compact('transformasi', 'pengembangan');
    }

    /**
     * One programmer, plus the two leaders above them.
     *
     * The platform operator is not seeded here; they belong to no workspace.
     *
     * @param  array<string, OrgUnit>  $units
     */
    protected function seedMembers(Workspace $workspace, array $units): void
    {
        // The Kepala Divisi runs the entity, so they hold the Owner tier.
        // There is no account above them inside the workspace.
        $this->seedMember(
            $workspace,
            'Prasetyo Mimboro',
            'kadiv@perkebunan.test',
            WorkspaceRole::Owner,
            $units['transformasi'],
            'Kepala Divisi',
        );

        // A Manager sitting in Pengembangan Digital is what gives them that
        // whole subtree (7.2 rule 2).
        $this->seedMember(
            $workspace,
            'Rakhmat Akbar Sinaga',
            'kasubdiv@perkebunan.test',
            WorkspaceRole::Manager,
            $units['pengembangan'],
            'Kepala Sub Divisi',
        );

        // The only ODS: a plain Member sees just the projects they are on.
        $this->seedMember(
            $workspace,
            'Amar',
            'amar@perkebunan.test',
            WorkspaceRole::Member,
            $units['pengembangan'],
            'ODS / Programmer',
        );
    }

    /**
     * @return array{user: User, membership: WorkspaceMember}
     */
    protected function seedMember(
        Workspace $workspace,
        string $name,
        string $email,
        WorkspaceRole $role,
        ?OrgUnit $unit = null,
        ?string $title = null,
    ): array {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'title' => $title,
            'org_unit_id' => $unit?->id,
            'joined_at' => now(),
        ]);

        return ['user' => $user, 'membership' => $membership];
    }

    protected function report(Workspace $workspace): void
    {
        $this->command->info("Workspace {$workspace->name} dibuat. Kata sandi semua akun: password");
        $this->command->table(
            ['Nama', 'Email', 'Role', 'Jabatan', 'Unit', 'Cakupan pemantauan'],
            [
                ['Super Admin', 'admin@perkebunan.test', 'Super Admin', '—', '— (di luar workspace)', 'semua workspace'],
                ['Prasetyo Mimboro', 'kadiv@perkebunan.test', 'Pemilik', 'Kepala Divisi', 'Divisi Transformasi Digital', 'seluruh workspace'],
                ['Rakhmat Akbar Sinaga', 'kasubdiv@perkebunan.test', 'Manajer', 'Kepala Sub Divisi', 'Pengembangan Digital', 'Pengembangan Digital & turunannya'],
                ['Amar', 'amar@perkebunan.test', 'Anggota', 'ODS / Programmer', 'Pengembangan Digital', 'project yang diikuti'],
            ],
        );
    }
}
