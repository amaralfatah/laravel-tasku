<?php

namespace Database\Seeders;

use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceScale;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\OrgUnitTree;
use App\Services\TaskHierarchy;
use App\Support\MonthWeek;
use App\Support\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;

/**
 * The whole worked example: one person, the workspace they run alone, and
 * their real backlog.
 *
 * Perkebunan Nusantara   ← Amar (Owner), the only member, one org unit
 *
 * The backlog is Amar Al Fatah's, taken from the running workspace — which
 * started as the per-programmer workbook the monitoring pages replace and has
 * moved on since. It exists so the person page and the Excel export have a
 * sheet's worth of genuine work to render: five applications, 139 tasks, two
 * levels deep, a few of them still running.
 *
 * Every account uses the password `password`, and every address is on a
 * `.test` domain so a stray email can never reach a real inbox.
 *
 * Dates are weeks of a month (`W3 08-26`), not days, so they are widened back
 * to the calendar here: a start lands on the first day of its week, an end on
 * the last, and week four always closes on the last day of the month — which
 * is exactly how {@see MonthWeek} reads them back. A row with no dates at all
 * is work nobody has scheduled yet.
 *
 * @phpstan-type WorkbookRow array{title: string, progress: int, start?: string, due?: string, status?: string, unassigned?: bool, children?: array<int, mixed>}
 */
class AmarWorkloadSeeder extends Seeder
{
    /** The person this workspace and its backlog belong to. */
    protected const EMAIL = 'amar@perkebunan.test';

    /** Name of both the workspace and the single org unit it runs. */
    protected const WORKSPACE = 'Perkebunan Nusantara';

    public function run(): void
    {
        $tenancy = app(Tenancy::class);
        $member = $this->seedWorkspace();
        $user = $member->user;

        if (Task::query()->withoutGlobalScopes()->where('assignee_id', $user->id)->exists()) {
            $this->command->warn('Lewati: task '.$user->name.' sudah ada.');

            return;
        }

        $tenancy->set($member->workspace, $member);

        $count = $this->seedProjects($member, $user);

        $tenancy->forget();

        $this->command->info('Workspace '.self::WORKSPACE.' siap. Kata sandi akun: password');
        $this->command->info("{$count} task milik {$user->name} dibuat di 5 aplikasi.");
    }

    /**
     * The workspace, its one org unit and the person who owns both.
     *
     * This is the smallest scale the product serves: one person working alone,
     * with no ladder above them and no branch below. A single node of the org
     * tree, which the workspace itself runs, is what {@see WorkspaceScale}
     * reads as `Solo` — the roster, the organisation page and the reporting
     * pages stay out of the way until there is somebody else to put on them.
     *
     * Idempotent, so re-running the seeder adopts what is already there rather
     * than opening a second workspace beside it.
     */
    protected function seedWorkspace(): WorkspaceMember
    {
        $tenancy = app(Tenancy::class);

        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'Amar', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );

