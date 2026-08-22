<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\OrgStructureImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls the SAP org structure into a workspace.
 *
 * The whole tree is fetched from the HRIS bridge in one call, so this is a
 * scheduled or manual job, never something a web request triggers.
 */
class ImportOrgStructure extends Command
{
    protected $signature = 'tasku:import-org-structure
                            {--workspace= : Target workspace id}
                            {--root= : SAP object id of the holding whose children become the roots, defaults to PT PERKEBUNANAN NUSANTARA I}
                            {--all : Import every root the view carries, including the fragments SAP sends no parent for}
                            {--prune : Delete units an earlier import created that the view no longer carries}
                            {--dry-run : Fetch and report the shape without writing}';

    protected $description = 'Import the SAP org structure (ZA_HRIS_ORGZ) into a workspace';

    public function handle(OrgStructureImporter $importer): int
    {
        $workspace = Workspace::query()->find($this->option('workspace'));

        if ($workspace === null && ! $this->option('dry-run')) {
            $this->error('Workspace tidak ditemukan. Gunakan --workspace=<id>.');

            return self::FAILURE;
        }

        $holding = $this->option('all')
            ? null
            : ((string) $this->option('root') ?: OrgStructureImporter::HOLDING);

        $this->line('Menarik '.OrgStructureImporter::VIEW.' dari bridge SAP…');

        try {
            $rows = $importer->fetch();
            $forest = $importer->forest($rows, $holding);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(count($rows).' baris terbaca, '.count($forest['nodes']).' unit dipakai.');
        $this->line("Root: {$forest['roots']}, kedalaman maksimal: {$forest['max_depth']}.");

        if ($forest['dropped'] > 0) {
            $this->line("{$forest['dropped']} unit dibuang: induk {$holding} sendiri, semua yang di luar subtree-nya, dan {$forest['excluded']} entitas yang dikecualikan.");
        }

        foreach (['skipped' => 'baris dilewati', 'conflicts' => 'induk ganda', 'cycles' => 'siklus dipotong'] as $key => $label) {
            if ($forest[$key] > 0) {
                $this->warn("{$forest[$key]} {$label}.");
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: tidak ada data yang ditulis.');

            return self::SUCCESS;
        }

        try {
            $result = $importer->sync($workspace, $forest['nodes']);
            $pruned = $this->option('prune') ? $importer->prune($workspace, $forest['nodes']) : null;
        } catch (Throwable $e) {
            $this->error('Import dibatalkan, tidak ada data yang tersimpan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$result['created']} unit dibuat, {$result['updated']} diperbarui, {$result['unchanged']} tidak berubah di workspace {$workspace->name}.");

        if ($pruned !== null) {
            $this->info("{$pruned['deleted']} unit lama dihapus.");

            if ($pruned['kept'] > 0) {
                $this->warn("{$pruned['kept']} unit lama dipertahankan karena masih dipakai project, anggota, atau sub unit.");
            }

            return self::SUCCESS;
        }

        if ($result['stale'] > 0) {
            $this->warn("{$result['stale']} unit hasil import sebelumnya sudah tidak ada di SAP. Jalankan ulang dengan --prune untuk menghapusnya.");
        }

        return self::SUCCESS;
    }
}
