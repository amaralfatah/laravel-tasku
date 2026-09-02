<?php

namespace Database\Seeders;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\TaskHierarchy;
use App\Support\MonthWeek;
use App\Support\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * Amar Al Fatah's real backlog, transcribed from the per-programmer workbook
 * the monitoring pages replace.
 *
 * It exists so the person page and the Excel export have a sheet's worth of
 * genuine work to render — five applications, 111 tasks, two levels deep,
 * one of them still unfinished. {@see DemoWorkspaceSeeder} seeds the people
 * and leaves the boards empty; this one fills a single member's.
 *
 * Dates in the workbook are weeks of a month (`W3 08-26`), not days, so they
 * are widened back to the calendar here: a start lands on the first day of its
 * week, an end on the last, and week four always closes on the last day of the
 * month — which is exactly how {@see MonthWeek} reads them back.
 *
 * @phpstan-type WorkbookLeaf array{0: string, 1: int, 2: string, 3: string}
 * @phpstan-type WorkbookRow array{0: string, 1: int, 2: string, 3: string, 4?: array<int, WorkbookLeaf>}
 */
class AmarWorkloadSeeder extends Seeder
{
    /** The member this backlog belongs to, seeded by DemoWorkspaceSeeder. */
    protected const EMAIL = 'amar@perkebunan.test';