        $existing = WorkspaceMember::query()->withoutGlobalScopes()->where('user_id', $user->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $workspace = Workspace::create(['name' => self::WORKSPACE]);

        // Org units are platform master data and carry no workspace_id, so the
        // node is written outside any tenant context; the workspace then adopts
        // it as the slice of the tree it runs. Typed `company` rather than
        // `division`: at this scale it stands for the whole operation, the same
        // shape the self-serve path creates.
        $root = $tenancy->withoutScope(fn (): OrgUnit => app(OrgUnitTree::class)->create([
            'name' => self::WORKSPACE,
            'type' => 'company',
        ]));

        $workspace->update(['root_org_unit_id' => $root->id]);

        // Amar runs the workspace himself, so he holds the Owner tier. No job
        // title is set: working alone, the tier's own name says enough, and the
        // column is there for a customer who wants one.
        return $tenancy->forWorkspace($workspace, fn (): WorkspaceMember => WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceRole::Owner,
            'org_unit_id' => $root->id,
            'joined_at' => now(),
        ]));
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
     * Rows are written in the order they are listed, because that is the order
     * the board holds them in — and `TaskHierarchy` derives both `position` and
     * the WBS number from the order they arrive in.
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
                // One task was opened without an owner and is kept that way.
                'assignee_id' => ($row['unassigned'] ?? false) ? null : $user->id,
                // Status usually follows the percentage; a row states its own
                // only where the board disagrees, e.g. work started and then
                // reset to nothing.
                'status' => isset($row['status'])
                    ? TaskStatus::from($row['status'])
                    : $this->status($progress),
                'progress' => $progress,
                'start_date' => $this->day($row['start'] ?? null, false),
                'due_date' => $this->day($row['due'] ?? null, true),
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
     * A row without a label is work nobody has scheduled yet, which the app
     * reports separately from work that is late (DIV-5), so the null is
     * carried through rather than filled in with a guess.
     *
     * @param  bool  $end  the closing day of that week rather than its first
     */
    protected function day(?string $label, bool $end): ?CarbonInterface
    {
        if ($label === null) {
            return null;
        }

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
     * Transcribed out of the running workspace rather than the spreadsheet:
     * the board has moved on since the workbook was written, so this is the
     * backlog as it actually stands — new work included, and the rows in the
     * order the board holds them rather than re-sorted by start date.
     *
     * @return array<string, array{0: string, 1: array<int, WorkbookRow>}>
     */
    protected function applications(): array
    {
        return [
            'GrowMate' => [
                'Aplikasi pengadaan dan distribusi pupuk, dari pengajuan sampai penerimaan di kebun, dengan pendamping mobile.',
                [
                    ['title' => 'Auth & Hak Akses', 'progress' => 100, 'start' => 'W1 06-25', 'due' => 'W4 06-25'],
                    ['title' => 'Pengajuan Transaksi & HPS', 'progress' => 100, 'start' => 'W2 06-25', 'due' => 'W4 10-25'],
                    ['title' => 'Integrasi IPS', 'progress' => 100, 'start' => 'W3 06-25', 'due' => 'W3 08-26', 'children' => [
                        ['title' => 'Sinkronisasi Data IPS', 'progress' => 100, 'start' => 'W3 06-25', 'due' => 'W4 12-25'],
                        ['title' => 'Create & Update PR ke IPS', 'progress' => 100, 'start' => 'W3 02-26', 'due' => 'W4 04-26'],
                        ['title' => 'Vendor & SPPBJ untuk mobile', 'progress' => 100, 'start' => 'W2 06-26', 'due' => 'W3 08-26'],
                    ]],
                    ['title' => 'Deployment', 'progress' => 100, 'start' => 'W4 06-25', 'due' => 'W4 04-26', 'children' => [
                        ['title' => 'Deploy server SISI', 'progress' => 100, 'start' => 'W4 06-25', 'due' => 'W1 07-25'],
                        ['title' => 'Containerisasi', 'progress' => 100, 'start' => 'W2 11-25', 'due' => 'W2 11-25'],
                        ['title' => 'Optimasi Docker', 'progress' => 100, 'start' => 'W4 04-26', 'due' => 'W4 04-26'],
                    ]],
                    ['title' => 'Dashboard, Laporan & Monitoring', 'progress' => 100, 'start' => 'W2 07-25', 'due' => 'W2 08-26', 'children' => [
                        ['title' => 'Dashboard Distribusi', 'progress' => 100, 'start' => 'W2 08-26', 'due' => 'W2 08-26'],
                    ]],
                    ['title' => 'Integrasi SAP/CDS', 'progress' => 100, 'start' => 'W2 08-25', 'due' => 'W4 07-26', 'children' => [
                        ['title' => 'Stock (CDS)', 'progress' => 100, 'start' => 'W2 08-25', 'due' => 'W4 08-25'],
                        ['title' => 'PO / Reservasi / Master & Posting GR-GI-Transfer', 'progress' => 100, 'start' => 'W1 12-25', 'due' => 'W2 04-26'],
                        ['title' => 'Update Stock ZPP_MISV', 'progress' => 100, 'start' => 'W2 06-26', 'due' => 'W2 06-26'],
                        ['title' => 'Update Sloc ZHLD_SLOC_V', 'progress' => 100, 'start' => 'W1 07-26', 'due' => 'W1 07-26'],
                    ]],
                    ['title' => 'Perjalanan Dinas', 'progress' => 100, 'start' => 'W3 09-25', 'due' => 'W2 08-26', 'children' => [
                        ['title' => 'Pengenalan Dan Sosialisasi Aplikasi', 'progress' => 100, 'start' => 'W3 09-25', 'due' => 'W3 09-25'],
                        ['title' => 'Dukungan Pengambilan Video Aplikasi', 'progress' => 100, 'start' => 'W4 09-25', 'due' => 'W4 09-25'],
                        ['title' => 'Implementasi Modul Pre Reservasi dan Pemupukan pada Komoditi Sawit', 'progress' => 100, 'start' => 'W2 02-26', 'due' => 'W2 02-26'],
                        ['title' => 'Implementasi Modul Pre Reservasi dan Pemupukan pada Komoditi Teh', 'progress' => 100, 'start' => 'W3 02-26', 'due' => 'W3 02-26'],
                        ['title' => 'Implementasi Modul Pre Reservasi dan Pemupukan pada Komoditi Tebu', 'progress' => 100, 'start' => 'W4 02-26', 'due' => 'W4 02-26'],
                        ['title' => 'Dukungan Pengambilan Video Aplikasi versi Culture', 'progress' => 100, 'start' => 'W2 03-26', 'due' => 'W2 03-26'],
                        ['title' => 'Rollout GrowMate di PT SGN', 'progress' => 100, 'start' => 'W2 07-26', 'due' => 'W2 07-26'],
                        ['title' => 'Rollout GrowMate di PalmCO', 'progress' => 100, 'start' => 'W2 08-26', 'due' => 'W2 08-26'],
                    ]],
                    ['title' => 'Fondasi Aplikasi Mobile', 'progress' => 100, 'start' => 'W1 02-26', 'due' => 'W3 02-26'],
                    ['title' => 'QR Code', 'progress' => 100, 'start' => 'W3 02-26', 'due' => 'W1 03-26'],
                    ['title' => 'Penerimaan', 'progress' => 100, 'start' => 'W1 03-26', 'due' => 'W3 03-26'],
                    ['title' => 'Pengeluaran', 'progress' => 100, 'start' => 'W3 03-26', 'due' => 'W1 04-26'],
                    ['title' => 'Pemupukan', 'progress' => 100, 'start' => 'W1 04-26', 'due' => 'W3 04-26'],
                    ['title' => 'Pengembalian Kemasan', 'progress' => 100, 'start' => 'W3 04-26', 'due' => 'W1 05-26'],
                    ['title' => 'Uji Layak Pakai/Bayar', 'progress' => 100, 'start' => 'W1 05-26', 'due' => 'W2 05-26'],
                    ['title' => 'Pengembalian Pupuk', 'progress' => 100, 'start' => 'W2 05-26', 'due' => 'W3 05-26'],
                    ['title' => 'Transfer Posting', 'progress' => 100, 'start' => 'W3 05-26', 'due' => 'W1 06-26'],
                    ['title' => 'AI Agent Chat (MVP)', 'progress' => 100, 'start' => 'W4 05-26', 'due' => 'W4 05-26'],
                    ['title' => 'Perbaikan pasca rollout SGN', 'progress' => 100, 'start' => 'W3 07-26', 'due' => 'W3 07-26', 'children' => [
                        ['title' => 'Perbaikan pasca rollout PalmCo', 'progress' => 100, 'start' => 'W3 08-26', 'due' => 'W4 08-26'],
                        ['title' => 'Perbaikan pasca rollout SGN', 'progress' => 100, 'start' => 'W3 07-26', 'due' => 'W3 07-26'],
                    ]],
                    ['title' => 'Maintenance', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26', 'children' => [
                        ['title' => 'Menambahkan Display Name company pada laporan HPS dan membuat History Cetak', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                    ]],
                    ['title' => 'Panduan', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26', 'children' => [
                        ['title' => 'Manual Book dan Video Tutorial Web', 'progress' => 100, 'start' => 'W1 09-25', 'due' => 'W2 09-25'],
                        ['title' => 'Manual Book Mobile', 'progress' => 100, 'start' => 'W1 08-26', 'due' => 'W1 08-26'],
                        ['title' => 'Video Tutorial Mobile', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                    ]],
                    ['title' => 'Offline Mode', 'progress' => 100, 'start' => 'W4 07-26', 'due' => 'W3 08-26'],
                    ['title' => 'Data Master', 'progress' => 100, 'start' => 'W1 06-25', 'due' => 'W4 07-26'],
                    ['title' => 'Video Tutorial', 'progress' => 83, 'start' => 'W4 08-26', 'due' => 'W2 09-26', 'children' => [
                        ['title' => 'Login', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W1 09-26'],
                        ['title' => 'Penerimaan', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W1 09-26'],
                        ['title' => 'Pengeluaran', 'progress' => 100],
                        ['title' => 'Pemupukan', 'progress' => 100],
                        ['title' => 'Pengembalian Kemasan', 'progress' => 100],
                        ['title' => 'Transfer Posting', 'progress' => 100],
                        ['title' => 'Pengembalian Pupuk', 'progress' => 100],
                        ['title' => 'Selisih Penerimaan', 'progress' => 100],
                        ['title' => 'Persetujuan', 'progress' => 100],
                        ['title' => 'Pengeluaran Sebagian', 'progress' => 100],
                        ['title' => 'Pemupukan Sebagian', 'progress' => 0],
                        ['title' => 'Penerimaan Internal', 'progress' => 0],
                    ]],
                    ['title' => 'Notifikasi Firebase', 'progress' => 0],
                    ['title' => 'Perbaikan pasca rollout PALM', 'progress' => 100, 'start' => 'W3 08-26', 'due' => 'W1 09-26', 'children' => [
                        ['title' => 'Multi-block untuk pengeluaran dan pemupukan.', 'progress' => 100],
                        ['title' => 'Tambah Notifikasi FIrebase', 'progress' => 100, 'start' => 'W4 08-26', 'due' => 'W4 08-26'],
                        ['title' => 'Tambah Notifikasi Firebase', 'progress' => 0, 'unassigned' => true],
                        ['title' => 'Validasi pengembalian: kunci QR terpakai, retur sebagian, dan kemasan khusus QR selesai dipupuk.', 'progress' => 100],
                        ['title' => 'Pencarian QR global dari Beranda dan di detail dokumen.', 'progress' => 100],
                        ['title' => 'Input manual QR via bottom sheet dan OCR.', 'progress' => 100],
                        ['title' => 'Perbesar teks QTY/nomor QR serta perbaiki tampilan layar kecil dan lipat.', 'progress' => 100],
                        ['title' => 'Ganti istilah Kepala Gudang menjadi KTU.', 'progress' => 100],
                        ['title' => 'Filter material otomatis mengikuti kebun terpilih berdasarkan SPPBJ.', 'progress' => 100],
                        ['title' => 'Offline mode umum dan penerimaan 1 QR untuk SGN.', 'progress' => 100],
                        ['title' => 'Integrasi notifikasi Firebase, log login, dan fitur update aplikasi.', 'progress' => 100],
                    ]],
                    ['title' => 'Tambah Notifikasi FIrebase', 'progress' => 0, 'start' => 'W4 08-26', 'due' => 'W4 08-26', 'status' => 'in_progress'],
                    ['title' => 'Video Tutorial', 'progress' => 0],
                ],
            ],
            'RUP (Rencana Umum Pengadaan)' => [
                'Penyusunan dan pemaketan Rencana Umum Pengadaan, terintegrasi dengan SAP CDS dan IPS.',
                [
                    ['title' => 'Fondasi Aplikasi & Setup Proyek', 'progress' => 100, 'start' => 'W2 01-26', 'due' => 'W4 01-26'],
                    ['title' => 'Alur Persetujuan Berjenjang & Fungsi Teknis', 'progress' => 100, 'start' => 'W2 01-26', 'due' => 'W1 02-26'],
                    ['title' => 'Pengajuan RUP', 'progress' => 100, 'start' => 'W3 01-26', 'due' => 'W1 03-26'],
                    ['title' => 'Pemaketan RUP', 'progress' => 100, 'start' => 'W3 01-26', 'due' => 'W2 04-26'],
                    ['title' => 'Dashboard & Statistik RUP', 'progress' => 100, 'start' => 'W4 01-26', 'due' => 'W1 02-26'],
                    ['title' => 'Integrasi SAP CDS', 'progress' => 100, 'start' => 'W4 01-26', 'due' => 'W2 06-26', 'children' => [
                        ['title' => 'Material SAP', 'progress' => 100, 'start' => 'W4 01-26', 'due' => 'W1 02-26'],
                        ['title' => 'Master Data & Sinkronisasi SAP', 'progress' => 100, 'start' => 'W1 03-26', 'due' => 'W2 04-26'],
                        ['title' => 'Stok Material: Sinkronisasi & Dashboard Monitoring', 'progress' => 100, 'start' => 'W3 04-26', 'due' => 'W2 06-26'],
                    ]],
                    ['title' => 'Import & Export Excel', 'progress' => 100, 'start' => 'W1 02-26', 'due' => 'W1 02-26'],
                    ['title' => 'Anggaran: WBS, GL Account & Cost Center', 'progress' => 100, 'start' => 'W2 02-26', 'due' => 'W2 02-26'],
                    ['title' => 'Pasca-RUPS & Finalisasi', 'progress' => 100, 'start' => 'W2 02-26', 'due' => 'W2 03-26'],
                    ['title' => 'Integrasi IPS', 'progress' => 100, 'start' => 'W1 03-26', 'due' => 'W4 04-26', 'children' => [
                        ['title' => 'Sinkronisasi Material dari IPS', 'progress' => 100, 'start' => 'W1 03-26', 'due' => 'W1 03-26'],
                        ['title' => 'Purchase Requisition ke IPS', 'progress' => 100, 'start' => 'W3 03-26', 'due' => 'W3 03-26'],
                        ['title' => 'Sync & Validasi IPS pada Paket/Pengajuan', 'progress' => 100, 'start' => 'W4 03-26', 'due' => 'W4 04-26'],
                    ]],
                    ['title' => 'Notifikasi', 'progress' => 100, 'start' => 'W4 03-26', 'due' => 'W4 03-26'],
                    ['title' => 'Manajemen Pengguna & Hak Akses', 'progress' => 100, 'start' => 'W4 03-26', 'due' => 'W1 04-26'],
                    ['title' => 'Deployment', 'progress' => 100, 'start' => 'W4 03-26', 'due' => 'W4 04-26'],
                    ['title' => 'Migrasi Data SGN', 'progress' => 100, 'start' => 'W1 05-26', 'due' => 'W3 05-26'],
                ],
            ],
            'Superman (Payment)' => [
                'Aplikasi pembayaran: refactor master data, migrasi server, dan rollout ke anak perusahaan.',
                [
                    ['title' => 'Refactor Master Data & Hak Akses', 'progress' => 100, 'start' => 'W4 03-26', 'due' => 'W4 04-26'],
                    ['title' => 'Setup Server & Kontainerisasi Docker', 'progress' => 100, 'start' => 'W4 04-26', 'due' => 'W4 04-26'],
                    ['title' => 'Deployment Dokploy & Auto-Deploy', 'progress' => 100, 'start' => 'W4 05-26', 'due' => 'W4 05-26'],
                    ['title' => 'Migrasi Database dan Storage Desentralisasi', 'progress' => 100, 'start' => 'W4 05-26', 'due' => 'W4 06-26'],
                    ['title' => 'Rollout Anak Perusahaan (PTPN1/PTPN4/PTSGN)', 'progress' => 100, 'start' => 'W4 05-26', 'due' => 'W4 06-26'],
                    ['title' => 'Maintenance Pasca Migrasi', 'progress' => 100, 'start' => 'W4 05-26', 'due' => 'W4 06-26'],
                    ['title' => 'Optimasi Memori & Performa', 'progress' => 100, 'start' => 'W4 06-26', 'due' => 'W4 06-26'],
                    ['title' => 'Perbaikan Form & Cetak SPP', 'progress' => 100, 'start' => 'W1 07-26', 'due' => 'W1 07-26'],
                    ['title' => 'Perbaikan Timezone & Cutoff', 'progress' => 100, 'start' => 'W3 07-26', 'due' => 'W3 07-26'],
                    ['title' => 'Verifikasi Email, 2FA & Keamanan Akun', 'progress' => 100, 'start' => 'W4 07-26', 'due' => 'W4 07-26'],
                ],
            ],
            'Support App & Server' => [
                'Dukungan kontainerisasi dan deployment untuk aplikasi lain di lingkungan PTPN.',
                [
                    ['title' => 'CRM - Docker & Deploy Coolify', 'progress' => 100, 'start' => 'W2 11-25', 'due' => 'W4 02-26', 'children' => [
                        ['title' => 'CRM - Setup Docker & Environment Dev', 'progress' => 100, 'start' => 'W2 11-25', 'due' => 'W2 12-25'],
                        ['title' => 'CRM - Coolify & Environment Produksi (PHP-FPM/SQL Server/Worker)', 'progress' => 100, 'start' => 'W2 01-26', 'due' => 'W4 02-26'],
                        ['title' => 'CRM Analytic - Setup Docker & Expose Port', 'progress' => 100, 'start' => 'W2 01-26', 'due' => 'W2 01-26'],
                    ]],
                    ['title' => 'Aplikasi Hukum - Docker & Deploy Coolify', 'progress' => 100, 'start' => 'W2 01-26', 'due' => 'W2 03-26', 'children' => [
                        ['title' => 'Aplikasi Hukum - Multi-stage Build & Deploy Coolify', 'progress' => 100, 'start' => 'W2 01-26', 'due' => 'W4 01-26'],
                        ['title' => 'Aplikasi Hukum - Compose Supervisord', 'progress' => 100, 'start' => 'W2 03-26', 'due' => 'W2 03-26'],
                    ]],
                    ['title' => 'Arhan - Docker & Deploy Coolify', 'progress' => 100, 'start' => 'W4 04-26', 'due' => 'W3 06-26', 'children' => [
                        ['title' => 'Arhan - Setup Docker', 'progress' => 100, 'start' => 'W4 04-26', 'due' => 'W4 04-26'],
                        ['title' => 'Arhan - Tuning Deploy Coolify', 'progress' => 100, 'start' => 'W3 06-26', 'due' => 'W3 06-26'],
                    ]],
                    ['title' => 'Supermental - Docker & Deployment Dokploy', 'progress' => 100, 'start' => 'W4 04-26', 'due' => 'W4 06-26'],
                    ['title' => 'IHCMIS - Docker & Deploy Coolify', 'progress' => 100, 'start' => 'W2 05-26', 'due' => 'W4 07-26', 'children' => [
                        ['title' => 'IHCMIS Core - Setup & Finalisasi Docker', 'progress' => 100, 'start' => 'W2 05-26', 'due' => 'W2 05-26'],
                        ['title' => 'IHCMIS DPCS - Setup Docker', 'progress' => 100, 'start' => 'W2 05-26', 'due' => 'W2 05-26'],
                        ['title' => 'IHCMIS ESS - Setup Docker & Fix Relay', 'progress' => 100, 'start' => 'W2 05-26', 'due' => 'W2 05-26'],
                        ['title' => 'IHCMIS IAM - Setup Docker', 'progress' => 100, 'start' => 'W2 05-26', 'due' => 'W2 05-26'],
                        ['title' => 'IHCMIS OneAccess - Setup Docker', 'progress' => 100, 'start' => 'W2 05-26', 'due' => 'W2 05-26'],
                        ['title' => 'IHCMIS DataControl - Setup Docker', 'progress' => 100, 'start' => 'W2 05-26', 'due' => 'W2 05-26'],
                        ['title' => 'IHCMIS Initiative Tracker - Setup Docker', 'progress' => 100, 'start' => 'W3 05-26', 'due' => 'W3 05-26'],
                        ['title' => 'IHCMIS ESS - Perbaikan Build Coolify', 'progress' => 100, 'start' => 'W4 06-26', 'due' => 'W4 06-26'],
                        ['title' => 'IHCMIS Core - Filebrowser & Permission Volume', 'progress' => 100, 'start' => 'W2 07-26', 'due' => 'W4 07-26'],
                    ]],
                    ['title' => 'CCTV - Docker & Deploy Coolify', 'progress' => 100, 'start' => 'W3 05-26', 'due' => 'W3 07-26', 'children' => [
                        ['title' => 'CCTV - Deploy Docker Coolify', 'progress' => 100, 'start' => 'W3 05-26', 'due' => 'W3 05-26'],
                        ['title' => 'CCTV - Fix Permission Docker', 'progress' => 100, 'start' => 'W3 07-26', 'due' => 'W3 07-26'],
                    ]],
                    ['title' => 'RKAP - Tambah anggaran 2027', 'progress' => 100, 'start' => 'W4 05-26', 'due' => 'W4 05-26'],
                    ['title' => 'PTI - Fix Timeout Gateway', 'progress' => 100, 'start' => 'W1 06-26', 'due' => 'W1 06-26'],
                    ['title' => 'ERIN - Deploy Single-Container Coolify', 'progress' => 100, 'start' => 'W4 06-26', 'due' => 'W4 06-26'],
                ],
            ],
            'PTPN API' => [
                'Layanan API master data PTPN dengan sinkronisasi harian dari SAP CDS.',
                [
                    ['title' => 'Membangun fondasi dan struktur', 'progress' => 100, 'start' => 'W1 07-26', 'due' => 'W2 07-26'],
                    ['title' => 'Layar Master Data & API v1', 'progress' => 100, 'start' => 'W1 07-26', 'due' => 'W1 07-26'],
                    ['title' => 'API Client & Manajemen Akses User', 'progress' => 100, 'start' => 'W1 07-26', 'due' => 'W1 07-26'],
                    ['title' => 'Integrasi SAP CDS & Sinkronisasi Harian', 'progress' => 100, 'start' => 'W1 07-26', 'due' => 'W4 07-26'],
                    ['title' => 'Deployment Docker & Coolify', 'progress' => 100, 'start' => 'W1 07-26', 'due' => 'W1 07-26'],
                    ['title' => 'Hierarki Unit & Filter Bertingkat', 'progress' => 100, 'start' => 'W4 07-26', 'due' => 'W4 07-26'],
                    ['title' => 'Ekspor XLSX Master Data', 'progress' => 100, 'start' => 'W4 07-26', 'due' => 'W4 07-26'],
                ],
            ],
        ];
    }
}
