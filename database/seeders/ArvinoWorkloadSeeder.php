<?php

namespace Database\Seeders;

use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\TaskHierarchy;
use App\Support\MonthWeek;
use App\Support\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * The second programmer in {@see AmarWorkloadSeeder}'s workspace, with the
 * backlog that comes off his own sheet of the workbook.
 *
 * Perkebunan Nusantara   ← Amar (Owner), Arvino (Member), one org unit
 *
 * Arvino works the data and portal side of the same operation — dashboards,
 * the OnePTPN hub, the SSO holding, an AI assistant — where Amar works the
 * procurement applications. Two people on one board is what gives the
 * monitoring pages something to compare: the person view has a second column,
 * and the roll-up stops being one person's total.
 *
 * The rows are transcribed from `arvino.xlsx`, the per-programmer workbook the
 * monitoring pages replace: 14 applications, 250 tasks, three levels deep, and
 * a stretch of the SSO and Boardroom work still running.
 *
 * Dates are weeks of a month (`W3 08-26`), not days, so they are widened back
 * to the calendar here: a start lands on the first day of its week, an end on
 * the last, and week four always closes on the last day of the month — which
 * is exactly how {@see MonthWeek} reads them back.
 *
 * @phpstan-type WorkbookRow array{title: string, progress: int, start: string, due: string, children?: array<int, mixed>}
 */
class ArvinoWorkloadSeeder extends Seeder
{
    /** The person this backlog belongs to. */
    protected const EMAIL = 'arvinoart@gmail.com';

    /** The workspace {@see AmarWorkloadSeeder} opens, which Arvino joins. */
    protected const WORKSPACE_SLUG = 'perkebunan-nusantara';

    public function run(): void
    {
        $tenancy = app(Tenancy::class);
        $member = $this->seedMembership();

        if ($member === null) {
            $this->command->warn('Lewati: '.self::EMAIL.' atau workspace '.self::WORKSPACE_SLUG.' belum ada.');

            return;
        }

        $user = $member->user;

        if (Task::query()->withoutGlobalScopes()->where('assignee_id', $user->id)->exists()) {
            $this->command->warn('Lewati: task '.$user->name.' sudah ada.');

            return;
        }

        $tenancy->set($member->workspace, $member);

        $count = $this->seedProjects($member, $user);

        $tenancy->forget();

        $this->command->info("{$count} task milik {$user->name} dibuat di 14 aplikasi.");
    }

