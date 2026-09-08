<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the operator cut someone's access without deleting the account.
 *
 * Deleting is not an option here: `tasks.assignee_id`, `created_by` and
 * `reviewed_by` are all `nullOnDelete`, so removing the row would blank the
 * record of who did the work rather than end their access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
