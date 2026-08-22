<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The org structure is master data: one SAP tree for the whole platform.
 *
 * `org_units.workspace_id` is dropped and each workspace instead points at the
 * node of that tree it runs — `workspaces.root_org_unit_id`. Tenant separation
 * moves from a foreign key to a `path` prefix, which is what the scope helpers
 * already compare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignId('root_org_unit_id')->nullable()->after('slug')
                ->constrained('org_units')->nullOnDelete();
        });

        $this->adoptExistingRoots();

        Schema::table('org_units', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'parent_id']);
            $table->dropIndex(['workspace_id', 'path']);
            // The SAP id is unique across the whole tree now, not per workspace.
            $table->dropUnique(['workspace_id', 'external_id']);
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');

            $table->index('parent_id');
            $table->index('path');
            $table->unique('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['path']);
            $table->dropUnique(['external_id']);

            $table->foreignId('workspace_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });

        $this->restoreWorkspaceIds();

        Schema::table('org_units', function (Blueprint $table) {
            $table->index(['workspace_id', 'parent_id']);
            $table->index(['workspace_id', 'path']);
            $table->unique(['workspace_id', 'external_id']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign(['root_org_unit_id']);
            $table->dropColumn('root_org_unit_id');
        });
    }

    /**
     * Give every workspace the top unit it already owned. A workspace that
     * grew several roots keeps the first one; the rest stay in the master tree
     * but fall outside its scope until an operator re-parents them.
     */
    protected function adoptExistingRoots(): void
    {
        DB::table('workspaces')->orderBy('id')->each(function (object $workspace): void {
            $root = DB::table('org_units')
                ->where('workspace_id', $workspace->id)
                ->whereNull('parent_id')
                ->orderBy('id')
                ->value('id');

            if ($root !== null) {
                DB::table('workspaces')->where('id', $workspace->id)->update(['root_org_unit_id' => $root]);
            }
        });
    }

    /**
     * Hand every unit back to the workspace whose root it sits under.
     */
    protected function restoreWorkspaceIds(): void
    {
        DB::table('workspaces')->whereNotNull('root_org_unit_id')->orderBy('id')->each(function (object $workspace): void {
            $path = DB::table('org_units')->where('id', $workspace->root_org_unit_id)->value('path');

            if ($path === null) {
                return;
            }

            DB::table('org_units')
                ->where('path', 'like', $path.'%')
                ->update(['workspace_id' => $workspace->id]);
        });
    }
};
