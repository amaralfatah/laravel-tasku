<?php

namespace App\Console\Commands;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\TaskHierarchy;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-off importer for the old per-programmer spreadsheets (R-9, Fase 5).
 *
 * Expects a CSV exported from one sheet, with a header row containing at
 * least `judul`. Optional columns: `wbs`, `assignee` (email or name),
 * `status`, `prioritas`, `progress`, `mulai`, `selesai`.
 *
 * The `wbs` column drives nesting: `1.2.1` becomes a child of `1.2`. When it
 * is absent every row is imported as a root task.
 */
class ImportSpreadsheet extends Command
{
    protected $signature = 'tasku:import-spreadsheet
                            {file : Path to the CSV file}
                            {--project= : Target project id}
                            {--dry-run : Parse and report without writing}';

    protected $description = 'Import tasks from an exported spreadsheet into a project';

    public function handle(Tenancy $tenancy, TaskHierarchy $hierarchy): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("File tidak bisa dibaca: {$path}");

            return self::FAILURE;
        }

        $project = Project::withoutGlobalScopes()->find($this->option('project'));

        if ($project === null) {
            $this->error('Project tidak ditemukan. Gunakan --project=<id>.');

            return self::FAILURE;
        }

        $tenancy->set($project->workspace);

        $rows = $this->readRows($path);

        if ($rows === []) {
            $this->error('Tidak ada baris yang bisa dibaca. Pastikan ada kolom header `judul`.');

            return self::FAILURE;
        }

        $this->line(count($rows).' baris terbaca dari '.basename($path).'.');

        if ($this->option('dry-run')) {
            $this->table(
                ['WBS', 'Judul', 'Assignee', 'Status', 'Mulai', 'Selesai'],
                array_map(fn (array $row): array => [
                    $row['wbs'] ?: '-',
                    $row['judul'],
                    $row['assignee'] ?: '-',
                    $row['status'],
                    $row['mulai'] ?: '-',
                    $row['selesai'] ?: '-',
                ], array_slice($rows, 0, 20)),
            );

            $this->info('Dry run: tidak ada data yang ditulis.');

            return self::SUCCESS;
        }

        $assignees = $this->assigneeMap($project);

        try {
            $created = DB::transaction(function () use ($rows, $project, $hierarchy, $assignees): int {
                // WBS string -> created task, so children can find their parent.
                $byWbs = [];
                $count = 0;

                foreach ($rows as $row) {
                    $parent = null;

                    if ($row['wbs'] !== '' && str_contains($row['wbs'], '.')) {
                        $parentWbs = substr($row['wbs'], 0, (int) strrpos($row['wbs'], '.'));
                        $parent = $byWbs[$parentWbs] ?? null;
                    }

                    $task = $hierarchy->create($project, [
                        'title' => $row['judul'],
                        'assignee_id' => $assignees[mb_strtolower($row['assignee'])] ?? null,
                        'status' => $row['status'],
                        'progress' => $row['progress'],
                        'priority' => $row['prioritas'],
                        'start_date' => $row['mulai'] ?: null,
                        'due_date' => $row['selesai'] ?: null,
                    ], $parent);

                    if ($row['wbs'] !== '') {
                        $byWbs[$row['wbs']] = $task;
                    }

                    $count++;
                }

                return $count;
            });
        } catch (Throwable $e) {
            $this->error('Import dibatalkan, tidak ada data yang tersimpan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$created} task diimpor ke project {$project->name}.");

        return self::SUCCESS;
    }

    /**
     * Read and normalise the CSV, keeping only rows that have a title.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function readRows(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $header = null;
        $rows = [];

        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            if ($header === null) {
                $header = array_map(
                    fn ($column): string => mb_strtolower(trim((string) $column)),
                    $line,
                );

                continue;
            }

            $row = array_combine(
                $header,
                array_pad(array_slice($line, 0, count($header)), count($header), ''),
            );

            $title = trim((string) ($row['judul'] ?? ''));

            if ($title === '') {
                continue;
            }

            $rows[] = [
                'wbs' => trim((string) ($row['wbs'] ?? '')),
                'judul' => $title,
                'assignee' => trim((string) ($row['assignee'] ?? '')),
                'status' => $this->status($row['status'] ?? null),
                'prioritas' => $this->priority($row['prioritas'] ?? null),
                'progress' => $this->progress($row['progress'] ?? null),
                'mulai' => $this->date($row['mulai'] ?? null),
                'selesai' => $this->date($row['selesai'] ?? null),
            ];
        }

        fclose($handle);

        // Shallow rows first, so a parent always exists before its children.
        usort($rows, fn (array $a, array $b): int => substr_count($a['wbs'], '.') <=> substr_count($b['wbs'], '.'));

        return $rows;
    }

    /**
     * Project members, indexed by lowercase email and name.
     *
     * @return array<string, int>
     */
    protected function assigneeMap(Project $project): array
    {
        $map = [];

        $users = $project->members()->get(['users.id', 'users.name', 'users.email']);

        if ($users->isEmpty()) {
            $users = User::query()
                ->whereIn('id', WorkspaceMember::query()->pluck('user_id'))
                ->get(['id', 'name', 'email']);
        }

        foreach ($users as $user) {
            $map[mb_strtolower($user->email)] = $user->id;
            $map[mb_strtolower($user->name)] = $user->id;
        }

        return $map;
    }

    protected function status(mixed $value): string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'done', 'selesai', 'complete', 'completed' => TaskStatus::Done->value,
            'in progress', 'in_progress', 'dikerjakan', 'berjalan', 'progress' => TaskStatus::InProgress->value,
            default => TaskStatus::Todo->value,
        };
    }

    protected function priority(mixed $value): string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'urgent', 'mendesak' => TaskPriority::Urgent->value,
            'high', 'tinggi' => TaskPriority::High->value,
            'low', 'rendah' => TaskPriority::Low->value,
            default => TaskPriority::Medium->value,
        };
    }

    protected function progress(mixed $value): int
    {
        $clean = (int) preg_replace('/[^0-9]/', '', (string) $value);

        return max(0, min(100, $clean));
    }

    /**
     * Accept the date formats the old sheets actually used.
     */
    protected function date(mixed $value): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $raw);

            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }
}