    public function run(): void
    {
        $user = User::query()->where('email', self::EMAIL)->first();

        if ($user === null) {
            $this->command->warn('Lewati: akun '.self::EMAIL.' belum ada, jalankan DemoWorkspaceSeeder dulu.');

            return;
        }

        $member = WorkspaceMember::query()->where('user_id', $user->id)->first();

        if ($member === null) {
            $this->command->warn('Lewati: '.self::EMAIL.' belum menjadi anggota workspace mana pun.');

            return;
        }

        if (Task::query()->where('assignee_id', $user->id)->exists()) {
            $this->command->warn('Lewati: task '.$user->name.' sudah ada.');

            return;
        }

        $tenancy = app(Tenancy::class);
        $tenancy->set($member->workspace, $member);

        $count = $this->seedProjects($member, $user);

        $tenancy->forget();

        $this->command->info("{$count} task milik {$user->name} dibuat di 5 aplikasi.");
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
     * The WBS numbers are not seeded: `TaskHierarchy` derives them from the
     * order the rows are written in, and {@see inStartOrder} makes that the
     * order the work actually started in.
     *
     * @param  array<int, WorkbookRow>  $rows
     */
    protected function seedBranch(TaskHierarchy $hierarchy, Project $project, array $rows, User $user, ?Task $parent = null): int
    {
        $count = 0;

        foreach ($this->inStartOrder($rows) as $row) {
            [$title, $progress, $start, $end] = $row;

            $task = $hierarchy->create($project, [
                'title' => $title,
                'assignee_id' => $user->id,
                'status' => $this->status($progress),
                'progress' => $progress,
                'start_date' => $this->day($start, false),
                'due_date' => $this->day($end, true),
            ], $parent);

            $count++;

            $count += $this->seedBranch($hierarchy, $project, $row[4] ?? [], $user, $task);
        }

        return $count;
    }

    /**
     * The rows of one level, earliest start first.
     *
     * The workbook lists work in the order it was written down, which is close
     * to but not the same as the order it began; sorting here is what makes a
     * WBS number read as a sequence. The sort is stable, so rows that start in
     * the same week keep the order the workbook gave them.
     *
     * @param  array<int, WorkbookRow>  $rows
     * @return array<int, WorkbookRow>
     */
    protected function inStartOrder(array $rows): array
    {
        usort($rows, fn (array $left, array $right): int => $this->day($left[2], false) <=> $this->day($right[2], false));

        return $rows;
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
     * @param  bool  $end  the closing day of that week rather than its first
     */
    protected function day(string $label, bool $end): CarbonInterface
    {
        [$week, $period] = explode(' ', $label);
        [$month, $year] = array_map('intval', explode('-', $period));
        $week = (int) mb_substr($week, 1);

        $first = Date::createFromDate(2000 + $year, $month, 1)->startOfDay();

        if (! $end) {
            return $first->addDays(($week - 1) * 7);
        }

        // Week four runs to the end of the month, however long that is.
        return $week >= MonthWeek::PER_MONTH
            ? $first->endOfMonth()->startOfDay()
            : $first->addDays($week * 7 - 1);
    }

    /**
     * The five applications, each with its tree of work.
     *
     * @return array<string, array{0: string, 1: array<int, WorkbookRow>}>
     */
    protected function applications(): array
    {
        return [
            'GrowMate' => [
                'Aplikasi pengadaan dan distribusi pupuk, dari pengajuan sampai penerimaan di kebun, dengan pendamping mobile.',
                $this->growMate(),
            ],
            'RUP (Rencana Umum Pengadaan)' => [
                'Penyusunan dan pemaketan Rencana Umum Pengadaan, terintegrasi dengan SAP CDS dan IPS.',
                $this->rup(),
            ],
            'Superman (Payment)' => [
                'Aplikasi pembayaran: refactor master data, migrasi server, dan rollout ke anak perusahaan.',
                $this->superman(),
            ],
            'Support App & Server' => [
                'Dukungan kontainerisasi dan deployment untuk aplikasi lain di lingkungan PTPN.',
                $this->support(),
            ],
            'PTPN API' => [
                'Layanan API master data PTPN dengan sinkronisasi harian dari SAP CDS.',
                $this->ptpnApi(),
            ],
        ];
    }

    /**
     * @return array<int, WorkbookRow>
     */
    protected function growMate(): array
    {
        return [
            ['Auth & Hak Akses', 100, 'W1 06-25', 'W4 06-25'],
            ['Data Master', 100, 'W1 06-25', 'W4 07-26'],
            ['Pengajuan Transaksi & HPS', 100, 'W2 06-25', 'W4 10-25'],
            ['Integrasi IPS', 100, 'W3 06-25', 'W3 08-26', [
                ['Sinkronisasi Data IPS', 100, 'W3 06-25', 'W4 12-25'],
                ['Create & Update PR ke IPS', 100, 'W3 02-26', 'W4 04-26'],
                ['Vendor & SPPBJ untuk mobile', 100, 'W2 06-26', 'W3 08-26'],
            ]],
            ['Deployment', 100, 'W4 06-25', 'W4 04-26', [
                ['Deploy server SISI', 100, 'W4 06-25', 'W1 07-25'],
                ['Containerisasi', 100, 'W2 11-25', 'W2 11-25'],
                ['Optimasi Docker', 100, 'W4 04-26', 'W4 04-26'],
            ]],
            ['Dashboard, Laporan & Monitoring', 100, 'W2 07-25', 'W2 08-26', [
                ['Dashboard Distribusi', 100, 'W2 08-26', 'W2 08-26'],
            ]],
            ['Integrasi SAP/CDS', 100, 'W2 08-25', 'W4 07-26', [
                ['Stock (CDS)', 100, 'W2 08-25', 'W4 08-25'],
                ['PO / Reservasi / Master & Posting GR-GI-Transfer', 100, 'W1 12-25', 'W2 04-26'],
                ['Update Stock ZPP_MISV', 100, 'W2 06-26', 'W2 06-26'],
                ['Update Sloc ZHLD_SLOC_V', 100, 'W1 07-26', 'W1 07-26'],
            ]],
            ['Fondasi Aplikasi Mobile', 100, 'W1 02-26', 'W3 02-26'],
            ['QR Code', 100, 'W3 02-26', 'W1 03-26'],
            ['Penerimaan', 100, 'W1 03-26', 'W3 03-26'],
            ['Pengeluaran', 100, 'W3 03-26', 'W1 04-26'],
            ['Pemupukan', 100, 'W1 04-26', 'W3 04-26'],
            ['Pengembalian Kemasan', 100, 'W3 04-26', 'W1 05-26'],
            ['Uji Layak Pakai/Bayar', 100, 'W1 05-26', 'W2 05-26'],
            ['Pengembalian Pupuk', 100, 'W2 05-26', 'W3 05-26'],
            ['Transfer Posting', 100, 'W3 05-26', 'W1 06-26'],
            ['AI Agent Chat (MVP)', 100, 'W4 05-26', 'W4 05-26'],
            ['Offline Mode', 87, 'W4 07-26', 'W3 08-26'],
            ['Perjalanan Dinas', 100, 'W3 09-25', 'W2 08-26', [
                ['Pengenalan Dan Sosialisasi Aplikasi', 100, 'W3 09-25', 'W3 09-25'],
                ['Dukungan Pengambilan Video Aplikasi', 100, 'W4 09-25', 'W4 09-25'],
                ['Implementasi Modul Pre Reservasi dan Pemupukan pada Komoditi Sawit', 100, 'W2 02-26', 'W2 02-26'],
                ['Implementasi Modul Pre Reservasi dan Pemupukan pada Komoditi Teh', 100, 'W3 02-26', 'W3 02-26'],
                ['Implementasi Modul Pre Reservasi dan Pemupukan pada Komoditi Tebu', 100, 'W4 02-26', 'W4 02-26'],
                ['Dukungan Pengambilan Video Aplikasi versi Culture', 100, 'W2 03-26', 'W2 03-26'],
                ['Rollout GrowMate di PT SGN', 100, 'W2 07-26', 'W2 07-26'],
                ['Rollout GrowMate di PalmCO', 100, 'W2 08-26', 'W2 08-26'],
            ]],
            ['Perbaikan pasca rollout', 100, 'W3 07-26', 'W3 08-26', [
                ['Perbaikan pasca rollout SGN', 100, 'W3 07-26', 'W3 07-26'],
                ['Perbaikan pasca rollout PalmCo', 100, 'W3 08-26', 'W4 08-26'],
            ]],
            ['Maintenance', 100, 'W4 08-26', 'W4 08-26', [
                ['Menambahkan Display Name company pada laporan HPS dan membuat History Cetak', 100, 'W4 08-26', 'W4 08-26'],
            ]],
            ['Panduan', 100, 'W4 08-26', 'W4 08-26', [
                ['Video Tutorial Mobile', 100, 'W4 08-26', 'W4 08-26'],
                ['Manual Book Mobile', 100, 'W1 08-26', 'W1 08-26'],
                ['Manual Book dan Video Tutorial Web', 100, 'W1 09-25', 'W2 09-25'],
            ]],
        ];
    }

    /**
     * @return array<int, WorkbookRow>
     */
    protected function rup(): array
    {
        return [
            ['Fondasi Aplikasi & Setup Proyek', 100, 'W2 01-26', 'W4 01-26'],
            ['Alur Persetujuan Berjenjang & Fungsi Teknis', 100, 'W2 01-26', 'W1 02-26'],
            ['Pengajuan RUP', 100, 'W3 01-26', 'W1 03-26'],
            ['Pemaketan RUP', 100, 'W3 01-26', 'W2 04-26'],
            ['Dashboard & Statistik RUP', 100, 'W4 01-26', 'W1 02-26'],
            ['Integrasi SAP CDS', 100, 'W4 01-26', 'W2 06-26', [
                ['Material SAP', 100, 'W4 01-26', 'W1 02-26'],
                ['Master Data & Sinkronisasi SAP', 100, 'W1 03-26', 'W2 04-26'],
                ['Stok Material: Sinkronisasi & Dashboard Monitoring', 100, 'W3 04-26', 'W2 06-26'],
            ]],
            ['Import & Export Excel', 100, 'W1 02-26', 'W1 02-26'],
            ['Anggaran: WBS, GL Account & Cost Center', 100, 'W2 02-26', 'W2 02-26'],
            ['Pasca-RUPS & Finalisasi', 100, 'W2 02-26', 'W2 03-26'],
            ['Integrasi IPS', 100, 'W1 03-26', 'W4 04-26', [
                ['Sinkronisasi Material dari IPS', 100, 'W1 03-26', 'W1 03-26'],
                ['Purchase Requisition ke IPS', 100, 'W3 03-26', 'W3 03-26'],
                ['Sync & Validasi IPS pada Paket/Pengajuan', 100, 'W4 03-26', 'W4 04-26'],
            ]],
            ['Notifikasi', 100, 'W4 03-26', 'W4 03-26'],
            ['Manajemen Pengguna & Hak Akses', 100, 'W4 03-26', 'W1 04-26'],
            ['Deployment', 100, 'W4 03-26', 'W4 04-26'],
            ['Migrasi Data SGN', 100, 'W1 05-26', 'W3 05-26'],
        ];
    }

    /**
     * @return array<int, WorkbookRow>
     */
    protected function superman(): array
    {
        return [
            ['Refactor Master Data & Hak Akses', 100, 'W4 03-26', 'W4 04-26'],
            ['Setup Server & Kontainerisasi Docker', 100, 'W4 04-26', 'W4 04-26'],
            ['Deployment Dokploy & Auto-Deploy', 100, 'W4 05-26', 'W4 05-26'],
            ['Migrasi Database dan Storage Desentralisasi', 100, 'W4 05-26', 'W4 06-26'],
            ['Rollout Anak Perusahaan (PTPN1/PTPN4/PTSGN)', 100, 'W4 05-26', 'W4 06-26'],
            ['Optimasi Memori & Performa', 100, 'W4 06-26', 'W4 06-26'],
            ['Maintenance Pasca Migrasi', 100, 'W4 05-26', 'W4 06-26'],
            ['Perbaikan Form & Cetak SPP', 100, 'W1 07-26', 'W1 07-26'],
            ['Perbaikan Timezone & Cutoff', 100, 'W3 07-26', 'W3 07-26'],
            ['Verifikasi Email, 2FA & Keamanan Akun', 100, 'W4 07-26', 'W4 07-26'],
        ];
    }

    /**
     * @return array<int, WorkbookRow>
     */
    protected function support(): array
    {
        return [
            ['CRM - Docker & Deploy Coolify', 100, 'W2 11-25', 'W4 02-26', [
                ['CRM - Setup Docker & Environment Dev', 100, 'W2 11-25', 'W2 12-25'],
                ['CRM - Coolify & Environment Produksi (PHP-FPM/SQL Server/Worker)', 100, 'W2 01-26', 'W4 02-26'],
                ['CRM Analytic - Setup Docker & Expose Port', 100, 'W2 01-26', 'W2 01-26'],
            ]],
            ['Aplikasi Hukum - Docker & Deploy Coolify', 100, 'W2 01-26', 'W2 03-26', [
                ['Aplikasi Hukum - Multi-stage Build & Deploy Coolify', 100, 'W2 01-26', 'W4 01-26'],
                ['Aplikasi Hukum - Compose Supervisord', 100, 'W2 03-26', 'W2 03-26'],
            ]],
            ['Arhan - Docker & Deploy Coolify', 100, 'W4 04-26', 'W3 06-26', [
                ['Arhan - Setup Docker', 100, 'W4 04-26', 'W4 04-26'],
                ['Arhan - Tuning Deploy Coolify', 100, 'W3 06-26', 'W3 06-26'],
            ]],
            ['Supermental - Docker & Deployment Dokploy', 100, 'W4 04-26', 'W4 06-26'],
            ['IHCMIS - Docker & Deploy Coolify', 100, 'W2 05-26', 'W4 07-26', [
                ['IHCMIS Core - Setup & Finalisasi Docker', 100, 'W2 05-26', 'W2 05-26'],
                ['IHCMIS DPCS - Setup Docker', 100, 'W2 05-26', 'W2 05-26'],
                ['IHCMIS ESS - Setup Docker & Fix Relay', 100, 'W2 05-26', 'W2 05-26'],
                ['IHCMIS IAM - Setup Docker', 100, 'W2 05-26', 'W2 05-26'],
                ['IHCMIS OneAccess - Setup Docker', 100, 'W2 05-26', 'W2 05-26'],
                ['IHCMIS DataControl - Setup Docker', 100, 'W2 05-26', 'W2 05-26'],
                ['IHCMIS Initiative Tracker - Setup Docker', 100, 'W3 05-26', 'W3 05-26'],
                ['IHCMIS ESS - Perbaikan Build Coolify', 100, 'W4 06-26', 'W4 06-26'],
                ['IHCMIS Core - Filebrowser & Permission Volume', 100, 'W2 07-26', 'W4 07-26'],
            ]],
            ['CCTV - Docker & Deploy Coolify', 100, 'W3 05-26', 'W3 07-26', [
                ['CCTV - Deploy Docker Coolify', 100, 'W3 05-26', 'W3 05-26'],
                ['CCTV - Fix Permission Docker', 100, 'W3 07-26', 'W3 07-26'],
            ]],
            ['PTI - Fix Timeout Gateway', 100, 'W1 06-26', 'W1 06-26'],
            ['ERIN - Deploy Single-Container Coolify', 100, 'W4 06-26', 'W4 06-26'],
            ['RKAP - Tambah anggaran 2027', 100, 'W4 05-26', 'W4 05-26'],
        ];
    }

    /**
     * @return array<int, WorkbookRow>
     */
    protected function ptpnApi(): array
    {
        return [
            ['Membangun fondasi dan struktur', 100, 'W1 07-26', 'W2 07-26'],
            ['Layar Master Data & API v1', 100, 'W1 07-26', 'W1 07-26'],
            ['API Client & Manajemen Akses User', 100, 'W1 07-26', 'W1 07-26'],
            ['Integrasi SAP CDS & Sinkronisasi Harian', 100, 'W1 07-26', 'W4 07-26'],
            ['Deployment Docker & Coolify', 100, 'W1 07-26', 'W1 07-26'],
            ['Hierarki Unit & Filter Bertingkat', 100, 'W4 07-26', 'W4 07-26'],
            ['Ekspor XLSX Master Data', 100, 'W4 07-26', 'W4 07-26'],
        ];
    }
}
