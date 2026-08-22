<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\OrgStructureImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls the SAP org structure into the platform-wide org tree.
 *
 * The structure is master data shared by every workspace, so this writes one
 * tree and no workspace is named. The whole thing is fetched from the HRIS
 * bridge in one call, making this a scheduled or manual job, never something a
 * web request triggers.
 */
class ImportOrgStructure extends Command
{
    protected $signature = 'tasku:import-org-structure
                            {--root= : SAP object id of the holding whose children become the roots, defaults to PT PERKEBUNANAN NUSANTARA I}
                            {--all : Import every root the view carries, including the fragments SAP sends no parent for}
                            {--prune : Delete units an earlier import created that the view no longer carries}
                            {--dry-run : Fetch and report the shape without writing}';

    protected $description = 'Import the SAP org structure (ZA_HRIS_ORGZ) as platform master data';

    public function handle(OrgStructureImporter $importer): int
    {
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
            $result = $importer->sync($forest['nodes']);
            $pruned = $this->option('prune') ? $importer->prune($forest['nodes']) : null;
        } catch (Throwable $e) {
            $this->error('Import dibatalkan, tidak ada data yang tersimpan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$result['created']} unit dibuat, {$result['updated']} diperbarui, {$result['unchanged']} tidak berubah.");

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
