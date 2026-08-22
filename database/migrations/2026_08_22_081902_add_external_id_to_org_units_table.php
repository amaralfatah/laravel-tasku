<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `external_id` holds the SAP object id (`o1id` / `o2id`) of the CDS view
 * `ZA_HRIS_ORGZ`, so a re-import matches units it already created instead of
 * duplicating the whole tree. Units created by hand keep it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->string('external_id', 32)->nullable()->after('workspace_id');

            $table->unique(['workspace_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
