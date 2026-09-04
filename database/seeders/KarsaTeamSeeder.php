<?php

namespace Database\Seeders;

use App\Enums\TaskPriority;
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
use App\Services\OrgUnitTree;
use App\Services\TaskHierarchy;
use App\Support\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;

/**
 * The next scale up from {@see AmarWorkloadSeeder}: a small studio where six
 * people share the work, and nobody has drawn an org chart yet.
 *
 * Karsa Studio   ← Rani (Owner), Bagas (Manager), three Members, one Viewer
 *
 * That is what {@see WorkspaceScale} reads as `Team`: more than one member,
 * still a single node of the org tree. The roster, the requester list and the
 * monitoring pages appear; the organisation page has one box on it, and the
 * group dashboard stays out of sight. Adding a second unit below the root is
 * all it would take to tip the same data into `Company`, which is exactly the
 * step this seeder is here to sit below.
 *
 * The backlog is invented rather than transcribed, and it is shaped to give
 * the team pages something to show: work spread over five people and three
 * clients, one task nobody has picked up, one waiting on a review, one over
 * its due date, and a handful nobody has scheduled at all.
 *
 * Every account uses the password `password`, and every address is on a
 * `.test` domain so a stray email can never reach a real inbox.
 *
 * Dates are day offsets from the day the seeder runs — `-30` is a month ago,
 * `7` is next week — so the board, the timeline and the late-work report read
 * as a team mid-flight however long after the seeding somebody looks. A row
 * with no offsets is work nobody has scheduled yet, and a row never carries
 * only one of the two: the timeline cannot draw half a bar.
 *
 * @phpstan-type TaskRow array{title: string, assignee?: string, progress?: int, status?: string, start?: int, due?: int, priority?: string, requester?: string, children?: array<int, mixed>}
 */
class KarsaTeamSeeder extends Seeder
{
    /** Name of both the workspace and the single org unit it runs. */
    protected const WORKSPACE = 'Karsa Studio';

    /** Domain every account in this workspace sits on. */
    protected const DOMAIN = 'karsa.test';

    public function run(): void
    {
        $tenancy = app(Tenancy::class);
        $members = $this->seedWorkspace();

        if (! isset($members['rani'])) {
            $this->command->warn('Lewati: workspace '.self::WORKSPACE.' sudah ada tanpa pemiliknya.');

            return;
        }

        $owner = $members['rani'];

        $tenancy->set($owner->workspace, $owner);

        if (Task::query()->exists()) {
            $tenancy->forget();
            $this->command->warn('Lewati: task '.self::WORKSPACE.' sudah ada.');

            return;
        }

        $requesters = $this->seedRequesters($owner);
        $count = $this->seedProjects($members, $requesters);

        $tenancy->forget();

        $this->command->info('Workspace '.self::WORKSPACE.' siap. Kata sandi akun: password');
        $this->command->info($count.' task dibagi ke '.count($members).' anggota di 3 project.');
    }

    /**
     * The workspace, its one org unit and the six people in it.
     *
     * The tree is deliberately a single node: everyone is placed in the root,
     * so the Manager's scope is the whole studio and `manager_id` — not the
     * org chart — is what records who reports to whom. That is how a team of
     * this size actually works, and it keeps the workspace on the `Team` scale
     * rather than tipping it into `Company`.
     *
     * Idempotent, so re-running the seeder adopts the workspace that is
     * already there rather than opening a second one beside it.
     *
     * @return array<string, WorkspaceMember> keyed the way {@see people()} is
     */
    protected function seedWorkspace(): array
    {
        $tenancy = app(Tenancy::class);

        $workspace = Workspace::query()->where('name', self::WORKSPACE)->first();

        if ($workspace !== null) {
            return $this->existingMembers($workspace);
        }

        $workspace = Workspace::create(['name' => self::WORKSPACE]);

        // Org units are platform master data and carry no workspace_id, so the
        // node is written outside any tenant context; the workspace then adopts
        // it as the slice of the tree it runs.
        $root = $tenancy->withoutScope(fn (): OrgUnit => app(OrgUnitTree::class)->create([
            'name' => self::WORKSPACE,
            'type' => 'company',
        ]));

        $workspace->update(['root_org_unit_id' => $root->id]);

        return $tenancy->forWorkspace($workspace, function () use ($workspace, $root): array {
            $members = [];

            foreach ($this->people() as $key => $person) {
                $user = User::firstOrCreate(
                    ['email' => $person['email']],
                    ['name' => $person['name'], 'password' => Hash::make('password'), 'email_verified_at' => now()],
                );

                $members[$key] = WorkspaceMember::create([
                    'workspace_id' => $workspace->id,
                    'user_id' => $user->id,
                    'role' => $person['role'],
                    // Unlike the solo workspace, every row carries a job title:
                    // with several people on a roster the tier alone stops
                    // saying who does what.
                    'title' => $person['title'],
                    'org_unit_id' => $root->id,
                    // People are listed leaders first, so whoever a row reports
                    // to has always been written by the time it is read.
                    'manager_id' => $person['manager'] === null ? null : $members[$person['manager']]->id,
                    'joined_at' => now()->subMonths($person['months']),
                ]);

                $members[$key]->setRelation('user', $user);
                $members[$key]->setRelation('workspace', $workspace);
            }

            return $members;
        });
    }

