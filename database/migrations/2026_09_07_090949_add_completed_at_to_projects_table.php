<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a project was finished.
 *
 * The status alone says a project is done but not since when, and the sidebar
 * needs the date: work that closed a month ago is history a reader navigates
 * to, not a place they go back to daily. `updated_at` cannot stand in for it —
 * renaming a finished project would make it look freshly closed.
 *
 * `Project::booted()` stamps it on the way into `completed` and clears it on
 * the way back out. Rows finished before this migration are backfilled from
 * `updated_at`, the closest thing to a closing date they carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestampTz('completed_at')->nullable()->after('status');
        });

        DB::table('projects')
            ->where('status', 'completed')
            ->update(['completed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('completed_at');
        });
    }
};
