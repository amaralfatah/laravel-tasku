<?php

namespace Database\Seeders;

use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\OrgUnitTree;
use App\Services\TaskHierarchy;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Worked example based on a plantation company's in-house software team.
 *
 * Perkebunan Nusantara
 *   └── Divisi Transformasi Digital   ← Kepala Divisi (BOD-1)
 *         └── Pengembangan Digital    ← Kepala Sub Divisi (BOD-2), Asisten
 *                                        (BOD-3) and five ODS (BOD-4)
 *
 * The history runs from June 2025 to the present: three delivered projects,
 * two in flight, and one just starting. Finished work carries its real
 * historical dates; work still in flight is anchored to `now()` so the overdue
 * flags, the today marker and the "due soon" reminders always have something
 * live to show.
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
        $tenancy->set($workspace);

        $units = $this->seedUnits(app(OrgUnitTree::class));
        $people = $this->seedMembers($workspace, $units, $tenancy);

        $projects = $this->seedProjects($units['pengembangan'], $people);

        // Tasks are created with model events off: otherwise every historical
        // assignment from 2025 would raise a notification dated today.
        Model::withoutEvents(function () use ($projects, $people): void {
            $this->seedTasks($projects, $people);
        });

        // Comments run with events on, so the mention and comment
        // notifications they raise are genuinely recent.
        $this->seedComments($projects, $people);

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
     * Six programmers — one Asisten and five ODS — plus the two above them.
     *
     * The platform operator is not seeded here; they belong to no workspace.
     *
     * @param  array<string, OrgUnit>  $units
     * @return array<string, User>
     */
    protected function seedMembers(Workspace $workspace, array $units, Tenancy $tenancy): array
    {
        // Kepala Divisi is BOD-1, the top of the entity. There is no account
        // above them inside the workspace.
        $kadiv = $this->seedMember(
            $workspace,
            'Prasetyo Mimboro',
            'kadiv@perkebunan.test',
            WorkspaceRole::Bod1,
            $units['transformasi'],
        );

        $people = [
            'kadiv' => $kadiv['user'],

            // Kepala Sub Divisi is BOD-2; sitting in Pengembangan Digital is
            // what gives them that whole subtree (7.2 rule 2).
            'kasubdiv' => $this->seedMember(
                $workspace,
                'Rakhmat Akbar Sinaga',
                'kasubdiv@perkebunan.test',
                WorkspaceRole::Bod2,
                $units['pengembangan'],
            )['user'],

            'amar' => $this->seedMember(
                $workspace,
                'Amar',
                'amar@perkebunan.test',
                WorkspaceRole::Bod3,
                $units['pengembangan'],
            )['user'],

            'heru' => $this->seedMember(
                $workspace,
                'Heru',
                'heru@perkebunan.test',
                WorkspaceRole::Bod4,
                $units['pengembangan'],
            )['user'],

            'vino' => $this->seedMember(
                $workspace,
                'Vino',
                'vino@perkebunan.test',
                WorkspaceRole::Bod4,
                $units['pengembangan'],
            )['user'],

            'yogi' => $this->seedMember(
                $workspace,
                'Yogi',
                'yogi@perkebunan.test',
                WorkspaceRole::Bod4,
                $units['pengembangan'],
            )['user'],

            'adit' => $this->seedMember(
                $workspace,
                'Adit',
                'adit@perkebunan.test',
                WorkspaceRole::Bod4,
                $units['pengembangan'],
            )['user'],

            'adhi' => $this->seedMember(
                $workspace,
                'Adhi',
                'adhi@perkebunan.test',
                WorkspaceRole::Bod4,
                $units['pengembangan'],
            )['user'],
        ];

        // The owner's membership drives creator attribution for the projects.
        $tenancy->set($workspace, $kadiv['membership']);

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
        ?OrgUnit $unit = null,
    ): array {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'org_unit_id' => $unit?->id,
            'joined_at' => now(),
        ]);

        return ['user' => $user, 'membership' => $membership];
    }

    /**
     * Six projects spanning June 2025 to now: three delivered, two running,
     * one just kicked off.
     *
     * @param  array<string, User>  $people
     * @return array<string, Project>
     */
    protected function seedProjects(OrgUnit $unit, array $people): array
    {
        $definitions = [
            'sik' => [
                'HRIS',
                'Pencatatan blok, luas tanam, dan realisasi panen per afdeling menggantikan buku mandor.',
                'completed',
                ['amar', 'heru', 'vino'],
            ],
            'mandor' => [
                'GrowMate',
                'Aplikasi Android untuk mandor mencatat kehadiran pemanen dan hasil panen harian langsung dari kebun.',
                'completed',
                ['amar', 'yogi', 'adhi'],
            ],
            'timbang' => [
                'Hukum',
                'Integrasi jembatan timbang pabrik kelapa sawit dengan pencatatan tiket TBS elektronik.',
                'completed',
                ['amar', 'vino', 'adit'],
            ],
            'dashboard' => [
                'Boardroom',
                'Dashboard manajemen untuk memantau produksi TBS, rendemen CPO, dan produktivitas per afdeling.',
                'active',
                ['amar', 'heru', 'adit', 'adhi'],
            ],
            'procurement' => [
                'RUP',
                'Pengadaan pupuk, bibit, dan pestisida secara elektronik dengan alur persetujuan berjenjang.',
                'active',
                ['amar', 'vino', 'yogi'],
            ],
            'hris' => [
                'PTI',
                'Portal mandiri karyawan untuk slip gaji, cuti, dan data kepegawaian.',
                'active',
                ['heru', 'adit'],
            ],
        ];

        $projects = [];

        foreach ($definitions as $key => [$name, $description, $status, $memberKeys]) {
            $project = Project::create([
                'org_unit_id' => $unit->id,
                'name' => $name,
                'key' => Project::generateKey($name),
                'description' => $description,
                'status' => $status,
            ]);

            $project->members()->sync(
                array_map(fn (string $memberKey): int => $people[$memberKey]->id, $memberKeys),
            );

            $projects[$key] = $project;
        }

        return $projects;
    }

    /**
     * @param  array<string, Project>  $projects
     * @param  array<string, User>  $people
     */
    protected function seedTasks(array $projects, array $people): void
    {
        $hierarchy = app(TaskHierarchy::class);

        foreach ($this->taskDefinitions() as $projectKey => $nodes) {
            $this->seedTree($hierarchy, $projects[$projectKey], $people, $nodes);
        }
    }

    /**
     * Walk a nested task definition, creating each node under its parent.
     *
     * @param  array<string, User>  $people
     * @param  array<int, array<string, mixed>>  $nodes
     */
    protected function seedTree(
        TaskHierarchy $hierarchy,
        Project $project,
        array $people,
        array $nodes,
        ?Task $parent = null,
    ): void {
        foreach ($nodes as $node) {
            $assigneeKey = $node['assignee'] ?? null;

            $task = $hierarchy->create($project, [
                'title' => $node['title'],
                'assignee_id' => $assigneeKey === null ? null : $people[$assigneeKey]->id,
                'status' => $node['status'] ?? 'todo',
                'progress' => $node['progress'] ?? 0,
                'priority' => $node['priority'] ?? 'medium',
                'start_date' => $node['start'] ?? null,
                'due_date' => $node['due'] ?? null,
            ], $parent);

            $this->backdate($task);

            if (isset($node['children'])) {
                $this->seedTree($hierarchy, $project, $people, $node['children'], $task);
            }
        }
    }

    /**
     * Move a finished task's timestamps back to when it actually ran.
     *
     * Without this every task looks like it was created and completed today,
     * which would make "selesai 30 hari terakhir" count the whole 2025 backlog.
     */
    protected function backdate(Task $task): void
    {
        if ($task->start_date === null || $task->due_date === null) {
            return;
        }

        $finishedInThePast = $task->status === TaskStatus::Done && $task->due_date->isPast();

        $task->forceFill([
            'created_at' => $task->start_date,
            'updated_at' => $finishedInThePast ? $task->due_date : now(),
        ])->saveQuietly();
    }

    /**
     * The task trees, one per project.
     *
     * Delivered projects use their real historical dates. Live projects are
     * anchored to `now()` so the timeline, the overdue flags and the due-soon
     * reminders always land around today.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function taskDefinitions(): array
    {
        $week = fn (int $offset): string => now()->startOfWeek()->addWeeks($offset)->toDateString();
        $friday = fn (int $offset): string => now()->startOfWeek()->addWeeks($offset)->addDays(4)->toDateString();

        return [
            // Jun - Des 2025, delivered.
            'sik' => [
                [
                    'title' => 'Analisis proses bisnis kebun',
                    'assignee' => 'amar', 'status' => 'done', 'progress' => 100, 'priority' => 'high',
                    'start' => '2025-06-02', 'due' => '2025-06-27',
                    'children' => [
                        ['title' => 'Wawancara asisten afdeling', 'assignee' => 'amar', 'status' => 'done', 'progress' => 100, 'start' => '2025-06-02', 'due' => '2025-06-13'],
                        ['title' => 'Pemetaan alur buku mandor', 'assignee' => 'heru', 'status' => 'done', 'progress' => 100, 'start' => '2025-06-16', 'due' => '2025-06-27'],
                    ],
                ],
                [
                    'title' => 'Perancangan basis data blok & afdeling',
                    'assignee' => 'vino', 'status' => 'done', 'progress' => 100, 'priority' => 'high',
                    'start' => '2025-06-30', 'due' => '2025-07-25',
                ],
                [
                    'title' => 'Implementasi modul inti',
                    'assignee' => 'amar', 'status' => 'done', 'progress' => 100, 'priority' => 'urgent',
                    'start' => '2025-07-28', 'due' => '2025-10-31',
                    'children' => [
                        [
                            'title' => 'Master data blok & luas tanam',
                            'assignee' => 'vino', 'status' => 'done', 'progress' => 100,
                            'start' => '2025-07-28', 'due' => '2025-08-22',
                            'children' => [
                                ['title' => 'Impor data blok dari spreadsheet', 'assignee' => 'vino', 'status' => 'done', 'progress' => 100, 'start' => '2025-07-28', 'due' => '2025-08-08'],
                                ['title' => 'Validasi luas tanam per afdeling', 'assignee' => 'heru', 'status' => 'done', 'progress' => 100, 'start' => '2025-08-11', 'due' => '2025-08-22'],
                            ],
                        ],
                        ['title' => 'Pencatatan realisasi panen harian', 'assignee' => 'heru', 'status' => 'done', 'progress' => 100, 'priority' => 'high', 'start' => '2025-08-25', 'due' => '2025-09-26'],
                        ['title' => 'Laporan produksi bulanan', 'assignee' => 'vino', 'status' => 'done', 'progress' => 100, 'start' => '2025-09-29', 'due' => '2025-10-31'],
                    ],
                ],
                [
                    'title' => 'Pengujian & serah terima',
                    'assignee' => 'amar', 'status' => 'done', 'progress' => 100,
                    'start' => '2025-11-03', 'due' => '2025-12-19',
                    'children' => [
                        ['title' => 'UAT bersama asisten kebun', 'assignee' => 'heru', 'status' => 'done', 'progress' => 100, 'start' => '2025-11-03', 'due' => '2025-11-28'],
                        ['title' => 'Pelatihan mandor & operator', 'assignee' => 'amar', 'status' => 'done', 'progress' => 100, 'start' => '2025-12-01', 'due' => '2025-12-19'],
                    ],
                ],
            ],

            // Sep 2025 - Mar 2026, delivered.
            'mandor' => [
                [
                    'title' => 'Riset kebutuhan lapangan',
                    'assignee' => 'yogi', 'status' => 'done', 'progress' => 100, 'priority' => 'high',
                    'start' => '2025-09-01', 'due' => '2025-09-26',
                ],
                [
                    'title' => 'Pengembangan aplikasi Android',
                    'assignee' => 'yogi', 'status' => 'done', 'progress' => 100, 'priority' => 'urgent',
                    'start' => '2025-09-29', 'due' => '2026-01-30',
                    'children' => [
                        ['title' => 'Absensi pemanen dengan GPS', 'assignee' => 'yogi', 'status' => 'done', 'progress' => 100, 'priority' => 'high', 'start' => '2025-09-29', 'due' => '2025-11-07'],
                        [
                            'title' => 'Input hasil panen offline',
                            'assignee' => 'yogi', 'status' => 'done', 'progress' => 100, 'priority' => 'urgent',
                            'start' => '2025-11-10', 'due' => '2025-12-19',
                            'children' => [
                                ['title' => 'Penyimpanan lokal saat sinyal hilang', 'assignee' => 'yogi', 'status' => 'done', 'progress' => 100, 'start' => '2025-11-10', 'due' => '2025-11-28'],
                                ['title' => 'Sinkronisasi otomatis saat online', 'assignee' => 'adhi', 'status' => 'done', 'progress' => 100, 'start' => '2025-12-01', 'due' => '2025-12-19'],
                            ],
                        ],
                        ['title' => 'Integrasi API Sistem Informasi Kebun', 'assignee' => 'amar', 'status' => 'done', 'progress' => 100, 'start' => '2026-01-05', 'due' => '2026-01-30'],
                    ],
                ],
                [
                    'title' => 'Uji coba di Kebun Rejosari',
                    'assignee' => 'adhi', 'status' => 'done', 'progress' => 100, 'priority' => 'high',
                    'start' => '2026-02-02', 'due' => '2026-03-13',
                ],
                [
                    'title' => 'Rilis ke Play Store internal',
                    'assignee' => 'amar', 'status' => 'done', 'progress' => 100,
                    'start' => '2026-03-16', 'due' => '2026-03-27',
                ],
            ],

            // Jan - Jul 2026, delivered.
            'timbang' => [
                [
                    'title' => 'Kajian integrasi jembatan timbang',
                    'assignee' => 'adit', 'status' => 'done', 'progress' => 100, 'priority' => 'high',
                    'start' => '2026-01-05', 'due' => '2026-02-13',
                    'children' => [
                        ['title' => 'Survei perangkat timbang PKS', 'assignee' => 'adit', 'status' => 'done', 'progress' => 100, 'start' => '2026-01-05', 'due' => '2026-01-23'],
                        ['title' => 'Uji baca serial indikator timbangan', 'assignee' => 'vino', 'status' => 'done', 'progress' => 100, 'start' => '2026-01-26', 'due' => '2026-02-13'],
                    ],
                ],
                [
                    'title' => 'Modul tiket TBS elektronik',
                    'assignee' => 'vino', 'status' => 'done', 'progress' => 100, 'priority' => 'urgent',
                    'start' => '2026-02-16', 'due' => '2026-05-08',
                    'children' => [
                        ['title' => 'Pencatatan bruto, tara, netto', 'assignee' => 'vino', 'status' => 'done', 'progress' => 100, 'start' => '2026-02-16', 'due' => '2026-03-20'],
                        ['title' => 'Potongan sortasi & denda kualitas', 'assignee' => 'adit', 'status' => 'done', 'progress' => 100, 'priority' => 'high', 'start' => '2026-03-23', 'due' => '2026-04-17'],
                        ['title' => 'Cetak tiket timbang', 'assignee' => 'adit', 'status' => 'done', 'progress' => 100, 'start' => '2026-04-20', 'due' => '2026-05-08'],
                    ],
                ],
                [
                    'title' => 'Paralel run dengan timbangan manual',
                    'assignee' => 'amar', 'status' => 'done', 'progress' => 100, 'priority' => 'high',
                    'start' => '2026-05-11', 'due' => '2026-06-26',
                ],
                [
                    'title' => 'Go-live PKS Sei Mangkei',
                    'assignee' => 'amar', 'status' => 'done', 'progress' => 100, 'priority' => 'urgent',
                    'start' => '2026-06-29', 'due' => '2026-07-17',
                ],
            ],

            // Apr 2026 - berjalan.
            'dashboard' => [
                [
                    'title' => 'Definisi indikator produksi',
                    'assignee' => 'amar', 'status' => 'done', 'progress' => 100, 'priority' => 'high',
                    'start' => '2026-04-06', 'due' => '2026-05-01',
                ],
                [
                    'title' => 'Pipeline data produksi & rendemen',
                    'assignee' => 'adit', 'status' => 'in_progress', 'progress' => 65, 'priority' => 'urgent',
                    'start' => '2026-05-04', 'due' => $friday(3),
                    'children' => [
                        ['title' => 'Ekstraksi data timbang harian', 'assignee' => 'adit', 'status' => 'done', 'progress' => 100, 'start' => '2026-05-04', 'due' => '2026-06-12'],
                        ['title' => 'Perhitungan rendemen CPO & PK', 'assignee' => 'vino', 'status' => 'done', 'progress' => 100, 'priority' => 'high', 'start' => '2026-06-15', 'due' => '2026-07-24'],
                        [
                            'title' => 'Agregasi produktivitas per afdeling',
                            'assignee' => 'adit', 'status' => 'in_progress', 'progress' => 40, 'priority' => 'high',
                            'start' => $week(-2), 'due' => $friday(3),
                            'children' => [
                                ['title' => 'Hitung ton per hektar', 'assignee' => 'adit', 'status' => 'in_progress', 'progress' => 70, 'start' => $week(-2), 'due' => $friday(0)],
                                ['title' => 'Bandingkan dengan target RKAP', 'assignee' => 'adhi', 'status' => 'todo', 'progress' => 0, 'priority' => 'high', 'start' => $week(1), 'due' => $friday(3)],
                            ],
                        ],
                    ],
                ],
                [
                    // Overdue: sudah lewat tenggat, belum selesai.
                    'title' => 'Antarmuka dashboard manajemen',
                    'assignee' => 'heru', 'status' => 'in_progress', 'progress' => 55, 'priority' => 'urgent',
                    'start' => '2026-07-06', 'due' => $friday(-2),
                    'children' => [
                        ['title' => 'Grafik tren produksi bulanan', 'assignee' => 'heru', 'status' => 'done', 'progress' => 100, 'start' => '2026-07-06', 'due' => '2026-07-31'],
                        ['title' => 'Filter periode & afdeling', 'assignee' => 'heru', 'status' => 'in_progress', 'progress' => 30, 'priority' => 'high', 'start' => $week(-3), 'due' => $friday(-1)],
                    ],
                ],
                [
                    'title' => 'Ekspor laporan ke PDF',
                    'assignee' => 'adhi', 'status' => 'todo', 'progress' => 0, 'priority' => 'low',
                    'start' => $week(4), 'due' => $friday(6),
                ],
                [
                    // Belum dijadwalkan, muncul di bagian terpisah timeline.
                    'title' => 'Riset prediksi produksi berbasis cuaca',
                    'assignee' => 'adit', 'status' => 'todo', 'progress' => 0, 'priority' => 'low',
                ],
            ],

            // Jun 2026 - berjalan.
            'procurement' => [
                [
                    'title' => 'Analisis alur pengadaan saprodi',
                    'assignee' => 'yogi', 'status' => 'done', 'progress' => 100, 'priority' => 'high',
                    'start' => '2026-06-01', 'due' => '2026-06-26',
                ],
                [
                    'title' => 'Modul permintaan & persetujuan',
                    'assignee' => 'vino', 'status' => 'in_progress', 'progress' => 50, 'priority' => 'urgent',
                    'start' => '2026-06-29', 'due' => $friday(5),
                    'children' => [
                        ['title' => 'Form permintaan pupuk & bibit', 'assignee' => 'yogi', 'status' => 'done', 'progress' => 100, 'start' => '2026-06-29', 'due' => '2026-07-24'],
                        ['title' => 'Alur persetujuan berjenjang', 'assignee' => 'vino', 'status' => 'in_progress', 'progress' => 45, 'priority' => 'urgent', 'start' => $week(-2), 'due' => $friday(2)],
                        ['title' => 'Notifikasi ke pejabat penyetuju', 'assignee' => 'yogi', 'status' => 'todo', 'progress' => 0, 'start' => $week(3), 'due' => $friday(5)],
                    ],
                ],
                [
                    // Overdue.
                    'title' => 'Integrasi data vendor terdaftar',
                    'assignee' => 'vino', 'status' => 'in_progress', 'progress' => 20, 'priority' => 'high',
                    'start' => '2026-07-27', 'due' => $friday(-1),
                ],
                [
                    'title' => 'Modul evaluasi vendor',
                    'assignee' => 'yogi', 'status' => 'todo', 'progress' => 0,
                    'start' => $week(6), 'due' => $friday(9),
                ],
            ],

            // Agustus 2026, baru mulai.
            'hris' => [
                [
                    'title' => 'Pengumpulan kebutuhan SDM',
                    'assignee' => 'heru', 'status' => 'in_progress', 'progress' => 35, 'priority' => 'high',
                    'start' => $week(-1), 'due' => $friday(1),
                ],
                [
                    'title' => 'Slip gaji elektronik',
                    'assignee' => 'adit', 'status' => 'todo', 'progress' => 0, 'priority' => 'high',
                    'start' => $week(2), 'due' => $friday(5),
                ],
                [
                    'title' => 'Pengajuan cuti online',
                    'assignee' => 'heru', 'status' => 'todo', 'progress' => 0,
                    'start' => $week(6), 'due' => $friday(9),
                ],
                [
                    'title' => 'Integrasi dengan mesin absensi kantor',
                    'assignee' => null, 'status' => 'todo', 'progress' => 0, 'priority' => 'low',
                ],
            ],
        ];
    }

    /**
     * A few recent comments, including mentions, so the thread and the bell
     * both have something real in them.
     *
     * @param  array<string, Project>  $projects
     * @param  array<string, User>  $people
     */
    protected function seedComments(array $projects, array $people): void
    {
        $threads = [
            ['dashboard', 'Filter periode & afdeling', 'amar', 'Progress masih 30% padahal tenggat minggu lalu. @[user:{heru}] ada kendala di query agregasinya?'],
            ['dashboard', 'Filter periode & afdeling', 'heru', 'Query per afdeling lambat kalau rentangnya setahun. Saya coba tambah index dulu, target selesai minggu ini.'],
            ['dashboard', 'Hitung ton per hektar', 'adhi', 'Angka ton per hektar sudah cocok dengan laporan manual Juli. @[user:{adit}] lanjut ke perbandingan RKAP ya.'],
            ['procurement', 'Alur persetujuan berjenjang', 'yogi', 'Batas nilai persetujuan Kepala Divisi berapa? Di dokumen lama tertulis 50 juta, tapi kata bagian pengadaan sudah naik.'],
            ['procurement', 'Integrasi data vendor terdaftar', 'amar', 'Ini sudah lewat tenggat. @[user:{vino}] tolong update estimasi barunya di panel detail.'],
        ];

        foreach ($threads as [$projectKey, $taskTitle, $authorKey, $template]) {
            $task = Task::query()
                ->where('project_id', $projects[$projectKey]->id)
                ->where('title', $taskTitle)
                ->first();

            if ($task === null) {
                continue;
            }

            // Swap the readable placeholders for the stored mention format.
            $body = preg_replace_callback(
                '/\{(\w+)\}/',
                fn (array $match): string => (string) ($people[$match[1]]->id ?? 0),
                $template,
            );

            $comment = new Comment(['body' => $body]);
            $comment->task_id = $task->id;
            $comment->user_id = $people[$authorKey]->id;
            $comment->workspace_id = $task->workspace_id;
            $comment->save();
        }
    }

    protected function report(Workspace $workspace): void
    {
        $this->command->info("Workspace {$workspace->name} dibuat. Kata sandi semua akun: password");
        $this->command->table(
            ['Nama', 'Email', 'Jenjang', 'Role', 'Unit', 'Cakupan pemantauan'],
            [
                ['Super Admin', 'admin@perkebunan.test', 'SA', 'Super Admin', '— (di luar workspace)', 'semua workspace'],
                ['Prasetyo Mimboro', 'kadiv@perkebunan.test', 'BOD-1', 'Kepala Divisi', 'Divisi Transformasi Digital', 'seluruh workspace'],
                ['Rakhmat Akbar Sinaga', 'kasubdiv@perkebunan.test', 'BOD-2', 'Kepala Sub Divisi', 'Pengembangan Digital', 'Pengembangan Digital & turunannya'],
                ['Amar', 'amar@perkebunan.test', 'BOD-3', 'Asisten', 'Pengembangan Digital', 'project yang diikuti'],
                ['Heru', 'heru@perkebunan.test', 'BOD-4', 'ODS / Programmer', 'Pengembangan Digital', 'project yang diikuti'],
                ['Vino', 'vino@perkebunan.test', 'BOD-4', 'ODS / Programmer', 'Pengembangan Digital', 'project yang diikuti'],
                ['Yogi', 'yogi@perkebunan.test', 'BOD-4', 'ODS / Programmer', 'Pengembangan Digital', 'project yang diikuti'],
                ['Adit', 'adit@perkebunan.test', 'BOD-4', 'ODS / Programmer', 'Pengembangan Digital', 'project yang diikuti'],
                ['Adhi', 'adhi@perkebunan.test', 'BOD-4', 'ODS / Programmer', 'Pengembangan Digital', 'project yang diikuti'],
            ],
        );
    }
}