    /**
     * The roster of a workspace this seeder has already written.
     *
     * @return array<string, WorkspaceMember>
     */
    protected function existingMembers(Workspace $workspace): array
    {
        $tenancy = app(Tenancy::class);

        return $tenancy->forWorkspace($workspace, function () use ($workspace): array {
            $rows = WorkspaceMember::query()->with('user')->get()->keyBy('user.email');

            $members = [];

            foreach ($this->people() as $key => $person) {
                $member = $rows->get($person['email']);

                if ($member !== null) {
                    $member->setRelation('workspace', $workspace);
                    $members[$key] = $member;
                }
            }

            return $members;
        });
    }

    /**
     * Who is in the studio, leaders first.
     *
     * The Viewer is the client's own project manager: they follow the work and
     * change nothing, which is the tier's whole purpose and the reason a studio
     * can share a board with a customer at all.
     *
     * @return array<string, array{name: string, email: string, role: WorkspaceRole, title: string, manager: string|null, months: int}>
     */
    protected function people(): array
    {
        return [
            'rani' => [
                'name' => 'Rani Prameswari',
                'email' => 'rani@'.self::DOMAIN,
                'role' => WorkspaceRole::Owner,
                'title' => 'Founder & Product Lead',
                'manager' => null,
                'months' => 36,
            ],
            'bagas' => [
                'name' => 'Bagas Wicaksono',
                'email' => 'bagas@'.self::DOMAIN,
                'role' => WorkspaceRole::Manager,
                'title' => 'Engineering Lead',
                'manager' => 'rani',
                'months' => 30,
            ],
            'dewi' => [
                'name' => 'Dewi Anggraini',
                'email' => 'dewi@'.self::DOMAIN,
                'role' => WorkspaceRole::Member,
                'title' => 'Frontend Engineer',
                'manager' => 'bagas',
                'months' => 14,
            ],
            'faisal' => [
                'name' => 'Faisal Rahman',
                'email' => 'faisal@'.self::DOMAIN,
                'role' => WorkspaceRole::Member,
                'title' => 'Backend Engineer',
                'manager' => 'bagas',
                'months' => 20,
            ],
            'nadia' => [
                'name' => 'Nadia Kusuma',
                'email' => 'nadia@'.self::DOMAIN,
                'role' => WorkspaceRole::Member,
                'title' => 'Product Designer',
                'manager' => 'bagas',
                'months' => 9,
            ],
            'yoga' => [
                'name' => 'Yoga Pratama',
                'email' => 'yoga@'.self::DOMAIN,
                'role' => WorkspaceRole::Viewer,
                'title' => 'Perwakilan Klien PT Sinar Rejeki',
                'manager' => 'rani',
                'months' => 4,
            ],
        ];
    }