    /**
     * Arvino's seat in the workspace Amar already runs.
     *
     * He joins as a Member on the same org unit: one programmer among the
     * others, with nobody below him, which is all the workbook records.
     *
     * The account is looked up, never opened: Arvino signs up himself, and a
     * seeder writing a second row under the same address would take the
     * backlog away from the one he uses. Idempotent, so re-running adopts the
     * membership that is already there, and null — nothing seeded — when
     * either the account or the workspace is missing.
     */
    protected function seedMembership(): ?WorkspaceMember
    {
        $tenancy = app(Tenancy::class);

        $workspace = $tenancy->withoutScope(
            fn (): ?Workspace => Workspace::query()->where('slug', self::WORKSPACE_SLUG)->first(),
        );

        $user = User::query()->where('email', self::EMAIL)->first();

        if ($workspace === null || $user === null) {
            return null;
        }

        return $tenancy->forWorkspace($workspace, fn (): WorkspaceMember => WorkspaceMember::firstOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            [
                'role' => WorkspaceRole::Member,
                'org_unit_id' => $workspace->root_org_unit_id,
                'joined_at' => now(),
            ],
        ));
    }

    /**
     * @return int the number of tasks written
     */
    protected function seedProjects(WorkspaceMember $member, User $user): int
    {
        $hierarchy = app(TaskHierarchy::class);
        $count = 0;

        foreach ($this->applications() as $name => [$description, $tasks]) {
            $project = Project::firstOrCreate(
                ['name' => $name],
                [
                    'org_unit_id' => $member->org_unit_id,
                    'key' => Project::generateKey($name),
                    'description' => $description,
                    'status' => 'active',
                ],
            );

            $project->members()->syncWithoutDetaching([$user->id]);

            // No assignment notifications and no roll-up while the tree is
            // written: this is historical work nobody needs to be told about,
            // and every progress figure is the one the workbook reports.
            $count += Model::withoutEvents(
                fn (): int => $this->seedBranch($hierarchy, $project, $tasks, $user),
            );
        }

        return $count;
    }

    /**
     * Write one level of the tree, then recurse into whatever hangs under it.
     *
     * Rows are written in the order the workbook numbers them, because that is
     * the order the board holds them in — and `TaskHierarchy` derives both
     * `position` and the WBS number from the order they arrive in.
     *
     * @param  array<int, WorkbookRow>  $rows
     */
    protected function seedBranch(TaskHierarchy $hierarchy, Project $project, array $rows, User $user, ?Task $parent = null): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $progress = $row['progress'];

            $task = $hierarchy->create($project, [
                'title' => $row['title'],
                'assignee_id' => $user->id,
                'status' => $this->status($progress),
                'progress' => $progress,
                'start_date' => $this->day($row['start'], false),
                'due_date' => $this->day($row['due'], true),
            ], $parent);

            $count++;

            $count += $this->seedBranch($hierarchy, $project, $row['children'] ?? [], $user, $task);
        }

        return $count;
    }

    protected function status(int $progress): TaskStatus
    {
        return match (true) {
            $progress >= 100 => TaskStatus::Done,
            $progress > 0 => TaskStatus::InProgress,
            default => TaskStatus::Todo,
        };
    }

    /**
     * Turn a `W3 08-26` label back into a calendar day.
     *
     * This is {@see MonthWeek::of()} run backwards, so every date the seeder
     * writes reads back as the label it came from. Weeks run Monday to Sunday,
     * which is why the arithmetic hangs off the day W2 opens on rather than
     * off seven day blocks from the first of the month: the leftover days a
     * month opens with are W1's, however few of them there are.
     *
     * @param  bool  $end  the closing day of that week rather than its first
     */
    protected function day(string $label, bool $end): CarbonInterface
    {
        [$week, $period] = explode(' ', $label);
        [$month, $year] = array_map('intval', explode('-', $period));
        $week = (int) mb_substr($week, 1);

        $first = Date::createFromDate(2000 + $year, $month, 1)->startOfDay();
        $second = $this->secondWeekOpens($first);

        if (! $end) {
            return $week <= 1 ? $first : $first->addDays($second - 1 + ($week - 2) * 7);
        }

        if ($week <= 1) {
            return $first->addDays($second - 2);
        }

        // Week four runs to the end of the month, however long that is.
        return $week >= MonthWeek::PER_MONTH
            ? $first->endOfMonth()->startOfDay()
            : $first->addDays($second - 2 + ($week - 1) * 7);
    }

    /**
     * The day of the month W2 opens on, counted exactly as `MonthWeek` does.
     */
    protected function secondWeekOpens(CarbonInterface $first): int
    {
        $lead = (8 - $first->dayOfWeekIso) % 7;

        return $lead < 4 ? $lead + 8 : $lead + 1;
    }

    /**
     * The fourteen applications, each with its tree of work.
     *
     * @return array<string, array{0: string, 1: array<int, WorkbookRow>}>
     */
    protected function applications(): array
    {
        return [
            'Google Cloud Platform' => [
                'Penyiapan VM dan program penarikan data di Google Cloud Platform.',
                [
                    ['title' => 'Pembuatan VM', 'progress' => 100, 'start' => 'W1 07-25', 'due' => 'W3 07-25', 'children' => [
                        ['title' => 'Pengaturan VM', 'progress' => 100, 'start' => 'W1 07-25', 'due' => 'W3 07-25'],
                    ]],
                    ['title' => 'Pembuatan Program Penarikan Data', 'progress' => 100, 'start' => 'W1 07-25', 'due' => 'W3 07-25'],
                ],
            ],
            'Dashboard RAGAB' => [
                'Dashboard highlight kinerja per komoditas: kelapa sawit, tebu, teh, kopi dan karet.',
                [
                    ['title' => 'Highlight Kinerja Kelapa Sawit', 'progress' => 100, 'start' => 'W1 07-25', 'due' => 'W2 07-25', 'children' => [
                        ['title' => 'Identifikasi Data', 'progress' => 100, 'start' => 'W1 07-25', 'due' => 'W2 07-25'],
                        ['title' => 'Penarikan Data', 'progress' => 100, 'start' => 'W1 07-25', 'due' => 'W2 07-25'],
                        ['title' => 'Pembuatan Dashboard', 'progress' => 100, 'start' => 'W2 07-25', 'due' => 'W3 07-25'],
                    ]],
                    ['title' => 'Highlight Kinerja Tebu', 'progress' => 100, 'start' => 'W4 07-25', 'due' => 'W1 08-25', 'children' => [
                        ['title' => 'Identifikasi Data', 'progress' => 100, 'start' => 'W4 07-25', 'due' => 'W1 08-25'],
                        ['title' => 'Penarikan Data', 'progress' => 100, 'start' => 'W4 07-25', 'due' => 'W1 08-25'],
                        ['title' => 'Pembuatan Dashboard', 'progress' => 100, 'start' => 'W1 08-25', 'due' => 'W2 08-25'],
                    ]],
                    ['title' => 'Highlight Kinerja Teh', 'progress' => 100, 'start' => 'W3 08-25', 'due' => 'W4 08-25', 'children' => [
                        ['title' => 'Identifikasi Data', 'progress' => 100, 'start' => 'W3 08-25', 'due' => 'W4 08-25'],
                        ['title' => 'Penarikan Data', 'progress' => 100, 'start' => 'W3 08-25', 'due' => 'W4 08-25'],
                        ['title' => 'Pembuatan Dashboard', 'progress' => 100, 'start' => 'W4 08-25', 'due' => 'W1 09-25'],
                    ]],
                    ['title' => 'Highlight Kinerja Kopi', 'progress' => 100, 'start' => 'W2 09-25', 'due' => 'W3 09-25', 'children' => [
                        ['title' => 'Identifikasi Data', 'progress' => 100, 'start' => 'W2 09-25', 'due' => 'W3 09-25'],
                        ['title' => 'Penarikan Data', 'progress' => 100, 'start' => 'W2 09-25', 'due' => 'W3 09-25'],
                        ['title' => 'Pembuatan Dasbhoard', 'progress' => 100, 'start' => 'W3 09-25', 'due' => 'W4 09-25'],
                    ]],
                    ['title' => 'Highlight Kinerja Karet', 'progress' => 100, 'start' => 'W1 10-25', 'due' => 'W2 10-25', 'children' => [
                        ['title' => 'Identifikasi Data', 'progress' => 100, 'start' => 'W1 10-25', 'due' => 'W2 10-25'],
                        ['title' => 'Penarikan Data', 'progress' => 100, 'start' => 'W1 10-25', 'due' => 'W2 10-25'],
                        ['title' => 'Pembuatan Dashboard', 'progress' => 100, 'start' => 'W2 10-25', 'due' => 'W3 10-25'],
                    ]],
                ],
            ],
            'Aplikasi Content Boardroom' => [
                'Aplikasi pengelolaan konten Boardroom, sisi frontend dan backend.',
                [
                    ['title' => 'Frontend', 'progress' => 100, 'start' => 'W3 09-25', 'due' => 'W1 11-25'],
                    ['title' => 'Backend', 'progress' => 100, 'start' => 'W4 09-25', 'due' => 'W1 11-25'],
                ],
            ],
            'Aplikasi Pica Boardroom' => [
                'Pica Boardroom untuk capaian produksi gula, tanaman sawit, cash cost, rendemen dan tekpol.',
                [
                    ['title' => 'Pica Capaian Produksi Gula', 'progress' => 100, 'start' => 'W2 10-25', 'due' => 'W4 11-25'],
                    ['title' => 'Pica Tanaman Sawit', 'progress' => 100, 'start' => 'W2 10-25', 'due' => 'W4 11-25'],
                    ['title' => 'Pica Cash Cost Off Farm Tebu', 'progress' => 100, 'start' => 'W2 10-25', 'due' => 'W4 11-25'],
                    ['title' => 'Pica Capaian Rendemen Tebu', 'progress' => 100, 'start' => 'W2 10-25', 'due' => 'W4 11-25'],
                    ['title' => 'Pica Tekpol Rendemen', 'progress' => 100, 'start' => 'W2 10-25', 'due' => 'W4 11-25'],
                    ['title' => 'Pica Tekpol PKS', 'progress' => 100, 'start' => 'W2 10-25', 'due' => 'W4 11-25'],
                ],
            ],
            'Aplikasi OnePTPN Hub' => [
                'Portal terpadu PTPN: One Gate, One Data, One Library, asisten AI dokumen dan gamifikasi.',
                [
                    ['title' => 'Fondasi Aplikasi & Autentikasi', 'progress' => 100, 'start' => 'W2 10-25', 'due' => 'W4 04-26', 'children' => [
                        ['title' => 'Halaman Login & Sesi Pengguna', 'progress' => 100, 'start' => 'W2 10-25', 'due' => 'W3 02-26'],
                        ['title' => 'Kerangka Tata Letak Aplikasi', 'progress' => 100, 'start' => 'W2 10-25', 'due' => 'W4 04-26'],
                    ]],
                    ['title' => 'Dashboard & Monitoring', 'progress' => 95, 'start' => 'W2 10-25', 'due' => 'W1 06-26', 'children' => [
                        ['title' => 'Statistik & Widget Dashboard', 'progress' => 95, 'start' => 'W2 10-25', 'due' => 'W1 06-26'],
                    ]],
                    ['title' => 'One Gate - Organisasi & SDM', 'progress' => 95, 'start' => 'W1 11-25', 'due' => 'W1 07-26', 'children' => [
                        ['title' => 'Struktur Organisasi', 'progress' => 95, 'start' => 'W1 11-25', 'due' => 'W1 07-26'],
                        ['title' => 'Bagan Perusahaan', 'progress' => 100, 'start' => 'W1 11-25', 'due' => 'W1 03-26'],
                        ['title' => 'Data Pegawai', 'progress' => 100, 'start' => 'W1 11-25', 'due' => 'W1 03-26'],
                        ['title' => 'Manajemen Peran (Roles & Permissions)', 'progress' => 100, 'start' => 'W1 11-25', 'due' => 'W1 02-26'],
                        ['title' => 'Manajemen Jabatan (Positions)', 'progress' => 100, 'start' => 'W1 11-25', 'due' => 'W1 02-26'],
                        ['title' => 'Hak Akses Pegawai', 'progress' => 100, 'start' => 'W2 11-25', 'due' => 'W1 02-26'],
                        ['title' => 'Hak Akses Organisasi', 'progress' => 100, 'start' => 'W1 03-26', 'due' => 'W1 03-26'],
                        ['title' => 'Kalender Hari Libur', 'progress' => 100, 'start' => 'W1 06-26', 'due' => 'W1 06-26'],
                    ]],
                    ['title' => 'Profil & Aktivitas Pengguna', 'progress' => 100, 'start' => 'W1 11-25', 'due' => 'W1 03-26', 'children' => [
                        ['title' => 'Profil Pengguna', 'progress' => 100, 'start' => 'W1 11-25', 'due' => 'W1 03-26'],
                        ['title' => 'Riwayat Aktivitas', 'progress' => 100, 'start' => 'W2 11-25', 'due' => 'W1 03-26'],
                    ]],
                    ['title' => 'One Gate - Portal Aplikasi', 'progress' => 100, 'start' => 'W2 11-25', 'due' => 'W1 02-26', 'children' => [
                        ['title' => 'Katalog & Akses Aplikasi', 'progress' => 100, 'start' => 'W2 11-25', 'due' => 'W1 02-26'],
                        ['title' => 'Hak Akses Aplikasi per Organisasi', 'progress' => 100, 'start' => 'W1 02-26', 'due' => 'W1 02-26'],
                    ]],
                    ['title' => 'One Data - Pembuatan Dokumen SOP', 'progress' => 90, 'start' => 'W3 11-25', 'due' => 'W4 07-26', 'children' => [
                        ['title' => 'Pembuatan Dokumen Baru', 'progress' => 90, 'start' => 'W3 11-25', 'due' => 'W4 07-26'],
                        ['title' => 'Peran Organisasi dalam Alur Dokumen', 'progress' => 100, 'start' => 'W4 11-25', 'due' => 'W4 02-26'],
                        ['title' => 'Draf Dokumen', 'progress' => 90, 'start' => 'W1 02-26', 'due' => 'W3 07-26'],
                    ]],
                    ['title' => 'One Data - Persetujuan & Monitoring Dokumen', 'progress' => 90, 'start' => 'W3 11-25', 'due' => 'W4 06-26', 'children' => [
                        ['title' => 'Upload Dokumen (One Data)', 'progress' => 100, 'start' => 'W1 11-25', 'due' => 'W1 06-26'],
                        ['title' => 'Monitoring Proses Dokumen', 'progress' => 90, 'start' => 'W3 11-25', 'due' => 'W3 06-26'],
                        ['title' => 'Alur Persetujuan Dokumen', 'progress' => 90, 'start' => 'W4 12-25', 'due' => 'W4 06-26', 'children' => [
                            ['title' => 'Perubahan Dokumen Saat Proses Persetujuan', 'progress' => 90, 'start' => 'W3 05-26', 'due' => 'W4 06-26'],
                        ]],
                        ['title' => 'Konfigurasi Alur Persetujuan', 'progress' => 95, 'start' => 'W4 01-26', 'due' => 'W3 05-26'],
                        ['title' => 'Dashboard SLA', 'progress' => 95, 'start' => 'W4 03-26', 'due' => 'W4 05-26'],
                        ['title' => 'Dokumen Perlu Perhatian', 'progress' => 100, 'start' => 'W3 05-26', 'due' => 'W3 05-26'],
                    ]],
                    ['title' => 'One Library - Repositori & Detail Dokumen', 'progress' => 95, 'start' => 'W4 11-25', 'due' => 'W4 06-26', 'children' => [
                        ['title' => 'Unggah Dokumen Eksisting', 'progress' => 95, 'start' => 'W4 11-25', 'due' => 'W1 06-26'],
                        ['title' => 'Daftar & Detail Dokumen', 'progress' => 100, 'start' => 'W4 11-25', 'due' => 'W4 06-26'],
                        ['title' => 'Pengelolaan Dokumen oleh Admin', 'progress' => 95, 'start' => 'W4 11-25', 'due' => 'W4 05-26', 'children' => [
                            ['title' => 'Edit Dokumen oleh Admin', 'progress' => 100, 'start' => 'W4 12-25', 'due' => 'W3 02-26'],
                        ]],
                        ['title' => 'Pencarian Dokumen', 'progress' => 100, 'start' => 'W1 12-25', 'due' => 'W3 02-26'],
                        ['title' => 'Persetujuan Dokumen (One Library)', 'progress' => 100, 'start' => 'W3 12-25', 'due' => 'W3 02-26'],
                    ]],
                    ['title' => 'Asisten AI Dokumen (EVA)', 'progress' => 90, 'start' => 'W2 12-25', 'due' => 'W4 04-26', 'children' => [
                        ['title' => 'Chat Asisten Dokumen', 'progress' => 90, 'start' => 'W2 12-25', 'due' => 'W4 04-26'],
                    ]],
                    ['title' => 'Dokumen KUPAS', 'progress' => 95, 'start' => 'W3 12-25', 'due' => 'W4 02-26', 'children' => [
                        ['title' => 'Pengajuan & Review KUPAS', 'progress' => 95, 'start' => 'W3 12-25', 'due' => 'W1 02-26', 'children' => [
                            ['title' => 'Detail KUPAS', 'progress' => 95, 'start' => 'W3 12-25', 'due' => 'W1 02-26'],
                        ]],
                        ['title' => 'Manajemen KUPAS', 'progress' => 95, 'start' => 'W1 02-26', 'due' => 'W4 02-26'],
                    ]],
                    ['title' => 'Pemahaman Dokumen & Gamifikasi', 'progress' => 95, 'start' => 'W3 12-25', 'due' => 'W1 03-26', 'children' => [
                        ['title' => 'Review & Saran Pengguna', 'progress' => 100, 'start' => 'W3 12-25', 'due' => 'W1 02-26'],
                        ['title' => 'Penukaran Hadiah', 'progress' => 95, 'start' => 'W2 01-26', 'due' => 'W1 03-26', 'children' => [
                            ['title' => 'Manajemen Penukaran Hadiah', 'progress' => 95, 'start' => 'W2 01-26', 'due' => 'W1 02-26'],
                            ['title' => 'Hak Akses Organisasi Penukaran', 'progress' => 100, 'start' => 'W4 01-26', 'due' => 'W1 02-26'],
                        ]],
                        ['title' => 'Poin Transaksi', 'progress' => 100, 'start' => 'W1 02-26', 'due' => 'W1 02-26'],
                        ['title' => 'Hak Akses Organisasi Dokumen (One Library)', 'progress' => 100, 'start' => 'W1 02-26', 'due' => 'W1 02-26'],
                        ['title' => 'Tampilan Struktur Perusahaan & Profil Jabatan', 'progress' => 100, 'start' => 'W3 02-26', 'due' => 'W3 02-26'],
                    ]],
                    ['title' => 'Manajemen Tugas Saya', 'progress' => 90, 'start' => 'W2 01-26', 'due' => 'W2 07-26', 'children' => [
                        ['title' => 'Daftar & Notifikasi Tugas Saya', 'progress' => 90, 'start' => 'W2 01-26', 'due' => 'W2 07-26'],
                    ]],
                    ['title' => 'Editor Dokumen Daring', 'progress' => 100, 'start' => 'W2 01-26', 'due' => 'W1 02-26', 'children' => [
                        ['title' => 'Editor Dokumen Daring', 'progress' => 100, 'start' => 'W2 01-26', 'due' => 'W1 02-26'],
                    ]],
                    ['title' => 'Dokumen KPPS', 'progress' => 95, 'start' => 'W1 06-26', 'due' => 'W4 06-26', 'children' => [
                        ['title' => 'Daftar & Upload KPPS', 'progress' => 100, 'start' => 'W1 06-26', 'due' => 'W4 06-26'],
                        ['title' => 'Detail KPPS', 'progress' => 100, 'start' => 'W1 06-26', 'due' => 'W4 06-26'],
                        ['title' => 'Edit KPPS', 'progress' => 90, 'start' => 'W3 06-26', 'due' => 'W4 06-26'],
                    ]],
                    ['title' => 'Cetak & Distribusi Dokumen', 'progress' => 100, 'start' => 'W1 06-26', 'due' => 'W1 06-26', 'children' => [
                        ['title' => 'Cetak Dokumen', 'progress' => 100, 'start' => 'W1 06-26', 'due' => 'W1 06-26'],
                    ]],
                ],
            ],
            'Migrasi & Integrasi Data' => [
                'Migrasi dan integrasi data lintas platform: Redshift, Supabase, GCP, CTH dan BigQuery.',
                [
                    ['title' => 'Program Lambda: Redshift ke Supabase', 'progress' => 100, 'start' => 'W1 07-26', 'due' => 'W1 07-26'],
                    ['title' => 'Program Migrasi Data: GCP ke CTH', 'progress' => 100, 'start' => 'W2 07-26', 'due' => 'W2 07-26'],
                    ['title' => 'Validasi Perhitungan Dashboard CTH & BigQuery', 'progress' => 100, 'start' => 'W2 07-26', 'due' => 'W2 07-26'],
                ],
            ],
            'Dashboard AnLab' => [
                'Dashboard AnLab, dari mockup sampai penarikan data API ke Redshift.',
                [
                    ['title' => 'Pembuatan Mockup', 'progress' => 100, 'start' => 'W2 07-26', 'due' => 'W2 07-26'],
                    ['title' => 'Perumusan & Pengembangan Dashboard', 'progress' => 100, 'start' => 'W2 07-26', 'due' => 'W2 07-26', 'children' => [
                        ['title' => 'Merumuskan Data & Tampilan yang Diharapkan', 'progress' => 100, 'start' => 'W2 07-26', 'due' => 'W2 07-26'],
                        ['title' => 'Menyusun API untuk Dikonsumsi', 'progress' => 100, 'start' => 'W2 07-26', 'due' => 'W2 07-26'],
                        ['title' => 'Penyusunan Dashboard', 'progress' => 100, 'start' => 'W2 07-26', 'due' => 'W2 07-26'],
                        ['title' => 'Finalisasi Dashboard', 'progress' => 100, 'start' => 'W2 07-26', 'due' => 'W2 07-26'],
                    ]],
                    ['title' => 'Penarikan Data API AnLab ke Redshift', 'progress' => 100, 'start' => 'W2 08-26', 'due' => 'W2 08-26'],
                ],
            ],
            'Dashboard SGN' => [
                'Perbaikan perhitungan finance performance dan dashboard eksekutif SGN.',
                [
                    ['title' => 'Perbaikan Perhitungan Highlight Finance Performance', 'progress' => 100, 'start' => 'W2 07-26', 'due' => 'W2 07-26'],
                    ['title' => 'Dashboard Eksekutif SGN', 'progress' => 100, 'start' => 'W4 07-26', 'due' => 'W4 07-26'],
                ],
            ],
            'Integrasi SAP ke Redshift' => [
                'Penarikan data SAP ke Redshift, termasuk upgrade metode DELTA.',
                [
                    ['title' => 'Script Custom Penarikan SAP ke Redshift', 'progress' => 100, 'start' => 'W3 07-26', 'due' => 'W3 07-26'],
                    ['title' => 'Upgrade Metode DELTA (PA0319)', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                ],
            ],
            'Aplikasi Boardroom' => [
                'Boardroom: login AgHRIS, hak akses menu, infrastruktur deployment dan integrasi SSO Holding.',
                [
                    ['title' => 'Backend - Integrasi Login AgHRIS', 'progress' => 100, 'start' => 'W4 07-26', 'due' => 'W4 07-26', 'children' => [
                        ['title' => 'Klien & Orkestrasi Autentikasi AgHRIS', 'progress' => 100, 'start' => 'W4 07-26', 'due' => 'W4 07-26'],
                        ['title' => 'Fallback ke Login Lokal Saat AgHRIS Down', 'progress' => 100, 'start' => 'W4 07-26', 'due' => 'W4 07-26'],
                    ]],
                    ['title' => 'Backend - Hak Akses Menu (Group Access)', 'progress' => 100, 'start' => 'W4 07-26', 'due' => 'W2 08-26', 'children' => [
                        ['title' => 'Model & Struktur Group Access', 'progress' => 100, 'start' => 'W4 07-26', 'due' => 'W4 07-26'],
                        ['title' => 'Auto-assign & Migrasi Data Group Default', 'progress' => 100, 'start' => 'W4 07-26', 'due' => 'W4 07-26'],
                        ['title' => 'Assign Group ke Username Tanpa User Terdaftar', 'progress' => 100, 'start' => 'W2 08-26', 'due' => 'W2 08-26'],
                        ['title' => 'Audit & Log Akses Menu', 'progress' => 100, 'start' => 'W2 08-26', 'due' => 'W2 08-26'],
                        ['title' => 'Penyempurnaan UI Admin Tree Checkbox', 'progress' => 100, 'start' => 'W2 08-26', 'due' => 'W2 08-26'],
                    ]],
                    ['title' => 'Frontend - Login & Autentikasi', 'progress' => 100, 'start' => 'W1 08-26', 'due' => 'W1 08-26', 'children' => [
                        ['title' => 'Placeholder Username Dukung NIK SAP', 'progress' => 100, 'start' => 'W1 08-26', 'due' => 'W1 08-26'],
                    ]],
                    ['title' => 'Backend - Konfigurasi & Infrastruktur Deployment', 'progress' => 100, 'start' => 'W1 08-26', 'due' => 'W2 08-26', 'children' => [
                        ['title' => 'Konfigurasi CORS/CSRF Multi Frontend', 'progress' => 100, 'start' => 'W1 08-26', 'due' => 'W1 08-26'],
                        ['title' => 'Optimasi Docker & Script Deployment', 'progress' => 100, 'start' => 'W2 08-26', 'due' => 'W2 08-26'],
                    ]],
                    ['title' => 'Frontend - Empty State Dashboard', 'progress' => 100, 'start' => 'W1 08-26', 'due' => 'W2 08-26', 'children' => [
                        ['title' => 'Komponen & Pesan Empty State Dashboard', 'progress' => 100, 'start' => 'W1 08-26', 'due' => 'W1 08-26'],
                        ['title' => 'Perbaikan Ikon di Production', 'progress' => 100, 'start' => 'W2 08-26', 'due' => 'W2 08-26'],
                    ]],
                    ['title' => 'Frontend - Integrasi Log Akses Menu', 'progress' => 100, 'start' => 'W2 08-26', 'due' => 'W2 08-26', 'children' => [
                        ['title' => 'Pencatatan Akses Menu & Side Menu ke Backend', 'progress' => 100, 'start' => 'W2 08-26', 'due' => 'W2 08-26'],
                    ]],
                    ['title' => 'Backend - Integrasi Login SSO Holding', 'progress' => 60, 'start' => 'W3 08-26', 'due' => 'W4 08-26', 'children' => [
                        ['title' => 'Callback & Integrasi Autentikasi SSO', 'progress' => 60, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Logika & Dokumentasi Kontrol Akses Registrasi Admin', 'progress' => 50, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                    ]],
                    ['title' => 'Frontend - Integrasi Login SSO Holding', 'progress' => 55, 'start' => 'W3 08-26', 'due' => 'W4 08-26', 'children' => [
                        ['title' => 'Alur Login & Redirect SSO', 'progress' => 60, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Tombol & Komponen SSO', 'progress' => 50, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                        ['title' => 'Halaman & Middleware Akses Ditolak', 'progress' => 40, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                    ]],
                ],
            ],
            'Update Login AGHRIS - Website Boardroom' => [
                'Pembaruan alur login AGHRIS pada website Boardroom.',
                [
                    // The workbook breaks this one into nothing, so the single
                    // row it does carry is the work itself.
                    ['title' => 'Update Login AGHRIS - Website Boardroom', 'progress' => 100, 'start' => 'W1 08-26', 'due' => 'W1 08-26'],
                ],
            ],
            'Aplikasi SSO' => [
                'SSO Holding berbasis Better Auth: passkey, connected apps, akun lokal dan log aktivitas.',
                [
                    ['title' => 'Fondasi Proyek & Autentikasi Better Auth', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26', 'children' => [
                        ['title' => 'Inisialisasi Proyek Next.js & Rename ke Holding SSO', 'progress' => 100, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Migrasi ke Better Auth (Desain)', 'progress' => 80, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                    ]],
                    ['title' => 'Kerangka Dashboard, Tema & Navigasi', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26', 'children' => [
                        ['title' => 'Desain Shared Shell Dashboard & Admin', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Toggle Tema Gelap/Terang', 'progress' => 100, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Sidebar Drawer (Portal, Animasi, Style Responsif)', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Konsistensi Styling Logo & Gambar', 'progress' => 100, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                    ]],
                    ['title' => 'Login & Autentikasi Pengguna', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26', 'children' => [
                        ['title' => 'Toggle Visibilitas Password', 'progress' => 100, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Login dengan Google & Verifikasi Email', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Penautan Akun Google Saat Consent', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Redirect Autentikasi Menggunakan Public Origin', 'progress' => 100, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                    ]],
                    ['title' => 'Autentikasi Passkey', 'progress' => 85, 'start' => 'W3 08-26', 'due' => 'W3 08-26', 'children' => [
                        ['title' => 'Implementasi Passkey (UI & Database)', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Manajemen State & Local Storage Passkey', 'progress' => 85, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Identifikasi Perangkat Passkey', 'progress' => 85, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Gaya Tombol Bersama Google & Passkey', 'progress' => 100, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                    ]],
                    ['title' => 'Manajemen Klien Aplikasi (Connected Apps)', 'progress' => 80, 'start' => 'W3 08-26', 'due' => 'W4 08-26', 'children' => [
                        ['title' => 'Fitur Connected Apps & Skema Database', 'progress' => 85, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Dukungan Launch URL & Validasi', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Formulir & Tampilan Klien Aplikasi', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Penanganan Error Klien Nonaktif', 'progress' => 60, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                    ]],
                    ['title' => 'Dokumentasi & Integrasi untuk Aplikasi Klien', 'progress' => 75, 'start' => 'W3 08-26', 'due' => 'W4 08-26', 'children' => [
                        ['title' => 'Halaman Dokumentasi Integrasi SSO', 'progress' => 80, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Contoh Launch & Callback URL', 'progress' => 80, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Contoh Response & Rincian Hak Akses Data', 'progress' => 80, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Tab Metode Integrasi & Contoh Sesi/Redirect', 'progress' => 60, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                    ]],
                    ['title' => 'Manajemen Akun Lokal di Luar AGHRIS', 'progress' => 85, 'start' => 'W3 08-26', 'due' => 'W4 08-26', 'children' => [
                        ['title' => 'Implementasi Sistem Akun Lokal', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Formulir Pembuatan & Pengelolaan Akun', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Penghapusan Akun dengan Log Aktivitas', 'progress' => 85, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Pagination & Filter Daftar Admin', 'progress' => 85, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Daftar Pengguna AGHRIS di Panel Admin', 'progress' => 80, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                    ]],
                    ['title' => 'Whitelist & Data Pegawai AGHRIS', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26', 'children' => [
                        ['title' => 'Skrip Manajemen Whitelist Admin', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Pemisahan Kontak Telepon AGHRIS & WhatsApp', 'progress' => 90, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                    ]],
                    ['title' => 'Log Aktivitas & Manajemen Sesi', 'progress' => 80, 'start' => 'W3 08-26', 'due' => 'W4 08-26', 'children' => [
                        ['title' => 'Sistem Pencatatan Aktivitas', 'progress' => 85, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Konteks Sesi pada Log Aktivitas', 'progress' => 85, 'start' => 'W3 08-26', 'due' => 'W3 08-26'],
                        ['title' => 'Widget Manajemen Sesi', 'progress' => 75, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                        ['title' => 'Personalisasi & Mode Headless Widget Sesi', 'progress' => 60, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                    ]],
                    ['title' => 'Tombol SSO Terintegrasi (HoldingSsoButton)', 'progress' => 70, 'start' => 'W4 08-26', 'due' => 'W4 08-26', 'children' => [
                        ['title' => 'Komponen Tombol SSO & Fitur Salin', 'progress' => 80, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                        ['title' => 'Konsistensi Styling Tombol & Widget Sesi', 'progress' => 80, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                        ['title' => 'Keamanan Cookie Lintas Situs untuk Consent', 'progress' => 70, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                    ]],
                ],
            ],
            'Laporan Harian Produksi Kelapa Sawit' => [
                'Penyusunan dan penyelesaian laporan harian produksi kelapa sawit.',
                [
                    ['title' => 'Penyusunan Laporan', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26', 'children' => [
                        ['title' => 'Merumuskan Data & Tampilan', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                        ['title' => 'Mengkompilasi Data menjadi Tabel', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                        ['title' => 'Penyusunan Dashboard/Laporan', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                        ['title' => 'Melanjutkan Penyusunan Dashboard/Laporan', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                        ['title' => 'Finalisasi & Penyempurnaan Laporan', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                    ]],
                    ['title' => 'Penyelesaian Laporan', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                ],
            ],
            'Aplikasi AI Assistant "Sri" (Realtime Voice)' => [
                'Asisten suara real-time Sri: mesin percakapan, avatar interaktif, analisis dan pelaporan.',
                [
                    ['title' => 'Backend - Mesin Percakapan Suara Real-time', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                        ['title' => 'Fungsi Dasar Realtime Voice & API Routes', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Fungsi Inti Realtime Voice "Erin AI" & Unit Test', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'API Routes Search & Avatar Token', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Update Model Pencarian ke gpt-5.6-luna', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                        ['title' => 'Penyempurnaan RealtimeVoicePrompt & Single Function Call', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'RealtimeVoicePrompt v2.3 - Instruksi Single View', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'RealtimeVoicePrompt v2.5 - Penyempurnaan Single View', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'RealtimeVoicePrompt v2.6 - Single Function Call per Response', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                        ['title' => 'Perbaikan Panel Behavior Saat Panggilan', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Panel Hanya Overwrite Konten (Cegah Empty State)', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Cegah Redraw Berulang, Tampilan Konsisten', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Terapkan Perubahan View Secara Langsung', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                        ['title' => 'Refactor Error Handling, Timeout & Unit Test', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Update Pengaturan Timeout Pencarian AI', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Refactor Error Handling Panggilan & Test RealtimeVoice', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Refactor Instruksi CapabilityTools & RealtimeVoiceSession', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                    ]],
                    ['title' => 'Avatar Interaktif (Robot & Manusia)', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                        ['title' => 'Video Klip & Pengaturan Audio Avatar Robot', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Video Klip Avatar Robot & Pengaturan Audio', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Perbaikan Tinggi Media Box Mobile & Test Layout', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Penyempurnaan RobotAvatar Video & Opsi Focus', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                        ['title' => 'Dukungan Multi-Bahasa & Mode VideoGpt', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Dukungan Bahasa pada Konfigurasi Avatar', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Mode VideoGpt dengan OpenAI Audio Passthrough', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                        ['title' => 'Avatar Manusia (Human Clip, Foto Profil, Bahasa)', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Dukungan Human Clip untuk AI Assistant', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Dukungan Bahasa Inggris untuk Human AI Avatar', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Foto Profil Manusia Baru (PNG/WEBP)', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                        ['title' => 'Integrasi Menu Mobile', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Integrasi AI Assistant dengan Menu Mobile', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                    ]],
                    ['title' => 'Analisis, Pelaporan & Capability Tools', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                        ['title' => 'ExecDashboard & Unit Test Analisis', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Desain Tampilan ExecDashboard & Test', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Unit Test Analisis & Kalkulator Erin AI', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Timing Tracking Tahap Analisis', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                        ['title' => 'Progress Display & Report Fetching', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Progress Display Analisis & Report Fetching', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'ReportViewBuilder - Row Selection & Summary', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Indikator Progress Panel Analisis & Report Fetching', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                        ['title' => 'Capability Tool & Penjelasan Fungsi ERIN', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Capability Tool Penjelasan Fungsi ERIN', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Penyempurnaan Penjelasan ERIN & ChatPanel', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                        ['title' => 'Perbaikan Rentang Tanggal Laporan', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Batas Rentang Tanggal & Error Handling Laporan', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                    ]],
                    ['title' => 'Rebranding "Erin AI" menjadi "Sri" & Penyempurnaan UX', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                        ['title' => 'Rename Referensi Erin AI -> Sri AI -> Sri', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Rename Referensi "Erin AI" ke "Sri AI"', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Rename Referensi "Sri AI" ke "Sri"', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                        ['title' => 'Penyederhanaan Tagline & Deskripsi', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Update Teks Tagline Entry', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Penyederhanaan Deskripsi & Tagline AI Assistant', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Update Ukuran Teks & Konten Entry/Index', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                    ]],
                    ['title' => 'Panel Profil Risk Management & Log Aktivitas Pengguna', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                        ['title' => 'Panel Profil Risk Management', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Panel Profil untuk Risk Management Officials', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                            ['title' => 'Panduan Instruksi Pengambilan Profil Risk Management', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                        ['title' => 'User Activity Tracking Panel', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Panel Pelacakan Aktivitas Pengguna', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                    ]],
                    ['title' => 'Modul Keuangan PTPN III', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                        ['title' => 'Data Kumulatif Keuangan', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26', 'children' => [
                            ['title' => 'Fungsi Keuangan PTPN III Data Kumulatif s/d Juli 2026', 'progress' => 100, 'start' => 'W1 09-26', 'due' => 'W1 09-26'],
                        ]],
                    ]],
                ],
            ],
        ];
    }
}