    /**
     * The list of people the work is asked for, which only a leader writes.
     *
     * Three of them are outside the studio altogether — a client, a government
     * office, a cooperative — which is why the column cannot be a user picker.
     * One is retired: the contract is over, so they stay on the tasks that name
     * them and disappear from the picker.
     *
     * @return array<string, Requester>
     */
    protected function seedRequesters(WorkspaceMember $owner): array
    {
        $rows = [
            'sinar' => ['Yoga Pratama', 'PT Sinar Rejeki', 'yoga@'.self::DOMAIN, true],
            'dinas' => ['Larasati Widodo', 'Dinas Pariwisata Kota Malang', 'larasati@dinaspar.test', true],
            'koperasi' => ['Hendra Saputra', 'Koperasi Tani Makmur', 'hendra@tanimakmur.test', true],
            'internal' => ['Rani Prameswari', self::WORKSPACE, 'rani@'.self::DOMAIN, true],
            'bumirasa' => ['Danu Kurniawan', 'CV Bumi Rasa', null, false],
        ];

        $requesters = [];

        foreach ($rows as $key => [$name, $organization, $email, $isActive]) {
            $requester = new Requester([
                'name' => $name,
                'organization' => $organization,
                'email' => $email,
                'is_active' => $isActive,
            ]);

            // Who added the row, which is not who asked for the work: the list
            // is maintained by a leader on behalf of everyone on it.
            $requester->created_by = $owner->user_id;
            $requester->save();

            $requesters[$key] = $requester;
        }

        return $requesters;
    }

    /**
     * @param  array<string, WorkspaceMember>  $members
     * @param  array<string, Requester>  $requesters
     * @return int the number of tasks written
     */
    protected function seedProjects(array $members, array $requesters): int
    {
        $hierarchy = app(TaskHierarchy::class);
        $owner = $members['rani'];
        $count = 0;

        foreach ($this->projects() as $name => [$description, $team, $tasks]) {
            $project = Project::firstOrCreate(
                ['name' => $name],
                [
                    'org_unit_id' => $owner->org_unit_id,
                    'key' => Project::generateKey($name),
                    'description' => $description,
                    'status' => 'active',
                ],
            );

            // Whoever opened the project runs it, the way a team-managed
            // project in Jira belongs to the person who started it.
            $project->forceFill(['created_by' => $owner->user_id])->save();

            $project->members()->syncWithoutDetaching(
                array_map(fn (string $key): int => $members[$key]->user_id, $team),
            );

            // No assignment notifications while the tree is written — this is
            // work the team has been doing for months, not news — and no
            // rollup, because a parent's percentage is computed here from the
            // sub tasks that were just written under it.
            $count += Model::withoutEvents(
                fn (): int => $this->seedBranch($hierarchy, $project, $tasks, $members, $requesters),
            );
        }

        return $count;
    }

    /**
     * Write one level of the tree, then recurse into whatever hangs under it.
     *
     * Rows are written in the order they are listed, because that is the order
     * the board holds them in — and `TaskHierarchy` derives both `position` and
     * the WBS number from the order they arrive in.
     *
     * @param  array<int, TaskRow>  $rows
     * @param  array<string, WorkspaceMember>  $members
     * @param  array<string, Requester>  $requesters
     */
    protected function seedBranch(
        TaskHierarchy $hierarchy,
        Project $project,
        array $rows,
        array $members,
        array $requesters,
        ?Task $parent = null,
    ): int {
        $count = 0;

        foreach ($rows as $row) {
            $status = TaskStatus::from($row['status'] ?? 'todo');

            $task = $hierarchy->create($project, [
                'title' => $row['title'],
                // One task is open with nobody on it, which is what the board's
                // unassigned filter and the workload report are there to find.
                'assignee_id' => isset($row['assignee']) ? $members[$row['assignee']]->user_id : null,
                'status' => $status,
                // A leaf's percentage follows its status, except while the work
                // is in progress — how far along it is, is the worker's to say.
                'progress' => $row['progress'] ?? $status->forcedProgress() ?? 0,
                'priority' => TaskPriority::from($row['priority'] ?? 'medium'),
                'start_date' => $this->day($row['start'] ?? null),
                'due_date' => $this->day($row['due'] ?? null),
                'requester_id' => isset($row['requester']) ? $requesters[$row['requester']]->id : null,
            ], $parent);

            // Who filed the task. The Engineering Lead breaks the work down;
            // the requester on the row is the person it is being done for.
            $task->forceFill(['created_by' => $members['bagas']->user_id])->save();

            $count++;

            $children = $row['children'] ?? [];
            $count += $this->seedBranch($hierarchy, $project, $children, $members, $requesters, $task);

            if ($children !== []) {
                // The events that normally roll a percentage up are off, so the
                // figure is computed here instead — same average, same rounding,
                // and the status is left exactly where the row put it.
                $hierarchy->syncParentProgress($task);
            }
        }

        return $count;
    }

    /**
     * Turn a day offset into a calendar day, counted from the day the seeder
     * runs: `-30` a month back, `7` next week.
     *
     * A row without an offset is work nobody has scheduled yet, which the app
     * reports separately from work that is late (DIV-5), so the null is carried
     * through rather than filled in with a guess.
     */
    protected function day(?int $offset): ?CarbonInterface
    {
        return $offset === null ? null : Date::now()->startOfDay()->addDays($offset);
    }

    /**
     * The three projects, each with its team and its tree of work.
     *
     * Two are client projects and one is the studio's own housekeeping, which
     * is what a studio of this size actually carries: paid work with a
     * requester on every row, and internal work asked for by the founder.
     *
     * @return array<string, array{0: string, 1: array<int, string>, 2: array<int, TaskRow>}>
     */
    protected function projects(): array
    {
        return [
            'Sinar Rejeki Commerce' => [
                'Toko online B2B untuk distributor: katalog grosir, pembayaran tempo, dan portal sales lapangan.',
                ['rani', 'bagas', 'dewi', 'faisal', 'nadia', 'yoga'],
                [
                    ['title' => 'Riset & Alur Belanja', 'status' => 'done', 'start' => -74, 'due' => -58, 'requester' => 'sinar', 'children' => [
                        ['title' => 'Wawancara 5 sales lapangan', 'assignee' => 'nadia', 'status' => 'done', 'start' => -74, 'due' => -66, 'requester' => 'sinar'],
                        ['title' => 'Peta alur pemesanan grosir', 'assignee' => 'nadia', 'status' => 'done', 'start' => -66, 'due' => -58, 'requester' => 'sinar'],
                    ]],
                    ['title' => 'Desain Antarmuka', 'status' => 'in_progress', 'start' => -56, 'due' => 4, 'requester' => 'sinar', 'children' => [
                        ['title' => 'Desain katalog & keranjang', 'assignee' => 'nadia', 'status' => 'done', 'start' => -56, 'due' => -44, 'requester' => 'sinar'],
                        // Handed up and waiting on a decision, which is what
                        // pins it at 100% without being finished.
                        ['title' => 'Desain checkout & pembayaran tempo', 'assignee' => 'nadia', 'status' => 'review', 'start' => -44, 'due' => -30, 'requester' => 'sinar'],
                        ['title' => 'Desain halaman riwayat pesanan', 'assignee' => 'nadia', 'status' => 'in_progress', 'progress' => 40, 'start' => -12, 'due' => 4, 'requester' => 'sinar'],
                    ]],
                    ['title' => 'Katalog Produk & Harga Grosir', 'assignee' => 'faisal', 'status' => 'done', 'start' => -50, 'due' => -32, 'priority' => 'high', 'requester' => 'sinar'],
                    ['title' => 'Keranjang & Checkout', 'status' => 'in_progress', 'start' => -32, 'due' => -3, 'requester' => 'sinar', 'children' => [
                        ['title' => 'API keranjang', 'assignee' => 'faisal', 'status' => 'done', 'start' => -32, 'due' => -22, 'requester' => 'sinar'],
                        // Past its due date and still running: the row the late
                        // work report exists for.
                        ['title' => 'Pembayaran tempo 30 hari', 'assignee' => 'faisal', 'status' => 'in_progress', 'progress' => 55, 'start' => -20, 'due' => -3, 'priority' => 'urgent', 'requester' => 'sinar'],
                        // Nobody has picked this up, and nobody has scheduled it.
                        ['title' => 'Integrasi kurir & ongkir', 'requester' => 'sinar'],
                    ]],
                    ['title' => 'Portal Sales Lapangan', 'assignee' => 'dewi', 'status' => 'in_progress', 'progress' => 70, 'start' => -22, 'due' => 7, 'priority' => 'high', 'requester' => 'sinar'],
                    ['title' => 'Laporan Penjualan per Wilayah', 'assignee' => 'dewi', 'start' => 7, 'due' => 21, 'requester' => 'sinar'],
                    ['title' => 'Uji Terima & Serah Terima Klien', 'assignee' => 'bagas', 'start' => 21, 'due' => 35, 'priority' => 'high', 'requester' => 'sinar'],
                ],
            ],
            'Wisata Malang' => [
                'Aplikasi mobile pariwisata Dinas Pariwisata Kota Malang: peta titik wisata, rute, dan kalender acara.',
                ['rani', 'bagas', 'dewi', 'faisal', 'nadia'],
                [
                    ['title' => 'Fondasi Aplikasi Mobile', 'assignee' => 'bagas', 'status' => 'done', 'start' => -60, 'due' => -48, 'requester' => 'dinas'],
                    ['title' => 'Peta & Titik Wisata', 'status' => 'in_progress', 'start' => -48, 'due' => 10, 'requester' => 'dinas', 'children' => [
                        ['title' => 'Integrasi peta & lokasi pengguna', 'assignee' => 'dewi', 'status' => 'done', 'start' => -48, 'due' => -36, 'requester' => 'dinas'],
                        ['title' => 'Detail titik wisata & galeri foto', 'assignee' => 'dewi', 'status' => 'done', 'start' => -36, 'due' => -18, 'requester' => 'dinas'],
                        ['title' => 'Rute & navigasi antar titik', 'assignee' => 'dewi', 'status' => 'in_progress', 'progress' => 30, 'start' => -8, 'due' => 10, 'requester' => 'dinas'],
                    ]],
                    ['title' => 'Kalender Acara Kota', 'assignee' => 'faisal', 'status' => 'review', 'start' => -24, 'due' => -6, 'requester' => 'dinas'],
                    ['title' => 'Panel Admin Dinas', 'assignee' => 'faisal', 'status' => 'in_progress', 'progress' => 80, 'start' => -18, 'due' => -1, 'priority' => 'high', 'requester' => 'dinas'],
                    ['title' => 'Terjemahan Bahasa Inggris', 'requester' => 'dinas', 'priority' => 'low'],
                    ['title' => 'Uji Coba Lapangan bersama Dinas', 'assignee' => 'nadia', 'start' => 12, 'due' => 24, 'requester' => 'dinas'],
                ],
            ],
            'Karsa Internal' => [
                'Pekerjaan studio sendiri: situs profil, kontrak dan invoice, onboarding, serta pemeliharaan server.',
                ['rani', 'bagas', 'faisal', 'nadia'],
                [
                    ['title' => 'Situs Profil Studio', 'status' => 'in_progress', 'start' => -40, 'due' => -30, 'requester' => 'internal', 'children' => [
                        ['title' => 'Tulis ulang halaman layanan', 'assignee' => 'nadia', 'status' => 'done', 'start' => -40, 'due' => -30, 'requester' => 'internal'],
                        ['title' => 'Studi kasus Sinar Rejeki', 'assignee' => 'nadia', 'requester' => 'internal', 'priority' => 'low'],
                    ]],
                    // The contract it came from is over, so the requester on it
                    // is retired — the row keeps the name either way.
                    ['title' => 'Arsip & Serah Terima Bumi Rasa', 'assignee' => 'bagas', 'status' => 'done', 'start' => -90, 'due' => -80, 'requester' => 'bumirasa'],
                    ['title' => 'Templat Kontrak & Invoice', 'assignee' => 'rani', 'status' => 'done', 'start' => -34, 'due' => -26, 'requester' => 'internal'],
                    ['title' => 'Onboarding Anggota Baru', 'assignee' => 'bagas', 'status' => 'in_progress', 'progress' => 45, 'start' => -14, 'due' => 14, 'requester' => 'internal'],
                    ['title' => 'Backup & Pemantauan Server', 'assignee' => 'faisal', 'status' => 'done', 'start' => -28, 'due' => -20, 'priority' => 'high', 'requester' => 'internal'],
                    ['title' => 'Penawaran Koperasi Tani Makmur', 'assignee' => 'rani', 'status' => 'in_progress', 'progress' => 20, 'start' => -5, 'due' => 9, 'priority' => 'high', 'requester' => 'koperasi'],
                    ['title' => 'Rencana Kuartal Depan', 'assignee' => 'rani', 'start' => 16, 'due' => 45, 'requester' => 'internal'],
                ],
            ],
        ];
    }
}
